<?php

namespace App\Services;

use App\Exceptions\InvalidWireframeException;
use App\Services\Contracts\ReportsWireframeTelemetry;
use App\Services\Contracts\WireframeGenerator;
use App\Support\ExecutionProfile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OpenAiWireframeGenerator implements ReportsWireframeTelemetry, WireframeGenerator
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $telemetry = null;

    public function __construct(
        private readonly WireframeValidator $validator,
        private readonly ExecutionProfile $executionProfiles,
    ) {}

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function generate(array $siteAst, array $brief, string $locale, int $wireframeAstVersion = 1, string $executionProfile = 'fast'): array
    {
        $profile = $this->executionProfiles->resolve($executionProfile);
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
                    'model' => $profile['model'],
                    'instructions' => $this->instructions($wireframeAstVersion),
                    'input' => json_encode([
                        'site_ast' => $siteAst,
                        'brief' => $brief,
                        'locale' => $locale,
                        'wireframe_ast_version' => $wireframeAstVersion,
                        'validation_feedback' => $feedback,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'reasoning' => ['effort' => $profile['reasoning_effort']],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'wireframes',
                        'strict' => true,
                        'schema' => $this->schema($wireframeAstVersion),
                    ]],
                    'max_output_tokens' => $wireframeAstVersion === 2 ? 64000 : 12000,
                    'store' => false,
                    'metadata' => ['stage' => 'wireframes', 'execution_profile' => $profile['id']],
                ]);
            } finally {
                $providerTelemetry->recordProviderDuration(hrtime(true) - $startedAt);
            }

            if (! $response->successful()) {
                Log::warning('Wireframe provider rejected request.', [
                    'provider' => 'openai',
                    'model' => $this->identifier($profile['model']),
                    'status' => $response->status(),
                    'request_id' => $this->identifier($response->header('x-request-id')),
                    'error_type' => $this->identifier($response->json('error.type')),
                    'error_code' => $this->identifier($response->json('error.code')),
                    'error_param' => $this->identifier($response->json('error.param')),
                ]);

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
            $document = $this->normalizeProviderDocument($document, $wireframeAstVersion);

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

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function startBackground(
        array $siteAst,
        array $brief,
        string $locale,
        int $wireframeAstVersion = 1,
        ?string $validationFeedback = null,
        string $executionProfile = 'fast',
    ): array {
        $profile = $this->executionProfiles->resolve($executionProfile);
        $this->validator->validateGenerationInput($siteAst, $locale, $wireframeAstVersion);
        $response = $this->providerRequest()->post(
            (string) config('services.openai.url'),
            $this->providerPayload($siteAst, $brief, $locale, $wireframeAstVersion, $validationFeedback, true, $profile),
        );
        $this->assertSuccessfulProviderResponse($response);
        $providerResponse = $response->json();
        if (! is_array($providerResponse)
            || $this->identifier($providerResponse['id'] ?? null) === null
            || ! in_array($providerResponse['status'] ?? null, ['queued', 'in_progress', 'completed'], true)) {
            throw new RuntimeException('The wireframe provider returned an invalid background job.');
        }

        return $providerResponse;
    }

    /** @return array<string,mixed> */
    public function retrieveBackground(string $responseId): array
    {
        if ($this->identifier($responseId) === null || ! str_starts_with($responseId, 'resp_')) {
            throw new RuntimeException('The wireframe provider response identifier is invalid.');
        }
        $response = $this->providerRequest()->get(rtrim((string) config('services.openai.url'), '/').'/'.rawurlencode($responseId));
        $this->assertSuccessfulProviderResponse($response);
        $providerResponse = $response->json();
        if (! is_array($providerResponse) || ! is_string($providerResponse['status'] ?? null)) {
            throw new RuntimeException('The wireframe provider returned an invalid background status.');
        }

        return $providerResponse;
    }

    /** @param array<string,mixed> $providerResponse @param array<string,mixed> $siteAst @return array{wireframe:array<string,mixed>,telemetry:array<string,mixed>} */
    public function completeBackground(array $providerResponse, array $siteAst, string $locale, int $wireframeAstVersion): array
    {
        if (($providerResponse['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The wireframe provider response is not complete.');
        }
        $text = is_string($providerResponse['output_text'] ?? null)
            ? $providerResponse['output_text']
            : $this->outputText((array) ($providerResponse['output'] ?? []));
        $document = json_decode($text, true);
        if (! is_array($document)) {
            throw new RuntimeException('The wireframe provider returned invalid JSON.');
        }
        $document = $this->normalizeProviderDocument($document, $wireframeAstVersion);
        $wireframe = $this->validator->validate($document, $siteAst, $wireframeAstVersion, $locale);

        return ['wireframe' => $wireframe, 'telemetry' => $this->backgroundTelemetry($providerResponse)];
    }

    /** @param array<string,mixed> $providerResponse @return array<string,mixed> */
    public function backgroundTelemetry(array $providerResponse): array
    {
        $providerTelemetry = new OpenAiTelemetry((array) config('services.openai.rate_card', []));
        $providerTelemetry->beginSemanticAttempt();
        $providerTelemetry->recordProviderRequest();
        $providerTelemetry->recordResponse($providerResponse);

        return $providerTelemetry->toArray();
    }

    /** @param array<string,mixed> $providerResponse */
    public function outputLimitRetryFeedback(array $providerResponse): ?string
    {
        if (($providerResponse['status'] ?? null) !== 'incomplete'
            || ($providerResponse['incomplete_details']['reason'] ?? null) !== 'max_output_tokens') {
            return null;
        }

        return 'The previous response exhausted the output-token limit. Return one complete, concise document rather than continuing the partial response. Use at most 80 nodes per page, 240 nodes total, four nested Region levels, eight sections per page, and 240 characters per body Text node. Remove repetition before omitting required page, form, image, link, or CTA semantics.';
    }

    private function providerRequest(): PendingRequest
    {
        $request = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout', 300))
            ->connectTimeout((int) config('services.openai.connect_timeout', 10));
        $retryDelays = $this->retryDelays();

        return $retryDelays === [] ? $request : $request->retry($retryDelays, throw: false);
    }

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    private function providerPayload(
        array $siteAst,
        array $brief,
        string $locale,
        int $wireframeAstVersion,
        ?string $feedback,
        bool $background,
        array $profile,
    ): array {
        return [
            'model' => $profile['model'],
            'instructions' => $this->instructions($wireframeAstVersion),
            'input' => json_encode([
                'site_ast' => $siteAst,
                'brief' => $brief,
                'locale' => $locale,
                'wireframe_ast_version' => $wireframeAstVersion,
                'validation_feedback' => $feedback,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reasoning' => ['effort' => $profile['reasoning_effort']],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'wireframes',
                'strict' => true,
                'schema' => $this->schema($wireframeAstVersion),
            ]],
            'max_output_tokens' => $wireframeAstVersion === 2 ? 64000 : 12000,
            'background' => $background,
            'store' => false,
            'metadata' => ['stage' => 'wireframes', 'execution_profile' => $profile['id']],
        ];
    }

    private function assertSuccessfulProviderResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        Log::warning('Wireframe provider rejected request.', [
            'provider' => 'openai',
            'model' => $this->identifier(config('services.openai.model')),
            'status' => $response->status(),
            'request_id' => $this->identifier($response->header('x-request-id')),
            'error_type' => $this->identifier($response->json('error.type')),
            'error_code' => $this->identifier($response->json('error.code')),
            'error_param' => $this->identifier($response->json('error.param')),
        ]);

        throw new RuntimeException('The wireframe provider rejected the request.');
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)
            || mb_strlen($value) > 200
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:@+\/-]*\z/D', $value) !== 1) {
            return null;
        }

        return $value;
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

Use an AIDMA-aware order on conversion pages: attention, interest, desire, memory, then action. Vary rhythm with split, grid, stack, timeline-like lists, FAQ, and form structures. Put a clear promise, primary action, and trust facts above the fold; place objections and decision information before the final action. Use three deliberate CTA moments when appropriate. Every page needs exactly one heading-1 and 2 to 12 section Regions. The request may contain at most 8 pages. Keep the generated document concise: target at most 120 nodes per page and 400 nodes total, never nest Region nodes more than five levels deep, and keep each body Text node under 400 characters. Remove repetitive copy and duplicate groups before adding more nodes.

Only Region may branch. Every branch must end in a concrete Text, Image, Link, Button, Input, Textarea, Select, or Checkbox leaf. Use actual labels, targets, image intent/alt text, form fields, options, helper copy, and consent links. Node IDs must agree with their declared type: any ID containing the token image is an Image node, never descriptive Text. Do not emit generic Item, Action, Title, Text, Image, Section, or placeholder-only content. Use semantic form only for an actual interactive form. Its schema deliberately separates introductory content, one-or-more labeled controls, and exactly one submit Button; populate all three faithfully. A visual inquiry section without controls is a group, never a form. Use ordered-list or unordered-list only when every direct child is a list-item Region. Images describe placement and intent only and never contain URLs.

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

        $regionProperties = fn (array $semantics): array => [
            'type' => ['type' => 'string', 'const' => 'Region'],
            'id' => $id,
            'semantic' => ['type' => 'string', 'enum' => $semantics],
            'layout' => ['type' => 'string', 'enum' => self::layouts()],
            'journey_stage' => ['type' => 'string', 'enum' => ['none', 'attention', 'interest', 'desire', 'memory', 'action']],
            'emphasis' => ['type' => 'string', 'enum' => ['neutral', 'supporting', 'standard', 'strong', 'primary']],
        ];

        $definitions = [];
        $definitions['region'] = $object([...$regionProperties(
            ['document', 'header', 'navigation', 'main', 'section', 'article', 'aside', 'footer', 'group'],
        ), 'children' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 40, 'items' => ['$ref' => '#/$defs/node']]], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        $definitions['listRegion'] = $object([...$regionProperties(
            ['ordered-list', 'unordered-list'],
        ), 'children' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['$ref' => '#/$defs/listItemRegion']]], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        $definitions['listItemRegion'] = $object([...$regionProperties(
            ['list-item'],
        ), 'children' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => ['$ref' => '#/$defs/node']]], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        $definitions['formRegion'] = $object([...$regionProperties(
            ['form'],
        ),
            'content' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ['$ref' => '#/$defs/formContentNode']],
            'controls' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => ['$ref' => '#/$defs/formControlNode']],
            'submit' => ['$ref' => '#/$defs/submitButton'],
        ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'content', 'controls', 'submit']);
        $definitions['text'] = $object([
            'type' => ['type' => 'string', 'const' => 'Text'],
            'id' => $id,
            'role' => ['type' => 'string', 'enum' => ['eyebrow', 'heading-1', 'heading-2', 'heading-3', 'body', 'small', 'label', 'price', 'step-number', 'summary']],
            'content' => $text(8000),
        ], ['type', 'id', 'role', 'content']);
        $definitions['image'] = $object([
            'type' => ['type' => 'string', 'const' => 'Image'],
            'id' => $id,
            'alt' => $text(500),
            'caption' => $nullableText(1000),
            'aspect' => ['type' => 'string', 'enum' => ['16:9', '4:3', '3:2', '1:1', '2:3']],
        ], ['type', 'id', 'alt', 'caption', 'aspect']);
        $definitions['link'] = $object([
            'type' => ['type' => 'string', 'const' => 'Link'],
            'id' => $id,
            'label' => $text(300),
            'href' => $text(500),
            'emphasis' => ['type' => 'string', 'enum' => ['plain', 'secondary', 'primary']],
        ], ['type', 'id', 'label', 'href', 'emphasis']);
        $definitions['button'] = $object([
            'type' => ['type' => 'string', 'const' => 'Button'],
            'id' => $id,
            'label' => $text(300),
            'button_type' => ['type' => 'string', 'const' => 'button'],
            'emphasis' => ['type' => 'string', 'enum' => ['secondary', 'primary']],
        ], ['type', 'id', 'label', 'button_type', 'emphasis']);
        $definitions['submitButton'] = $object([
            'type' => ['type' => 'string', 'const' => 'Button'],
            'id' => $id,
            'label' => $text(300),
            'button_type' => ['type' => 'string', 'const' => 'submit'],
            'emphasis' => ['type' => 'string', 'enum' => ['secondary', 'primary']],
        ], ['type', 'id', 'label', 'button_type', 'emphasis']);
        $definitions['input'] = $object([
            'type' => ['type' => 'string', 'const' => 'Input'],
            'id' => $id,
            'input_type' => ['type' => 'string', 'enum' => ['text', 'email', 'tel', 'url']],
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'input_type', 'label', 'name', 'placeholder', 'required']);
        $definitions['textarea'] = $object([
            'type' => ['type' => 'string', 'const' => 'Textarea'],
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
            'type' => ['type' => 'string', 'const' => 'Select'],
            'id' => $id,
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
            'options' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['$ref' => '#/$defs/option']],
        ], ['type', 'id', 'label', 'name', 'placeholder', 'required', 'options']);
        $definitions['checkbox'] = $object([
            'type' => ['type' => 'string', 'const' => 'Checkbox'],
            'id' => $id,
            'label' => $text(300),
            'name' => $fieldName,
            'value' => $text(100),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'label', 'name', 'value', 'required']);
        $definitions['node'] = ['anyOf' => array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['region', 'listRegion', 'formRegion', 'text', 'image', 'link', 'button'],
        )];
        $definitions['formContentNode'] = ['anyOf' => array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['text', 'image', 'link', 'button'],
        )];
        $definitions['formControlNode'] = ['anyOf' => array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['input', 'textarea', 'select', 'checkbox'],
        )];

        return [
            ...$object([
                'version' => ['type' => 'integer', 'const' => 2],
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

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function normalizeProviderDocument(array $document, int $wireframeAstVersion): array
    {
        if ($wireframeAstVersion !== 2 || ! is_array($document['pages'] ?? null)) {
            return $document;
        }
        foreach ($document['pages'] as $pageIndex => $page) {
            if (is_array($page) && is_array($page['root'] ?? null)) {
                $document['pages'][$pageIndex]['root'] = $this->normalizeProviderNode($page['root']);
            }
        }

        return $document;
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function normalizeProviderNode(array $node): array
    {
        if (($node['type'] ?? null) !== 'Region') {
            return $node;
        }
        if (($node['semantic'] ?? null) === 'form'
            && is_array($node['content'] ?? null)
            && is_array($node['controls'] ?? null)
            && is_array($node['submit'] ?? null)) {
            $node['children'] = [...$node['content'], ...$node['controls'], $node['submit']];
            unset($node['content'], $node['controls'], $node['submit']);
        }
        if (is_array($node['children'] ?? null)) {
            foreach ($node['children'] as $childIndex => $child) {
                if (is_array($child)) {
                    $node['children'][$childIndex] = $this->normalizeProviderNode($child);
                }
            }
        }

        return $node;
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
