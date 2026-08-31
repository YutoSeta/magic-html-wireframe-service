<?php

namespace App\Services\Contracts;

interface WireframeGenerator
{
    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function generate(array $siteAst, array $brief, string $locale, int $wireframeAstVersion = 1): array;
}
