<?php

namespace App\Services;

use App\Support\CanonicalJson;
use Illuminate\Support\Str;

final class WireframeMaterializer
{
    public function __construct(
        private readonly WireframeValidator $validator,
        private readonly WireframeDecorateAst $decorateAst,
    ) {}

    /**
     * @param  array<string,mixed>  $wireframe
     * @return array<string,mixed>
     */
    public function materialize(array $wireframe): array
    {
        $startedAt = hrtime(true);
        $version = ($wireframe['version'] ?? null) === 2 ? 2 : 1;
        $pages = is_array($wireframe['pages'] ?? null) ? $wireframe['pages'] : [];
        $siteAst = ['pages' => array_map(
            fn (mixed $page): array => [
                'key' => is_array($page) ? ($page['key'] ?? null) : null,
                ...($version === 2 ? [
                    'path' => is_array($page) ? ($page['path'] ?? null) : null,
                    'title' => is_array($page) ? ($page['title'] ?? null) : null,
                ] : []),
            ],
            $pages,
        )];
        $normalized = $this->validator->validate($wireframe, $siteAst, $version);
        $decoration = $version === 2 ? $this->decorateAst->definition() : null;
        $sourceDigest = $version === 2
            ? hash('sha256', CanonicalJson::encode([
                'wireframe_ast' => $normalized,
                'wireframe_decorate_ast' => $decoration,
                'renderer_version' => '2.1',
            ]))
            : hash('sha256', CanonicalJson::encode($normalized));
        $files = [];
        $fileManifest = [];
        $usedPaths = [];
        $renderPaths = [];
        $routeToFile = [];

        foreach ($normalized['pages'] as $page) {
            $pageKey = (string) $page['key'];
            $renderPaths[$pageKey] = $this->path($pageKey, $usedPaths);
            if ($version === 2) {
                $routeToFile[(string) $page['path']] = $renderPaths[$pageKey];
            }
        }

        foreach ($normalized['pages'] as $page) {
            $pageKey = (string) $page['key'];
            $path = $renderPaths[$pageKey];
            $content = $version === 2
                ? $this->htmlV2($page, (string) $normalized['locale'], $sourceDigest, $routeToFile)
                : $this->htmlV1($page, $sourceDigest);
            $files[] = [
                'path' => $path,
                'mime' => 'text/html; charset=UTF-8',
                'content_base64' => base64_encode($content),
            ];
            $fileManifest[] = [
                'page_key' => $pageKey,
                'path' => $path,
                'size' => strlen($content),
                'sha256' => hash('sha256', $content),
            ];
        }

        return [
            'source_digest' => $sourceDigest,
            'entry_path' => $files[0]['path'],
            'files' => $files,
            'file_manifest' => $fileManifest,
            ...($decoration !== null ? ['wireframe_decorate_ast' => $decoration] : []),
            'telemetry' => [
                'operation' => 'wireframes.materialize',
                'renderer' => $version === 2 ? 'semantic-wireframe-html' : 'neutral-layout-html',
                'renderer_version' => $version === 2 ? '2.1' : '1.0',
                'provider' => 'deterministic',
                'model' => null,
                'response_id' => null,
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'estimated_cost' => null,
                'provider_request_count' => 0,
                'semantic_attempt_count' => 0,
                'retry_count' => 0,
                'provider_duration_ms' => 0,
                'render_duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
                'rate_card' => null,
            ],
        ];
    }

    /** @param array<string,true> $usedPaths */
    private function path(string $pageKey, array &$usedPaths): string
    {
        $slug = Str::substr(Str::slug($pageKey), 0, 80);
        $slug = $slug !== '' ? $slug : 'page';
        $path = "page-{$slug}.html";
        $comparisonPath = Str::lower($path);
        $collision = 0;
        while (isset($usedPaths[$comparisonPath])) {
            $collision++;
            $suffix = substr(hash('sha256', $pageKey), 0, 12).($collision > 1 ? "-{$collision}" : '');
            $path = "page-{$slug}-{$suffix}.html";
            $comparisonPath = Str::lower($path);
        }
        $usedPaths[$comparisonPath] = true;

        return $path;
    }

    /** @param array<string,mixed> $page */
    private function htmlV1(array $page, string $sourceDigest): string
    {
        $pageKey = $this->escape((string) $page['key']);
        $sections = array_map(fn (array $section): string => $this->sectionV1($section), $page['sections']);
        $body = implode("\n", $sections);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; font-src 'none'; connect-src 'none'; script-src 'none'; base-uri 'none'; form-action 'none'">
<meta name="wireframe-source-sha256" content="{$sourceDigest}">
<title>Wireframe preview</title>
<style>
*{box-sizing:border-box}html{color:#000;background:#fff;font-family:system-ui,sans-serif}body{margin:0;padding:24px}main{width:min(1120px,100%);margin:0 auto}.section{display:grid;gap:20px;margin:0 0 24px;padding:24px;border:1px solid #000}.section-meta{display:flex;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid #000;font:12px/1.4 ui-monospace,monospace}.roles{display:grid;gap:16px}.role{margin:0}.role-eyebrow{font-size:12px;text-transform:uppercase}.role-title{min-height:40px;font-size:24px;font-weight:700}.role-text{min-height:64px;line-height:1.5}.role-items{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:0;list-style:none}.role-items li,.role-actions span,.role-image{min-height:48px;padding:12px;border:1px solid #000}.role-actions{display:flex;gap:12px}.role-image{display:grid;min-height:180px;place-items:center}.composition-hero .roles{grid-template-columns:minmax(0,3fr) minmax(0,2fr)}.composition-hero .role-image{grid-column:2;grid-row:1/span 5}@media(max-width:720px){body{padding:12px}.section{padding:16px}.composition-hero .roles,.role-items{grid-template-columns:1fr}.composition-hero .role-image{grid-column:auto;grid-row:auto}.role-actions{display:grid}}
</style>
</head>
<body>
<main data-wireframe-page="{$pageKey}">
{$body}
</main>
</body>
</html>
HTML;
    }

    /** @param array<string,mixed> $section */
    private function sectionV1(array $section): string
    {
        $key = $this->escape((string) $section['key']);
        $composition = $this->escape((string) $section['composition']);
        $roles = array_map(fn (string $role): string => $this->roleV1($role), $section['roles']);
        $content = implode("\n", $roles);

        return <<<HTML
<section class="section composition-{$composition}" data-section-key="{$key}" data-composition="{$composition}">
<div class="section-meta"><span>{$key}</span><span>{$composition}</span></div>
<div class="roles">
{$content}
</div>
</section>
HTML;
    }

    private function roleV1(string $role): string
    {
        return match ($role) {
            'Eyebrow' => '<p class="role role-eyebrow">Eyebrow</p>',
            'Title' => '<h2 class="role role-title">Title</h2>',
            'Text' => '<p class="role role-text">Text</p>',
            'Items' => '<ul class="role role-items" aria-label="Items"><li>Item</li><li>Item</li><li>Item</li></ul>',
            'Actions' => '<div class="role role-actions" aria-label="Actions"><span>Action</span><span>Action</span></div>',
            'Image' => '<div class="role role-image" role="img" aria-label="Image placeholder">Image</div>',
        };
    }

    /** @param array<string,mixed> $page */
    private function htmlV2(array $page, string $locale, string $sourceDigest, array $routeToFile): string
    {
        $lang = $this->escape($locale);
        $title = $this->escape((string) $page['title']);
        $body = $this->node($page['root'], $routeToFile);
        $css = $this->decorateAst->css();

        return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; font-src 'none'; connect-src 'none'; script-src 'none'; base-uri 'none'; form-action 'none'">
<meta name="wireframe-source-sha256" content="{$sourceDigest}">
<meta name="wireframe-decoration-profile" content="wireframe-neutral-v1">
<title>{$title}</title>
<style>
{$css}
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    /** @param array<string,mixed> $node */
    private function node(array $node, array $routeToFile): string
    {
        $type = (string) $node['type'];

        return match ($type) {
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

    /** @param array<string,mixed> $node */
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
        $magicHtmlAttributes = " data-mh-role=\"{$magicHtmlRole}\"";
        if ($semantic === 'section') {
            $magicHtmlAttributes .= " data-mh-composition=\"{$id}\"";
        }
        $children = implode("\n", array_map(fn (array $child): string => $this->node($child, $routeToFile), $node['children']));
        $formAttribute = $semantic === 'form' ? " m-form=\"{$id}\"" : '';

        return "<{$tag} id=\"{$id}\" class=\"wf-layout-{$layout}\" data-wf-node data-wf-kind=\"region\" data-wf-semantic=\"{$semantic}\" data-wf-stage=\"{$stage}\" data-wf-emphasis=\"{$emphasis}\"{$magicHtmlAttributes}{$formAttribute}>\n{$children}\n</{$tag}>";
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

        return "<{$tag} id=\"{$id}\" class=\"wf-text-{$role}\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Text\" data-mh-role=\"{$magicHtmlRole}\">{$content}</{$tag}>";
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

        return "<figure id=\"{$id}\" class=\"wf-image\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Image\" data-aspect=\"{$aspect}\" data-mh-role=\"Image\" role=\"img\" aria-label=\"{$alt}\"><span>{$alt}</span>{$caption}</figure>";
    }

    /** @param array<string,mixed> $node */
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

        return "<a id=\"{$id}\" class=\"wf-link\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Link\" data-emphasis=\"{$emphasis}\" data-mh-role=\"Link\" href=\"{$href}\">{$label}</a>";
    }

    /** @param array<string,mixed> $node */
    private function buttonLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $type = $this->escape((string) $node['button_type']);
        $emphasis = $this->escape((string) $node['emphasis']);

        return "<button id=\"{$id}\" class=\"wf-button\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Button\" data-emphasis=\"{$emphasis}\" data-mh-role=\"Btn\" type=\"{$type}\">{$label}</button>";
    }

    /** @param array<string,mixed> $node */
    private function inputLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $type = $this->escape((string) $node['input_type']);
        $placeholder = $this->placeholder($node['placeholder']);
        $required = $node['required'] ? ' required' : '';

        return "<label id=\"{$id}\" class=\"wf-field\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Input\" data-mh-role=\"Input\"><span>{$label}</span><input type=\"{$type}\" name=\"{$name}\" m-field=\"{$name}\"{$placeholder}{$required}></label>";
    }

    /** @param array<string,mixed> $node */
    private function textareaLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $placeholder = $this->placeholder($node['placeholder']);
        $required = $node['required'] ? ' required' : '';

        return "<label id=\"{$id}\" class=\"wf-field\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Textarea\" data-mh-role=\"Input\"><span>{$label}</span><textarea name=\"{$name}\" m-field=\"{$name}\"{$placeholder}{$required}></textarea></label>";
    }

    /** @param array<string,mixed> $node */
    private function selectLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $required = $node['required'] ? ' required' : '';
        $placeholder = $node['placeholder'] !== null
            ? '<option value="">'.$this->escape((string) $node['placeholder']).'</option>'
            : '';
        $options = implode('', array_map(
            fn (array $option): string => '<option value="'.$this->escape((string) $option['value']).'">'.$this->escape((string) $option['label']).'</option>',
            $node['options'],
        ));

        return "<label id=\"{$id}\" class=\"wf-field\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Select\" data-mh-role=\"Input\"><span>{$label}</span><select name=\"{$name}\" m-field=\"{$name}\"{$required}>{$placeholder}{$options}</select></label>";
    }

    /** @param array<string,mixed> $node */
    private function checkboxLeaf(array $node): string
    {
        $id = $this->escape((string) $node['id']);
        $label = $this->escape((string) $node['label']);
        $name = $this->escape((string) $node['name']);
        $value = $this->escape((string) $node['value']);
        $required = $node['required'] ? ' required' : '';

        return "<label id=\"{$id}\" class=\"wf-field wf-option\" data-wf-node data-wf-kind=\"leaf\" data-wf-type=\"Checkbox\" data-mh-role=\"Option\"><input type=\"checkbox\" name=\"{$name}\" value=\"{$value}\" m-field=\"{$name}\"{$required}><span>{$label}</span></label>";
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

    private function placeholder(mixed $value): string
    {
        return is_string($value) ? ' placeholder="'.$this->escape($value).'"' : '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
