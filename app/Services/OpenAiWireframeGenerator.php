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
    public function generate(array $siteAst, array $brief, string $locale): array
    {
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
                    'instructions' => 'You are a semantic wireframe architect. Preserve every Site AST page key exactly once. Each page has 2 to 8 unique sections. Describe information hierarchy only. Do not write copy, HTML, CSS, colors, dimensions, selectors, or asset URLs.',
                    'input' => json_encode([
                        'site_ast' => $siteAst,
                        'brief' => $brief,
                        'locale' => $locale,
                        'validation_feedback' => $feedback,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'reasoning' => ['effort' => (string) config('services.openai.reasoning_effort', 'medium')],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'wireframes',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ]],
                    'max_output_tokens' => 12000,
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
                throw new RuntimeException('The wireframe provider returned invalid JSON.');
            }

            try {
                $wireframe = $this->validator->validate($document, $siteAst);
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
    private function schema(): array
    {
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
