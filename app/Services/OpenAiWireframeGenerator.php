<?php

namespace App\Services;

use App\Exceptions\InvalidWireframeException;
use App\Services\Contracts\ReportsWireframeTelemetry;
use App\Services\Contracts\WireframeGenerator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiWireframeGenerator implements ReportsWireframeTelemetry, WireframeGenerator
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $telemetry = null;

    public function __construct(private readonly WireframeValidator $validator) {}

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function generate(array $siteAst, array $brief, string $locale, int $wireframeAstVersion = 1): array
    {
        $this->validator->validateGenerationInput($siteAst, $locale, $wireframeAstVersion);
        $this->telemetry = null;
        $providerTelemetry = new OpenAiTelemetry((array) config('services.openai.rate_card', []));
        $feedback = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $providerTelemetry->beginSemanticAttempt();
            $request = Http::withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->timeout((int) config('services.openai.timeout', 300))
                ->connectTimeout((int) config('services.openai.connect_timeout', 10))
                ->beforeSending(function () use ($providerTelemetry): void {
                    $providerTelemetry->recordProviderRequest();
                });
            $retryDelays = $this->retryDelays();
            if ($retryDelays !== []) {
                $request->retry($retryDelays, throw: false);
            }

            $startedAt = hrtime(true);
            try {
                $response = $request->post((string) config('services.openai.url'), [
                    'model' => (string) config('services.openai.model'),
                    'instructions' => $this->instructions($wireframeAstVersion),
                    'input' => json_encode([
                        'site_ast' => $siteAst,
                        'brief' => $brief,
                        'locale' => $locale,
                        'wireframe_ast_version' => $wireframeAstVersion,
                        'validation_feedback' => $feedback,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'reasoning' => ['effort' => (string) config('services.openai.reasoning_effort', 'medium')],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'wireframes',
                        'strict' => true,
                        'schema' => $this->schema($wireframeAstVersion),
                    ]],
                    'max_output_tokens' => $wireframeAstVersion === 2 ? 64000 : 12000,
                    'store' => false,
                    'metadata' => ['stage' => 'wireframes'],
                ]);
            } finally {
                $providerTelemetry->recordProviderDuration(hrtime(true) - $startedAt);
            }

            if (! $response->successful()) {
                throw new RuntimeException('The wireframe provider rejected the request.');
            }
            $providerResponse = $response->json();
            $providerTelemetry->recordResponse(is_array($providerResponse) ? $providerResponse : []);
            $text = $response->json('output_text');
            if (! is_string($text)) {
                $text = $this->outputText((array) $response->json('output', []));
            }
            $document = is_string($text) ? json_decode($text, true) : null;
            if (! is_array($document)) {
                if ($wireframeAstVersion === 1) {
                    throw new RuntimeException('The wireframe provider returned invalid JSON.');
                }
                $status = is_string($providerResponse['status'] ?? null) ? $providerResponse['status'] : 'unknown';
                $feedback = "The provider returned incomplete or invalid JSON (status: {$status}). Produce one complete JSON document within the declared limits.";

                continue;
            }

            try {
                $wireframe = $this->validator->validate($document, $siteAst, $wireframeAstVersion, $locale);
                $this->telemetry = $providerTelemetry->toArray();

                return $wireframe;
            } catch (InvalidWireframeException $exception) {
                $feedback = $exception->getMessage();
            }
        }

        throw new InvalidWireframeException('No valid wireframe was produced. '.($feedback ?? ''));
    }

    /** @return array<string,mixed>|null */
    public function telemetry(): ?array
    {
        return $this->telemetry;
    }

    /** @return array<string,mixed> */
    private function schema(int $wireframeAstVersion): array
    {
        if ($wireframeAstVersion === 2) {
            return $this->v2Schema();
        }

        $text = fn (int $max): array => ['type' => 'string', 'maxLength' => $max];
        $object = fn (array $properties, array $required): array => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ];

        return $object([
            'pages' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $object([
                'key' => $text(100),
                'sections' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 8, 'items' => $object([
                    'key' => $text(100),
                    'composition' => ['type' => 'string', 'enum' => ['hero', 'feature-grid', 'content', 'steps', 'testimonials', 'faq', 'cta', 'contact']],
                    'roles' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => ['Eyebrow', 'Title', 'Text', 'Items', 'Actions', 'Image']]],
                ], ['key', 'composition', 'roles'])],
            ], ['key', 'sections'])],
        ], ['pages']);
    }

    private function instructions(int $wireframeAstVersion): string
    {
        if ($wireframeAstVersion === 1) {
            return 'You are a semantic wireframe architect. Preserve every Site AST page key exactly once. Each page has 2 to 8 unique sections. Describe information hierarchy only. Do not write copy, HTML, CSS, colors, dimensions, selectors, or asset URLs.';
        }

        return <<<'PROMPT'
You are a senior information architect and conversion-focused wireframe director. Produce Wireframe AST version 2 in the requested locale, grounded only in the supplied Site AST, brief, and materials. Preserve every Site AST page key, path, and title exactly once. Layout-only means skin-free, not content-free: write the real customer-facing copy required to judge hierarchy and conversion. You may reorganize content while preserving factual constraints.

Use an AIDMA-aware order on conversion pages: attention, interest, desire, memory, then action. Vary rhythm with split, grid, stack, timeline-like lists, FAQ, and form structures. Put a clear promise, primary action, and trust facts above the fold; place objections and decision information before the final action. Use three deliberate CTA moments when appropriate. Every page needs exactly one heading-1 and 2 to 12 section Regions. The request may contain at most 8 pages, 300 nodes per page, and 800 nodes in total; prefer concise structures that fit comfortably within those limits.

Only Region may branch. Every branch must end in a concrete Text, Image, Link, Button, Input, Textarea, Select, or Checkbox leaf. Use actual labels, targets, image intent/alt text, form fields, options, helper copy, and consent links. Do not emit generic Item, Action, Title, Text, Image, Section, or placeholder-only content. A form must contain labeled controls and a submit Button. Images describe placement and intent only and never contain URLs.

Do not output HTML, CSS, classes, selectors, colors, dimensions, fonts, decoration, remote URLs, scripts, or vendor components. The renderer owns a fixed neutral wireframe decoration. Node IDs are internal lowercase kebab-case identifiers and must never be written into visible copy. Links must use a resolvable local #anchor or a site-relative /path. Keep claims, prices, hours, guarantees, legal statements, and contact constraints faithful to the source materials.
PROMPT;
    }

    /** @return array<string,mixed> */
    private function v2Schema(): array
    {
        $text = fn (int $max, int $min = 1): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $nullableText = fn (int $max): array => ['type' => ['string', 'null'], 'maxLength' => $max];
        $object = fn (array $properties, array $required): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $id = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$', 'maxLength' => 100];
        $fieldName = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$', 'maxLength' => 100];

        $definitions = [];
        $definitions['region'] = $object([
            'type' => ['const' => 'Region'],
            'id' => $id,
            'semantic' => ['type' => 'string', 'enum' => self::regionSemantics()],
            'layout' => ['type' => 'string', 'enum' => self::layouts()],
            'journey_stage' => ['type' => 'string', 'enum' => ['none', 'attention', 'interest', 'desire', 'memory', 'action']],
            'emphasis' => ['type' => 'string', 'enum' => ['neutral', 'supporting', 'standard', 'strong', 'primary']],
            'children' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 40, 'items' => ['$ref' => '#/$defs/node']],
        ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        $definitions['text'] = $object([
            'type' => ['const' => 'Text'],
            'id' => $id,
            'role' => ['type' => 'string', 'enum' => ['eyebrow', 'heading-1', 'heading-2', 'heading-3', 'body', 'small', 'label', 'price', 'step-number', 'summary']],
            'content' => $text(8000),
        ], ['type', 'id', 'role', 'content']);
        $definitions['image'] = $object([
            'type' => ['const' => 'Image'],
            'id' => $id,
            'alt' => $text(500),
            'caption' => $nullableText(1000),
            'aspect' => ['type' => 'string', 'enum' => ['16:9', '4:3', '3:2', '1:1', '2:3']],
        ], ['type', 'id', 'alt', 'caption', 'aspect']);
        $definitions['link'] = $object([
            'type' => ['const' => 'Link'],
            'id' => $id,
            'label' => $text(300),
            'href' => $text(500),
            'emphasis' => ['type' => 'string', 'enum' => ['plain', 'secondary', 'primary']],
        ], ['type', 'id', 'label', 'href', 'emphasis']);
        $definitions['button'] = $object([
            'type' => ['const' => 'Button'],
            'id' => $id,
            'label' => $text(300),
            'button_type' => ['type' => 'string', 'enum' => ['button', 'submit', 'reset']],
            'emphasis' => ['type' => 'string', 'enum' => ['secondary', 'primary']],
        ], ['type', 'id', 'label', 'button_type', 'emphasis']);
        $definitions['input'] = $object([
            'type' => ['const' => 'Input'],
            'id' => $id,
            'input_type' => ['type' => 'string', 'enum' => ['text', 'email', 'tel', 'url']],
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'input_type', 'label', 'name', 'placeholder', 'required']);
        $definitions['textarea'] = $object([
            'type' => ['const' => 'Textarea'],
            'id' => $id,
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'label', 'name', 'placeholder', 'required']);
        $definitions['option'] = $object([
            'label' => $text(200),
            'value' => $text(100),
        ], ['label', 'value']);
        $definitions['select'] = $object([
            'type' => ['const' => 'Select'],
            'id' => $id,
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
            'options' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['$ref' => '#/$defs/option']],
        ], ['type', 'id', 'label', 'name', 'placeholder', 'required', 'options']);
        $definitions['checkbox'] = $object([
            'type' => ['const' => 'Checkbox'],
            'id' => $id,
            'label' => $text(300),
            'name' => $fieldName,
            'value' => $text(100),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'label', 'name', 'value', 'required']);
        $definitions['node'] = ['anyOf' => array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['region', 'text', 'image', 'link', 'button', 'input', 'textarea', 'select', 'checkbox'],
        )];

        return [
            ...$object([
                'version' => ['const' => 2],
                'locale' => $text(20, 2),
                'pages' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ['$ref' => '#/$defs/page']],
            ], ['version', 'locale', 'pages']),
            '$defs' => [
                ...$definitions,
                'page' => $object([
                    'key' => $text(100),
                    'path' => $text(200),
                    'title' => $text(200),
                    'root' => ['$ref' => '#/$defs/region'],
                ], ['key', 'path', 'title', 'root']),
            ],
        ];
    }

    /** @return list<string> */
    private static function regionSemantics(): array
    {
        return [
            'document', 'header', 'navigation', 'main', 'section', 'article', 'aside', 'footer',
            'group', 'ordered-list', 'unordered-list', 'list-item', 'form', 'field-group',
        ];
    }

    /** @return list<string> */
    private static function layouts(): array
    {
        return [
            'stack', 'cluster', 'grid-2', 'grid-3', 'grid-4', 'split', 'split-wide-start',
            'split-wide-end', 'centered',
        ];
    }

    /** @param array<int,mixed> $output */
    private function outputText(array $output): string
    {
        foreach ($output as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return '';
    }

    /** @return list<int> */
    private function retryDelays(): array
    {
        $delays = config('services.openai.retry_delays_ms', []);
        if (! is_array($delays)) {
            return [];
        }

        return array_values(array_filter($delays, fn (mixed $delay): bool => is_int($delay) && $delay >= 0 && $delay <= 60_000));
    }
}
