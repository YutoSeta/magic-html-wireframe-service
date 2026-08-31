<?php

namespace App\Services;

use App\Exceptions\InvalidWireframeException;

final class WireframeValidator
{
    private const array COMPOSITIONS = ['hero', 'feature-grid', 'content', 'steps', 'testimonials', 'faq', 'cta', 'contact'];

    private const array ROLES = ['Eyebrow', 'Title', 'Text', 'Items', 'Actions', 'Image'];

    private const array REGION_SEMANTICS = [
        'document', 'header', 'navigation', 'main', 'section', 'article', 'aside', 'footer',
        'group', 'ordered-list', 'unordered-list', 'list-item', 'form', 'field-group',
    ];

    private const array LAYOUTS = [
        'stack', 'cluster', 'grid-2', 'grid-3', 'grid-4', 'split', 'split-wide-start',
        'split-wide-end', 'centered',
    ];

    private const array JOURNEY_STAGES = ['none', 'attention', 'interest', 'desire', 'memory', 'action'];

    private const array EMPHASES = ['neutral', 'supporting', 'standard', 'strong', 'primary'];

    private const array LEAF_TYPES = ['Text', 'Image', 'Link', 'Button', 'Input', 'Textarea', 'Select', 'Checkbox'];

    private const array TEXT_ROLES = [
        'eyebrow', 'heading-1', 'heading-2', 'heading-3', 'body', 'small', 'label', 'price',
        'step-number', 'summary',
    ];

    private const array ACTION_EMPHASES = ['plain', 'secondary', 'primary'];

    private const array IMAGE_ASPECTS = ['16:9', '4:3', '3:2', '1:1', '2:3'];

    /**
     * @param  array<string,mixed>  $document
     * @param  array<string,mixed>  $siteAst
     * @return array<string,mixed>
     */
    public function validate(array $document, array $siteAst, ?int $expectedVersion = null, ?string $expectedLocale = null): array
    {
        $version = $expectedVersion ?? (is_int($document['version'] ?? null) ? $document['version'] : 1);
        $this->assert(in_array($version, [1, 2], true), 'The Wireframe AST version is not supported.');

        if ($version === 1) {
            $this->assert(! isset($document['version']) || $document['version'] === 1, 'Wireframe AST version 1 was requested.');

            return $this->validateV1($document, $siteAst);
        }

        $this->assert(($document['version'] ?? null) === 2, 'Wireframe AST version 2 was requested.');

        return $this->validateV2($document, $siteAst, $expectedLocale);
    }

    /** @param array<string,mixed> $siteAst */
    public function validateGenerationInput(array $siteAst, string $locale, int $wireframeAstVersion): void
    {
        $this->assert(in_array($wireframeAstVersion, [1, 2], true), 'The Wireframe AST version is not supported.');
        if ($wireframeAstVersion === 1) {
            return;
        }

        $this->assert((bool) preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale), 'Locale is invalid.');
        $pages = $siteAst['pages'] ?? null;
        $this->assert(is_array($pages) && array_is_list($pages) && $pages !== [] && count($pages) <= 8, 'Site AST must contain 1 to 8 pages for Wireframe AST v2.');
        $this->indexSitePages($pages);
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $siteAst @return array<string,mixed> */
    private function validateV1(array $document, array $siteAst): array
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

    /** @param array<string,mixed> $document @param array<string,mixed> $siteAst @return array<string,mixed> */
    private function validateV2(array $document, array $siteAst, ?string $expectedLocale): array
    {
        $this->exactKeys($document, ['version', 'locale', 'pages'], 'Wireframe AST v2');
        $locale = $this->text($document['locale'] ?? null, 20, 'Locale');
        $this->assert((bool) preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale), 'Locale is invalid.');
        $this->assert($expectedLocale === null || $locale === $expectedLocale, 'The generated locale must match the requested locale.');
        $pages = $document['pages'] ?? null;
        $sitePages = $siteAst['pages'] ?? null;
        $this->assert(is_array($pages) && array_is_list($pages) && $pages !== [] && count($pages) <= 8, 'Wireframe AST v2 must contain 1 to 8 pages.');
        $this->assert(is_array($sitePages) && array_is_list($sitePages) && $sitePages !== [], 'Site AST must contain pages.');

        $byKey = $this->indexByKey($pages);
        $siteByKey = $this->indexSitePages($sitePages);
        $actualKeys = array_keys($byKey);
        $expectedKeys = array_keys($siteByKey);
        sort($actualKeys);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected);
        $this->assert($actualKeys === $sortedExpected, 'Wireframes must match the Site AST page keys.');

        $normalizedPages = [];
        $knownPaths = array_fill_keys(array_column($siteByKey, 'path'), true);
        $totalNodeCount = 0;
        $pageNodeIdsByPath = [];
        $crossPageAnchors = [];
        foreach ($siteByKey as $pageKey => $sitePage) {
            $page = $byKey[$pageKey];
            $this->exactKeys($page, ['key', 'path', 'title', 'root'], 'Wireframe page');
            $path = $this->path($page['path'] ?? null, 'Wireframe page path');
            $title = $this->text($page['title'] ?? null, 200, 'Wireframe page title');
            $this->assert($path === $sitePage['path'], 'Wireframe page paths must match the Site AST.');
            $this->assert($title === $sitePage['title'], 'Wireframe page titles must match the Site AST.');
            $root = $page['root'] ?? null;
            $this->assert(is_array($root), 'Every wireframe page must contain a root Region.');

            $state = [
                'ids' => [],
                'anchors' => [],
                'known_paths' => $knownPaths,
                'node_count' => 0,
                'heading_1_count' => 0,
                'main_heading_1_count' => 0,
                'section_count' => 0,
                'main_section_count' => 0,
                'cross_page_anchors' => [],
            ];
            $normalizedRoot = $this->node($root, null, 0, false, false, $state);
            $this->assert($normalizedRoot['type'] === 'Region' && $normalizedRoot['semantic'] === 'document', 'The page root must be a document Region.');
            $directSemantics = array_map(
                fn (array $node): ?string => $node['type'] === 'Region' ? $node['semantic'] : null,
                $normalizedRoot['children'],
            );
            $this->assert(! in_array(null, $directSemantics, true), 'A document may contain only header, main and footer Regions.');
            $this->assert(array_diff($directSemantics, ['header', 'main', 'footer']) === [], 'A document may contain only header, main and footer Regions.');
            $this->assert(count(array_keys($directSemantics, 'main', true)) === 1, 'A document must contain exactly one main Region.');
            $this->assert(count(array_keys($directSemantics, 'header', true)) <= 1, 'A document may contain at most one header Region.');
            $this->assert(count(array_keys($directSemantics, 'footer', true)) <= 1, 'A document may contain at most one footer Region.');
            $this->assert($state['heading_1_count'] === 1, 'Every page must contain exactly one heading-1 Text node.');
            $this->assert($state['main_heading_1_count'] === 1, 'The page heading-1 must be inside the main Region.');
            $this->assert($state['section_count'] >= 2 && $state['section_count'] <= 12, 'Every page must contain 2 to 12 section Regions.');
            $this->assert($state['main_section_count'] === $state['section_count'], 'Every section Region must be a direct child of main.');
            $totalNodeCount += $state['node_count'];
            $this->assert($totalNodeCount <= 800, 'Wireframe AST v2 may not contain more than 800 nodes in total.');
            foreach ($state['anchors'] as $anchor) {
                $this->assert(isset($state['ids'][$anchor]), "The local anchor #{$anchor} does not resolve to a node.");
            }
            $pageNodeIdsByPath[$path] = $state['ids'];
            array_push($crossPageAnchors, ...$state['cross_page_anchors']);

            $normalizedPages[] = ['key' => $pageKey, 'path' => $path, 'title' => $title, 'root' => $normalizedRoot];
        }
        foreach ($crossPageAnchors as $reference) {
            $this->assert(
                isset($pageNodeIdsByPath[$reference['path']][$reference['fragment']]),
                "The cross-page anchor {$reference['path']}#{$reference['fragment']} does not resolve to a node.",
            );
        }

        return ['version' => 2, 'locale' => $locale, 'pages' => $normalizedPages];
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    private function node(array $node, ?string $parentSemantic, int $depth, bool $insideForm, bool $insideMain, array &$state): array
    {
        $this->assert($depth <= 8, 'Wireframe nodes may not exceed a depth of 8.');
        $state['node_count']++;
        $this->assert($state['node_count'] <= 300, 'A wireframe page may not contain more than 300 nodes.');
        $type = $this->text($node['type'] ?? null, 30, 'Node type');
        $this->assert($type === 'Region' || in_array($type, self::LEAF_TYPES, true), 'A wireframe contains an unsupported node type.');
        $id = $this->nodeId($node['id'] ?? null);
        $this->assert(! isset($state['ids'][$id]), 'Node IDs must be unique within a page.');
        $state['ids'][$id] = true;

        if ($type === 'Region') {
            $this->exactKeys($node, ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children'], 'Region node');
            $semantic = $this->enum($node['semantic'] ?? null, self::REGION_SEMANTICS, 'Region semantic');
            $layout = $this->enum($node['layout'] ?? null, self::LAYOUTS, 'Region layout');
            $journeyStage = $this->enum($node['journey_stage'] ?? null, self::JOURNEY_STAGES, 'Journey stage');
            $emphasis = $this->enum($node['emphasis'] ?? null, self::EMPHASES, 'Region emphasis');
            $children = $node['children'] ?? null;
            $this->assert(is_array($children) && array_is_list($children) && $children !== [] && count($children) <= 40, 'Every Region must contain 1 to 40 child nodes.');
            $this->assert($depth === 0 || $semantic !== 'document', 'A document Region may only be the page root.');
            if (in_array($semantic, ['header', 'main', 'footer'], true)) {
                $this->assert($parentSemantic === 'document', 'Header, main and footer Regions must be direct children of document.');
            }
            if ($semantic === 'section') {
                $this->assert($parentSemantic === 'main' && $insideMain, 'Section Regions must be direct children of main.');
            }
            $isList = in_array($semantic, ['ordered-list', 'unordered-list'], true);
            $parentIsList = in_array($parentSemantic, ['ordered-list', 'unordered-list'], true);
            $this->assert($semantic !== 'list-item' || $parentIsList, 'A list-item Region must be a direct child of a list Region.');
            $this->assert(! $parentIsList || $semantic === 'list-item', 'A list Region may contain only list-item Regions.');
            $this->assert(! ($semantic === 'form' && $insideForm), 'Form Regions may not be nested.');
            if ($semantic === 'section') {
                $state['section_count']++;
                $state['main_section_count']++;
            }

            $normalizedChildren = [];
            foreach ($children as $child) {
                $this->assert(is_array($child), 'Every Region child must be an object.');
                if ($isList) {
                    $this->assert(($child['type'] ?? null) === 'Region' && ($child['semantic'] ?? null) === 'list-item', 'A list Region may contain only list-item Regions.');
                }
                if ($semantic === 'main') {
                    $this->assert(($child['type'] ?? null) === 'Region' && ($child['semantic'] ?? null) === 'section', 'Main may contain only section Regions.');
                }
                $normalizedChildren[] = $this->node(
                    $child,
                    $semantic,
                    $depth + 1,
                    $insideForm || $semantic === 'form',
                    $insideMain || $semantic === 'main',
                    $state,
                );
            }
            if ($semantic === 'form') {
                $form = ['control_count' => 0, 'submit_count' => 0, 'names' => []];
                $this->collectFormFacts($normalizedChildren, $form);
                $this->assert($form['control_count'] > 0, 'Every form Region must contain at least one form control.');
                $this->assert($form['submit_count'] === 1, 'Every form Region must contain exactly one submit Button.');
            }

            return [
                'type' => 'Region',
                'id' => $id,
                'semantic' => $semantic,
                'layout' => $layout,
                'journey_stage' => $journeyStage,
                'emphasis' => $emphasis,
                'children' => $normalizedChildren,
            ];
        }

        if (in_array($type, ['Input', 'Textarea', 'Select', 'Checkbox'], true)) {
            $this->assert($insideForm, 'Form controls may only appear within a form Region.');
        }

        return match ($type) {
            'Text' => $this->textNode($node, $id, $insideMain, $state),
            'Image' => $this->imageNode($node, $id),
            'Link' => $this->linkNode($node, $id, $state),
            'Button' => $this->buttonNode($node, $id, $insideForm),
            'Input' => $this->inputNode($node, $id),
            'Textarea' => $this->simpleControlNode($node, $id, 'Textarea'),
            'Select' => $this->selectNode($node, $id),
            'Checkbox' => $this->checkboxNode($node, $id),
        };
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $state @return array<string,mixed> */
    private function textNode(array $node, string $id, bool $insideMain, array &$state): array
    {
        $this->exactKeys($node, ['type', 'id', 'role', 'content'], 'Text node');
        $role = $this->enum($node['role'] ?? null, self::TEXT_ROLES, 'Text role');
        $content = $this->content($node['content'] ?? null, 8000, 'Text content');
        if ($role === 'heading-1') {
            $state['heading_1_count']++;
            if ($insideMain) {
                $state['main_heading_1_count']++;
            }
        }

        return ['type' => 'Text', 'id' => $id, 'role' => $role, 'content' => $content];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function imageNode(array $node, string $id): array
    {
        $this->exactKeys($node, ['type', 'id', 'alt', 'caption', 'aspect'], 'Image node');

        return [
            'type' => 'Image',
            'id' => $id,
            'alt' => $this->content($node['alt'] ?? null, 500, 'Image alt text'),
            'caption' => $this->nullableText($node['caption'] ?? null, 1000, 'Image caption'),
            'aspect' => $this->enum($node['aspect'] ?? null, self::IMAGE_ASPECTS, 'Image aspect'),
        ];
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $state @return array<string,mixed> */
    private function linkNode(array $node, string $id, array &$state): array
    {
        $this->exactKeys($node, ['type', 'id', 'label', 'href', 'emphasis'], 'Link node');
        $href = $this->href($node['href'] ?? null, $state);
        if (str_starts_with($href, '#')) {
            $state['anchors'][] = substr($href, 1);
        }

        return [
            'type' => 'Link',
            'id' => $id,
            'label' => $this->content($node['label'] ?? null, 300, 'Link label'),
            'href' => $href,
            'emphasis' => $this->enum($node['emphasis'] ?? null, self::ACTION_EMPHASES, 'Link emphasis'),
        ];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function buttonNode(array $node, string $id, bool $insideForm): array
    {
        $this->exactKeys($node, ['type', 'id', 'label', 'button_type', 'emphasis'], 'Button node');
        $buttonType = $this->enum($node['button_type'] ?? null, ['button', 'submit', 'reset'], 'Button type');
        $this->assert(! in_array($buttonType, ['submit', 'reset'], true) || $insideForm, 'Submit and reset buttons may only appear within a form Region.');

        return [
            'type' => 'Button',
            'id' => $id,
            'label' => $this->content($node['label'] ?? null, 300, 'Button label'),
            'button_type' => $buttonType,
            'emphasis' => $this->enum($node['emphasis'] ?? null, ['secondary', 'primary'], 'Button emphasis'),
        ];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function inputNode(array $node, string $id): array
    {
        $this->exactKeys($node, ['type', 'id', 'input_type', 'label', 'name', 'placeholder', 'required'], 'Input node');

        return [
            'type' => 'Input',
            'id' => $id,
            'input_type' => $this->enum($node['input_type'] ?? null, ['text', 'email', 'tel', 'url'], 'Input type'),
            'label' => $this->content($node['label'] ?? null, 200, 'Input label'),
            'name' => $this->fieldName($node['name'] ?? null),
            'placeholder' => $this->nullableText($node['placeholder'] ?? null, 300, 'Input placeholder'),
            'required' => $this->boolean($node['required'] ?? null, 'Input required'),
        ];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function simpleControlNode(array $node, string $id, string $type): array
    {
        $this->exactKeys($node, ['type', 'id', 'label', 'name', 'placeholder', 'required'], "{$type} node");

        return [
            'type' => $type,
            'id' => $id,
            'label' => $this->content($node['label'] ?? null, 200, "{$type} label"),
            'name' => $this->fieldName($node['name'] ?? null),
            'placeholder' => $this->nullableText($node['placeholder'] ?? null, 300, "{$type} placeholder"),
            'required' => $this->boolean($node['required'] ?? null, "{$type} required"),
        ];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function selectNode(array $node, string $id): array
    {
        $this->exactKeys($node, ['type', 'id', 'label', 'name', 'placeholder', 'required', 'options'], 'Select node');
        $options = $node['options'] ?? null;
        $this->assert(is_array($options) && array_is_list($options) && $options !== [] && count($options) <= 20, 'Select must contain 1 to 20 options.');
        $normalizedOptions = [];
        $values = [];
        foreach ($options as $option) {
            $this->assert(is_array($option), 'Every Select option must be an object.');
            $this->exactKeys($option, ['label', 'value'], 'Select option');
            $value = $this->text($option['value'] ?? null, 100, 'Select option value');
            $this->assert(! isset($values[$value]), 'Select option values must be unique.');
            $values[$value] = true;
            $normalizedOptions[] = [
                'label' => $this->content($option['label'] ?? null, 200, 'Select option label'),
                'value' => $value,
            ];
        }

        return [
            'type' => 'Select',
            'id' => $id,
            'label' => $this->content($node['label'] ?? null, 200, 'Select label'),
            'name' => $this->fieldName($node['name'] ?? null),
            'placeholder' => $this->nullableText($node['placeholder'] ?? null, 300, 'Select placeholder'),
            'required' => $this->boolean($node['required'] ?? null, 'Select required'),
            'options' => $normalizedOptions,
        ];
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function checkboxNode(array $node, string $id): array
    {
        $this->exactKeys($node, ['type', 'id', 'label', 'name', 'value', 'required'], 'Checkbox node');

        return [
            'type' => 'Checkbox',
            'id' => $id,
            'label' => $this->content($node['label'] ?? null, 300, 'Checkbox label'),
            'name' => $this->fieldName($node['name'] ?? null),
            'value' => $this->text($node['value'] ?? null, 100, 'Checkbox value'),
            'required' => $this->boolean($node['required'] ?? null, 'Checkbox required'),
        ];
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

    /** @param array<int,mixed> $items @return array<string,array{path:string,title:string}> */
    private function indexSitePages(array $items): array
    {
        $indexed = [];
        $paths = [];
        foreach ($items as $index => $item) {
            $this->assert(is_array($item), 'Every Site AST page must be an object.');
            $key = $this->pageKey($item);
            $this->assert((bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $key), 'Site AST page keys must use lowercase URL-safe characters.');
            $this->assert(! isset($indexed[$key]), 'Site AST page keys must be unique.');
            $path = $this->path($item['path'] ?? null, 'Site AST page path');
            if ($index === 0) {
                $this->assert($key === 'home' && $path === '/', 'The first Site AST page must be the home page with key home and path /.');
            }
            $this->assert(! isset($paths[$path]), 'Site AST page paths must be unique.');
            $paths[$path] = true;
            $indexed[$key] = [
                'path' => $path,
                'title' => $this->text($item['title'] ?? null, 200, 'Site AST page title'),
            ];
        }

        return $indexed;
    }

    private function pageKey(mixed $page): string
    {
        $this->assert(is_array($page), 'Every Site AST page must be an object.');

        return $this->text($page['key'] ?? null, 100, 'Site AST page key');
    }

    private function nodeId(mixed $value): string
    {
        $id = $this->text($value, 100, 'Node ID');
        $this->assert((bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $id), 'Node IDs must use lowercase kebab-case.');

        return $id;
    }

    private function fieldName(mixed $value): string
    {
        $name = $this->text($value, 100, 'Field name');
        $this->assert((bool) preg_match('/^[a-z][a-z0-9_-]*$/', $name), 'Field names must use a safe lowercase identifier.');

        return $name;
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @param  array{control_count:int,submit_count:int,names:array<string,true>}  $facts
     */
    private function collectFormFacts(array $nodes, array &$facts): void
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'Region') {
                $this->collectFormFacts($node['children'], $facts);

                continue;
            }
            if (in_array($node['type'], ['Input', 'Textarea', 'Select', 'Checkbox'], true)) {
                $facts['control_count']++;
                $name = (string) $node['name'];
                $this->assert(! isset($facts['names'][$name]), 'Form control names must be unique within a form.');
                $facts['names'][$name] = true;
            }
            if ($node['type'] === 'Button' && $node['button_type'] === 'submit') {
                $facts['submit_count']++;
            }
        }
    }

    private function path(mixed $value, string $label): string
    {
        $path = $this->text($value, 200, $label);
        $this->assert((bool) preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)*)?$#', $path), "{$label} must be a canonical lowercase internal path.");

        return $path;
    }

    /** @param array<string,mixed> $state */
    private function href(mixed $value, array &$state): string
    {
        $href = $this->text($value, 500, 'Link href');
        if (str_starts_with($href, '#')) {
            $this->assert((bool) preg_match('/^#[a-z0-9][a-z0-9-]*$/', $href), 'Local link anchors must reference a kebab-case node ID.');

            return $href;
        }

        $this->assert(str_starts_with($href, '/') && ! str_contains($href, '\\'), 'Links must use a local anchor or canonical site-relative path.');
        [$path, $fragment] = array_pad(explode('#', $href, 2), 2, null);
        $path = $this->path($path, 'Link path');
        $this->assert(isset($state['known_paths'][$path]), 'Links may only reference a generated Site AST page path.');
        if ($fragment !== null) {
            $this->assert((bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $fragment), 'Link fragments must use a kebab-case node ID.');
            $state['cross_page_anchors'][] = ['path' => $path, 'fragment' => $fragment];
        }

        return $path.($fragment !== null ? "#{$fragment}" : '');
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $label): string
    {
        $value = $this->text($value, 100, $label);
        $this->assert(in_array($value, $allowed, true), "{$label} is not supported.");

        return $value;
    }

    private function content(mixed $value, int $max, string $label): string
    {
        $value = $this->text($value, $max, $label);
        $this->assert(! in_array(mb_strtolower($value), ['item', 'action', 'title', 'text', 'image', 'section'], true), "{$label} may not be a generic placeholder.");

        return $value;
    }

    private function nullableText(mixed $value, int $max, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->text($value, $max, $label);
    }

    private function boolean(mixed $value, string $label): bool
    {
        $this->assert(is_bool($value), "{$label} must be a boolean.");

        return $value;
    }

    /** @param list<string> $keys */
    private function exactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        $expected = $keys;
        sort($expected);
        $this->assert($actual === $expected, "{$label} contains missing or unsupported fields.");
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
