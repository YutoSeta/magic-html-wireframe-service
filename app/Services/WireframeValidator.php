<?php

namespace App\Services;

use App\Exceptions\InvalidWireframeException;

final class WireframeValidator
{
    private const array COMPOSITIONS = ['hero', 'feature-grid', 'content', 'steps', 'testimonials', 'faq', 'cta', 'contact'];

    private const array ROLES = ['Eyebrow', 'Title', 'Text', 'Items', 'Actions', 'Image'];

    /** @param array<string,mixed> $document @param array<string,mixed> $siteAst @return array<string,mixed> */
    public function validate(array $document, array $siteAst): array
    {
        $pages = $document['pages'] ?? null;
        $sitePages = $siteAst['pages'] ?? null;
        $this->assert(is_array($pages) && array_is_list($pages), 'Wireframes must contain a page list.');
        $this->assert(is_array($sitePages) && array_is_list($sitePages) && $sitePages !== [], 'Site AST must contain pages.');
        $byKey = $this->indexByKey($pages);
        $expectedKeys = array_map(fn (mixed $page): string => $this->pageKey($page), $sitePages);
        $actualKeys = array_keys($byKey);
        sort($actualKeys);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected);
        $this->assert($actualKeys === $sortedExpected, 'Wireframes must match the Site AST page keys.');

        $normalized = [];
        foreach ($expectedKeys as $pageKey) {
            $sections = $byKey[$pageKey]['sections'] ?? null;
            $this->assert(is_array($sections) && array_is_list($sections) && count($sections) >= 2 && count($sections) <= 8, 'Every page must contain 2 to 8 sections.');
            $sectionKeys = [];
            $normalizedSections = [];
            foreach ($sections as $section) {
                $this->assert(is_array($section), 'Every section must be an object.');
                $key = $this->text($section['key'] ?? null, 100, 'Section key');
                $composition = $this->text($section['composition'] ?? null, 100, 'Section composition');
                $roles = $section['roles'] ?? null;
                $this->assert((bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $key), 'Section keys must use lowercase URL-safe characters.');
                $this->assert(! isset($sectionKeys[$key]), 'Section keys must be unique within a page.');
                $this->assert(in_array($composition, self::COMPOSITIONS, true), 'Section composition is not supported.');
                $this->assert(is_array($roles) && array_is_list($roles) && $roles !== [], 'Every section must contain semantic roles.');
                $roles = array_values(array_unique(array_map(fn (mixed $role): string => $this->text($role, 30, 'Role'), $roles)));
                $this->assert(array_diff($roles, self::ROLES) === [], 'A wireframe contains an unsupported semantic role.');
                $sectionKeys[$key] = true;
                $normalizedSections[] = ['key' => $key, 'composition' => $composition, 'roles' => $roles];
            }
            $normalized[] = ['key' => $pageKey, 'sections' => $normalizedSections];
        }

        return ['version' => 1, 'pages' => $normalized];
    }

    /** @param array<int,mixed> $items @return array<string,array<string,mixed>> */
    private function indexByKey(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $this->assert(is_array($item), 'Every wireframe page must be an object.');
            $key = $this->text($item['key'] ?? null, 100, 'Wireframe page key');
            $this->assert(! isset($indexed[$key]), 'Wireframe page keys must be unique.');
            $indexed[$key] = $item;
        }

        return $indexed;
    }

    private function pageKey(mixed $page): string
    {
        $this->assert(is_array($page), 'Every Site AST page must be an object.');

        return $this->text($page['key'] ?? null, 100, 'Site AST page key');
    }

    private function text(mixed $value, int $max, string $label): string
    {
        $this->assert(is_string($value), "{$label} must be text.");
        $value = trim($value);
        $this->assert($value !== '' && mb_strlen($value) <= $max, "{$label} is empty or too long.");

        return $value;
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidWireframeException($message);
        }
    }
}
