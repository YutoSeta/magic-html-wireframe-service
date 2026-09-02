<?php

namespace App\Layout;

use Illuminate\Support\Str;

final class SemanticLayoutHtmlRenderer
{
    /**
     * @param  list<array<string,mixed>>  $pages
     * @return array<string,array{page_key:string,path:string,title:string,html:string}>
     */
    public function render(array $pages, string $locale, array $layoutAstDigests): array
    {
        $usedPaths = [];
        $filePaths = [];
        $routeToFile = [];
        foreach ($pages as $page) {
            $pageKey = (string) $page['key'];
            $filePaths[$pageKey] = $this->filePath($pageKey, $usedPaths);
            $routeToFile[(string) $page['path']] = $filePaths[$pageKey];
        }

        $rendered = [];
        foreach ($pages as $page) {
            $pageKey = (string) $page['key'];
            $path = $filePaths[$pageKey];
            $rendered[$pageKey] = [
                'page_key' => $pageKey,
                'path' => $path,
                'title' => (string) $page['title'],
                'html' => $this->document(
                    $page,
                    $locale,
                    $routeToFile,
                    (string) ($layoutAstDigests[$pageKey] ?? ''),
                ),
            ];
        }

        return $rendered;
    }

    /** @param array<string,true> $usedPaths */
    private function filePath(string $pageKey, array &$usedPaths): string
    {
        $slug = Str::substr(Str::slug($pageKey), 0, 80);
        $slug = $slug !== '' ? $slug : 'page';
        $path = "page-{$slug}.html";
        $comparisonPath = Str::lower($path);
        $collision = 0;
        while (isset($usedPaths[$comparisonPath])) {
            $collision++;
            $suffix = Str::substr(hash('sha256', $pageKey), 0, 12).($collision > 1 ? "-{$collision}" : '');
            $path = "page-{$slug}-{$suffix}.html";
            $comparisonPath = Str::lower($path);
        }
        $usedPaths[$comparisonPath] = true;

        return $path;
    }

    /** @param array<string,mixed> $page @param array<string,string> $routeToFile */
    private function document(array $page, string $locale, array $routeToFile, string $layoutAstDigest): string
    {
        $lang = $this->escape($locale);
        $title = $this->escape((string) $page['title']);
        $body = $this->node((array) $page['root'], $routeToFile);

        return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'none'; img-src 'none'; font-src 'none'; connect-src 'none'; script-src 'none'; base-uri 'none'; form-action 'none'">
<meta name="layout-ast-sha256" content="{$layoutAstDigest}">
<meta name="layout-source-profile" content="layout-snapshot-v1">
<title>{$title}</title>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    /** @param array<string,mixed> $node @param array<string,string> $routeToFile */
    private function node(array $node, array $routeToFile): string
    {
        return match ((string) $node['type']) {
            'Region' => $this->region($node, $routeToFile),
            'Text' => $this->textLeaf($node),
            'Image' => $this->imageLeaf($node),
            'Link' => $this->linkLeaf($node, $routeToFile),
            'Button' => $this->buttonLeaf($node),
            'Input' => $this->inputLeaf($node),
            'Textarea' => $this->textareaLeaf($node),
            'Select' => $this->selectLeaf($node),
            'Checkbox' => $this->checkboxLeaf($node),
        };
    }

    /** @param array<string,mixed> $node @param array<string,string> $routeToFile */
    private function region(array $node, array $routeToFile): string
    {
        $semantic = (string) $node['semantic'];
        $tag = match ($semantic) {
            'header' => 'header',
            'navigation' => 'nav',
            'main' => 'main',
            'section' => 'section',
            'article' => 'article',
            'aside' => 'aside',
            'footer' => 'footer',
            'ordered-list' => 'ol',
            'unordered-list' => 'ul',
            'list-item' => 'li',
            'form' => 'form',
            default => 'div',
        };
        $id = $this->escape((string) $node['id']);
        $layout = $this->escape((string) $node['layout']);
        $stage = $this->escape((string) $node['journey_stage']);
        $emphasis = $this->escape((string) $node['emphasis']);
        $magicHtmlRole = $this->regionMagicHtmlRole($semantic);
        $qualifier = $this->regionMagicHtmlQualifier($semantic);
        $attributes = " data-mh-role=\"{$magicHtmlRole}\"";
        if ($qualifier !== '') {
            $attributes .= " data-mh-qualifier=\"{$qualifier}\"";
        }
        if ($semantic === 'section') {
            $attributes .= " data-mh-composition=\"{$id}\"";
        }
        $attributes .= $this->designAttributes($magicHtmlRole, $id, $this->isDesignContainer($magicHtmlRole), $qualifier);
        $children = implode("\n", array_map(fn (array $child): string => $this->node($child, $routeToFile), $node['children']));
        $formAttribute = $semantic === 'form' ? " m-form=\"{$id}\"" : '';

        return "<{$tag} id=\"{$id}\" class=\"layout-flow-{$layout}\" data-layout-node data-layout-kind=\"region\" data-layout-semantic=\"{$semantic}\" data-layout-stage=\"{$stage}\" data-layout-emphasis=\"{$emphasis}\"{$attributes}{$formAttribute}>\n{$children}\n</{$tag}>";
    }

    /** @param array<string,mixed> $node */
    private function textLeaf(array $node): string
    {
        $tag = match ($node['role']) {
            'heading-1' => 'h1',
            'heading-2' => 'h2',
            'heading-3' => 'h3',
            'label', 'price', 'step-number', 'summary' => 'span',
            default => 'p',
        };
        $id = $this->escape((string) $node['id']);
        $role = $this->escape((string) $node['role']);
        $content = $this->escape((string) $node['content']);
        $magicHtmlRole = $this->textMagicHtmlRole((string) $node['role']);

        return "<{$tag} id=\"{$id}\" class=\"layout-text-{$role}\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Text\" data-mh-role=\"{$magicHtmlRole}\"{$this->designAttributes($magicHtmlRole, $id)}>{$content}</{$tag}>";
    }

    /** @param array<string,mixed> $node */
    private function imageLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $alt = $this->escape((string) $node['alt']);
        $aspect = $this->escape((string) $node['aspect']);
        $caption = $node['caption'] !== null
            ? '<figcaption>'.$this->escape((string) $node['caption']).'</figcaption>'
            : '';

        return "<figure id=\"{$id}\" class=\"layout-image\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Image\" data-layout-aspect=\"{$aspect}\" data-mh-role=\"Image\"{$this->designAttributes('Image', $id)} role=\"img\" aria-label=\"{$alt}\"><span>{$alt}</span>{$caption}</figure>";
    }

    /** @param array<string,mixed> $node @param array<string,string> $routeToFile */
    private function linkLeaf(array $node, array $routeToFile): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $href = (string) $node['href'];
        if (str_starts_with($href, '/')) {
            [$path, $fragment] = array_pad(explode('#', $href, 2), 2, null);
            if (isset($routeToFile[$path])) {
                $href = $routeToFile[$path].($fragment !== null ? "#{$fragment}" : '');
            }
        }
        $href = $this->escape($href);
        $emphasis = $this->escape((string) $node['emphasis']);

        return "<a id=\"{$id}\" class=\"layout-link\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Link\" data-layout-emphasis=\"{$emphasis}\" data-mh-role=\"Link\"{$this->designAttributes('Link', $id)} href=\"{$href}\">{$label}</a>";
    }

    /** @param array<string,mixed> $node */
    private function buttonLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $type = $this->escape((string) $node['button_type']);
        $emphasis = $this->escape((string) $node['emphasis']);

        return "<button id=\"{$id}\" class=\"layout-button\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Button\" data-layout-emphasis=\"{$emphasis}\" data-mh-role=\"Btn\"{$this->designAttributes('Btn', $id)} type=\"{$type}\">{$label}</button>";
    }

    /** @param array<string,mixed> $node */
    private function inputLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $type = $this->escape((string) $node['input_type']);
        $required = $node['required'] ? ' required' : '';
        $controlId = $id.'-control';

        return "<div id=\"{$id}\" class=\"layout-field\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Input\" data-mh-role=\"Group\" data-mh-qualifier=\"Field\"><label for=\"{$controlId}\" data-mh-role=\"Label\">{$label}</label><input type=\"{$type}\" name=\"{$name}\" m-field=\"{$name}\" id=\"{$controlId}\" data-mh-role=\"Input\" data-mh-qualifier=\"Text\"{$this->placeholder($node['placeholder'])}{$required}></div>";
    }

    /** @param array<string,mixed> $node */
    private function textareaLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $required = $node['required'] ? ' required' : '';
        $controlId = $id.'-control';

        return "<div id=\"{$id}\" class=\"layout-field\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Textarea\" data-mh-role=\"Group\" data-mh-qualifier=\"Field\"><label for=\"{$controlId}\" data-mh-role=\"Label\">{$label}</label><textarea name=\"{$name}\" m-field=\"{$name}\" id=\"{$controlId}\" data-mh-role=\"Input\" data-mh-qualifier=\"Textarea\"{$this->placeholder($node['placeholder'])}{$required}></textarea></div>";
    }

    /** @param array<string,mixed> $node */
    private function selectLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $required = $node['required'] ? ' required' : '';
        $controlId = $id.'-control';
        $placeholder = $node['placeholder'] !== null
            ? '<option value="" data-mh-role="Option">'.$this->escape((string) $node['placeholder']).'</option>'
            : '';
        $options = implode('', array_map(
            fn (array $option): string => '<option value="'.$this->escape((string) $option['value']).'" data-mh-role="Option">'.$this->escape((string) $option['label']).'</option>',
            $node['options'],
        ));

        return "<div id=\"{$id}\" class=\"layout-field\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Select\" data-mh-role=\"Group\" data-mh-qualifier=\"Field\"><label for=\"{$controlId}\" data-mh-role=\"Label\">{$label}</label><select name=\"{$name}\" m-field=\"{$name}\" id=\"{$controlId}\" data-mh-role=\"Input\" data-mh-qualifier=\"Select\"{$required}>{$placeholder}{$options}</select></div>";
    }

    /** @param array<string,mixed> $node */
    private function checkboxLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $value = $this->escape((string) $node['value']);
        $required = $node['required'] ? ' required' : '';
        $controlId = $id.'-control';

        return "<div id=\"{$id}\" class=\"layout-field layout-option\" data-layout-node data-layout-kind=\"leaf\" data-layout-type=\"Checkbox\" data-mh-role=\"Group\" data-mh-qualifier=\"Field\"><input type=\"checkbox\" name=\"{$name}\" value=\"{$value}\" m-field=\"{$name}\" id=\"{$controlId}\" data-mh-role=\"Input\" data-mh-qualifier=\"Checkbox\"{$required}><label for=\"{$controlId}\" data-mh-role=\"Label\">{$label}</label></div>";
    }

    private function regionMagicHtmlRole(string $semantic): string
    {
        return match ($semantic) {
            'document' => 'Page',
            'header' => 'Header',
            'main' => 'Main',
            'section' => 'Section',
            'article', 'list-item' => 'Item',
            'aside' => 'Frame',
            'footer' => 'Footer',
            'form' => 'Form',
            default => 'Group',
        };
    }

    private function textMagicHtmlRole(string $role): string
    {
        return match ($role) {
            'heading-1', 'heading-2', 'heading-3' => 'Title',
            'label' => 'Label',
            'eyebrow', 'price', 'step-number' => 'Accent',
            default => 'Desc',
        };
    }

    private function regionMagicHtmlQualifier(string $semantic): string
    {
        return $semantic === 'navigation' ? 'Navigation' : '';
    }

    private function designAttributes(string $role, string $id, bool $container = false, string $qualifier = ''): string
    {
        $key = LayoutDesignKey::forNode($role, $id, $qualifier);

        return ' data-mh-design="'.$key.'"'.($container ? ' data-mh-design-container' : '');
    }

    private function isDesignContainer(string $role): bool
    {
        return in_array($role, ['Page', 'Main', 'Header', 'Footer', 'Section', 'Group', 'Item', 'Frame', 'Form'], true);
    }

    private function placeholder(mixed $value): string
    {
        return is_string($value) ? ' placeholder="'.$this->escape($value).'"' : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
