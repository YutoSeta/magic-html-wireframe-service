<?php

namespace App\Services;

final class OpenAiTelemetry
{
    private const int MAX_TOKEN_COUNT = 10_000_000_000;

    private int $providerRequestCount = 0;

    private int $semanticAttemptCount = 0;

    private int $providerDurationNanoseconds = 0;

    /**
     * @var list<array{
     *     model:string|null,
     *     response_id:string|null,
     *     input_tokens:int|null,
     *     cached_input_tokens:int|null,
     *     output_tokens:int|null,
     *     reasoning_tokens:int|null,
     *     estimated_cost:float|null,
     *     rate_card:array{version:string,effective_at:string,source:string,currency:string,model:string}|null
     * }>
     */
    private array $responses = [];

    /** @param array<string,mixed> $rateCard */
    public function __construct(private readonly array $rateCard) {}

    public function beginSemanticAttempt(): void
    {
        $this->semanticAttemptCount++;
    }

    public function recordProviderRequest(): void
    {
        $this->providerRequestCount++;
    }

    public function recordProviderDuration(int $durationNanoseconds): void
    {
        if ($durationNanoseconds < 0 || $durationNanoseconds > PHP_INT_MAX - $this->providerDurationNanoseconds) {
            return;
        }

        $this->providerDurationNanoseconds += $durationNanoseconds;
    }

    /** @param array<string,mixed> $response */
    public function recordResponse(array $response): void
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $outputDetails = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];
        $inputTokens = $this->nonNegativeInteger($usage['input_tokens'] ?? null);
        $cachedInputTokens = $this->nonNegativeInteger($inputDetails['cached_tokens'] ?? null);
        $outputTokens = $this->nonNegativeInteger($usage['output_tokens'] ?? null);
        $reasoningTokens = $this->nonNegativeInteger($outputDetails['reasoning_tokens'] ?? null);

        if ($inputTokens !== null && $cachedInputTokens !== null) {
            $cachedInputTokens = min($inputTokens, $cachedInputTokens);
        }
        if ($outputTokens !== null && $reasoningTokens !== null) {
            $reasoningTokens = min($outputTokens, $reasoningTokens);
        }

        $model = $this->identifier($response['model'] ?? null);
        $rate = $this->rateFor($model);

        $this->responses[] = [
            'model' => $model,
            'response_id' => $this->identifier($response['id'] ?? null),
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'estimated_cost' => $this->estimateCost($inputTokens, $cachedInputTokens, $outputTokens, $rate),
            'rate_card' => $rate['metadata'] ?? null,
        ];
    }

    /**
     * @return array{
     *     provider:string,
     *     model:string|null,
     *     response_id:string|null,
     *     input_tokens:int|null,
     *     cached_input_tokens:int|null,
     *     output_tokens:int|null,
     *     reasoning_tokens:int|null,
     *     estimated_cost:float|null,
     *     provider_request_count:int,
     *     semantic_attempt_count:int,
     *     retry_count:int,
     *     provider_duration_ms:int,
     *     rate_card:array{version:string,effective_at:string,source:string,currency:string,model:string}|null
     * }
     */
    public function toArray(): array
    {
        $lastResponse = $this->responses[array_key_last($this->responses)] ?? null;
        $rateCard = $this->commonRateCard();

        return [
            'provider' => 'openai',
            'model' => $lastResponse['model'] ?? null,
            'response_id' => $lastResponse['response_id'] ?? null,
            'input_tokens' => $this->sumTokens('input_tokens'),
            'cached_input_tokens' => $this->sumTokens('cached_input_tokens'),
            'output_tokens' => $this->sumTokens('output_tokens'),
            'reasoning_tokens' => $this->sumTokens('reasoning_tokens'),
            'estimated_cost' => $this->sumEstimatedCost($rateCard),
            'provider_request_count' => $this->providerRequestCount,
            'semantic_attempt_count' => $this->semanticAttemptCount,
            'retry_count' => max(0, $this->providerRequestCount - $this->semanticAttemptCount),
            'provider_duration_ms' => intdiv($this->providerDurationNanoseconds, 1_000_000),
            'rate_card' => $rateCard,
        ];
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

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\A[0-9]{1,19}\z/D', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0 || $value > self::MAX_TOKEN_COUNT) {
            return null;
        }

        return $value;
    }

    private function finiteNumber(mixed $value, float $minimum = 0.0): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        if (is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= $minimum ? $number : null;
    }

    /**
     * @return array{
     *     input_per_million:float,
     *     cached_input_per_million:float,
     *     output_per_million:float,
     *     long_context_threshold_tokens:int,
     *     long_context_input_multiplier:float,
     *     long_context_output_multiplier:float,
     *     metadata:array{version:string,effective_at:string,source:string,currency:string,model:string}
     * }|null
     */
    private function rateFor(?string $model): ?array
    {
        $models = $this->rateCard['models'] ?? null;
        if ($model === null || ! is_array($models)) {
            return null;
        }

        foreach ($models as $rateModel => $candidate) {
            if (! is_string($rateModel) || ! is_array($candidate)) {
                continue;
            }
            $aliases = is_array($candidate['aliases'] ?? null) ? $candidate['aliases'] : [];
            $snapshotPrefixes = is_array($candidate['snapshot_prefixes'] ?? null) ? $candidate['snapshot_prefixes'] : [];
            if ($model !== $rateModel
                && ! in_array($model, $aliases, true)
                && ! $this->matchesSnapshot($model, $snapshotPrefixes)) {
                continue;
            }

            $inputRate = $this->finiteNumber($candidate['input_per_million'] ?? null);
            $cachedInputRate = $this->finiteNumber($candidate['cached_input_per_million'] ?? null);
            $outputRate = $this->finiteNumber($candidate['output_per_million'] ?? null);
            $threshold = $this->nonNegativeInteger($candidate['long_context_threshold_tokens'] ?? null);
            $inputMultiplier = $this->finiteNumber($candidate['long_context_input_multiplier'] ?? null, 1.0);
            $outputMultiplier = $this->finiteNumber($candidate['long_context_output_multiplier'] ?? null, 1.0);
            $metadata = $this->rateCardMetadata($rateModel);
            if ($inputRate === null || $cachedInputRate === null || $outputRate === null
                || $threshold === null || $inputMultiplier === null || $outputMultiplier === null
                || $metadata === null) {
                return null;
            }

            return [
                'input_per_million' => $inputRate,
                'cached_input_per_million' => $cachedInputRate,
                'output_per_million' => $outputRate,
                'long_context_threshold_tokens' => $threshold,
                'long_context_input_multiplier' => $inputMultiplier,
                'long_context_output_multiplier' => $outputMultiplier,
                'metadata' => $metadata,
            ];
        }

        return null;
    }

    /** @param array<int,mixed> $prefixes */
    private function matchesSnapshot(string $model, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (! is_string($prefix) || $this->identifier($prefix) === null) {
                continue;
            }
            if (preg_match('/\A'.preg_quote($prefix, '/').'[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $model) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array{version:string,effective_at:string,source:string,currency:string,model:string}|null */
    private function rateCardMetadata(string $model): ?array
    {
        $version = $this->rateCard['version'] ?? null;
        $effectiveAt = $this->rateCard['effective_at'] ?? null;
        $source = $this->rateCard['source'] ?? null;
        $currency = $this->rateCard['currency'] ?? null;
        $sourceParts = is_string($source) ? parse_url($source) : false;

        if (! is_string($version) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $version) !== 1
            || ! is_string($effectiveAt) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $effectiveAt) !== 1
            || ! is_string($source) || strlen($source) > 500
            || ! is_array($sourceParts) || ($sourceParts['scheme'] ?? null) !== 'https'
            || ! in_array($sourceParts['host'] ?? null, ['developers.openai.com', 'platform.openai.com'], true)
            || isset($sourceParts['user']) || isset($sourceParts['pass']) || isset($sourceParts['port'])
            || isset($sourceParts['query']) || isset($sourceParts['fragment'])
            || ! is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || $this->identifier($model) === null) {
            return null;
        }

        return [
            'version' => $version,
            'effective_at' => $effectiveAt,
            'source' => $source,
            'currency' => $currency,
            'model' => $model,
        ];
    }

    /**
     * @param array{
     *     input_per_million:float,
     *     cached_input_per_million:float,
     *     output_per_million:float,
     *     long_context_threshold_tokens:int,
     *     long_context_input_multiplier:float,
     *     long_context_output_multiplier:float,
     *     metadata:array{version:string,effective_at:string,source:string,currency:string,model:string}
     * }|null $rate
     */
    private function estimateCost(?int $inputTokens, ?int $cachedInputTokens, ?int $outputTokens, ?array $rate): ?float
    {
        if ($inputTokens === null || $cachedInputTokens === null || $outputTokens === null || $rate === null) {
            return null;
        }

        $longContext = $inputTokens > $rate['long_context_threshold_tokens'];
        $inputMultiplier = $longContext ? $rate['long_context_input_multiplier'] : 1.0;
        $outputMultiplier = $longContext ? $rate['long_context_output_multiplier'] : 1.0;
        $uncachedInputTokens = $inputTokens - $cachedInputTokens;
        $cost = (
            (($uncachedInputTokens * $rate['input_per_million']) + ($cachedInputTokens * $rate['cached_input_per_million'])) * $inputMultiplier
            + ($outputTokens * $rate['output_per_million'] * $outputMultiplier)
        ) / 1_000_000;

        if (! is_finite($cost) || $cost < 0 || $cost > 1_000_000) {
            return null;
        }

        return round($cost, 12);
    }

    private function sumTokens(string $key): ?int
    {
        if ($this->responses === []) {
            return null;
        }

        $total = 0;
        foreach ($this->responses as $response) {
            $value = $response[$key] ?? null;
            if (! is_int($value) || $value > PHP_INT_MAX - $total) {
                return null;
            }
            $total += $value;
        }

        return $total;
    }

    /** @param array{version:string,effective_at:string,source:string,currency:string,model:string}|null $rateCard */
    private function sumEstimatedCost(?array $rateCard): ?float
    {
        if ($rateCard === null || $this->responses === []) {
            return null;
        }

        $total = 0.0;
        foreach ($this->responses as $response) {
            $value = $response['estimated_cost'];
            if (! is_float($value)) {
                return null;
            }
            $total += $value;
        }

        return is_finite($total) ? round($total, 12) : null;
    }

    /** @return array{version:string,effective_at:string,source:string,currency:string,model:string}|null */
    private function commonRateCard(): ?array
    {
        if ($this->responses === []) {
            return null;
        }

        $rateCard = $this->responses[0]['rate_card'];
        if (! is_array($rateCard)) {
            return null;
        }

        foreach ($this->responses as $response) {
            if ($response['rate_card'] !== $rateCard) {
                return null;
            }
        }

        return $rateCard;
    }
}
