<?php

namespace App\Support;

use InvalidArgumentException;

final class ExecutionProfile
{
    /** @return array{id:string,model:string,reasoning_effort:string} */
    public function resolve(?string $profile = null): array
    {
        $id = $profile ?: (string) config('services.openai.default_execution_profile', 'fast');
        $definition = config("services.openai.execution_profiles.{$id}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown execution profile [{$id}].");
        }

        return [
            'id' => $id,
            'model' => (string) ($definition['model'] ?? ''),
            'reasoning_effort' => (string) ($definition['reasoning_effort'] ?? ''),
        ];
    }
}
