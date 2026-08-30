<?php

namespace App\Services;

use App\Support\CanonicalJson;
use Illuminate\Support\Str;

final class WireframeMaterializer
{
    public function __construct(private readonly WireframeValidator $validator) {}

    /**
     * @param  array<string,mixed>  $wireframe
     * @return array{
     *     source_digest:string,
     *     entry_path:string,
     *     files:list<array{path:string,mime:string,content_base64:string}>,
     *     file_manifest:list<array{page_key:string,path:string,size:int,sha256:string}>,
     *     telemetry:array{operation:string,renderer:string,renderer_version:string,provider:string,model:null,response_id:null,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost:null,provider_request_count:int,semantic_attempt_count:int,retry_count:int,provider_duration_ms:int,render_duration_ms:int,rate_card:null}
     * }
     */
    public function materialize(array $wireframe): array
    {
        $startedAt = hrtime(true);
        $pages = is_array($wireframe['pages'] ?? null) ? $wireframe['pages'] : [];
        $siteAst = ['pages' => array_map(
            fn (mixed $page): array => ['key' => is_array($page) ? ($page['key'] ?? null) : null],
            $pages,
        )];
        $normalized = $this->validator->validate($wireframe, $siteAst);
        $sourceDigest = hash('sha256', CanonicalJson::encode($normalized));
        $files = [];
        $fileManifest = [];
        $usedPaths = [];

        foreach ($normalized['pages'] as $page) {
            $pageKey = (string) $page['key'];
            $path = $this->path($pageKey, $usedPaths);
            $content = $this->html($page, $sourceDigest);
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
            'telemetry' => [
                'operation' => 'wireframes.materialize',
                'renderer' => 'neutral-layout-html',
                'renderer_version' => '1.0',
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
    private function html(array $page, string $sourceDigest): string
    {
        $pageKey = $this->escape((string) $page['key']);
        $sections = array_map(fn (array $section): string => $this->section($section), $page['sections']);
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
    private function section(array $section): string
    {
        $key = $this->escape((string) $section['key']);
        $composition = $this->escape((string) $section['composition']);
        $roles = array_map(fn (string $role): string => $this->role($role), $section['roles']);
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

    private function role(string $role): string
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
