<?php

namespace App\WireframePresentation;

use LogicException;

final class WireframePresentationPreset
{
    public const PROFILE = 'wireframe-presentation-v1';

    public const VERSION = '1.0';

    private const ALLOWED_PROPERTIES = [
        'background',
        'background-color',
        'color',
        'outline',
        'outline-color',
        'outline-offset',
        'outline-style',
        'outline-width',
        'text-decoration',
        'text-decoration-color',
        'text-decoration-line',
        'text-decoration-style',
    ];

    /** @return array<string,mixed> */
    public function skin(): array
    {
        return [
            'version' => 1,
            'preset' => 'wireframe-neutral-skin-v2',
            'canvas' => ['background' => '#ffffff', 'foreground' => '#171717'],
            'surfaces' => [
                'container' => 'transparent',
                'leaf' => 'rgba(255,255,255,0.88)',
                'image' => '#d1d5db',
                'input' => '#f3f4f6',
                'button' => '#171717',
            ],
            'link' => ['color' => '#172554', 'text_decoration' => 'underline'],
        ];
    }

    /** @return array<string,mixed> */
    public function decor(): array
    {
        return [
            'version' => 1,
            'preset' => 'wireframe-structure-decor-v2',
            'outlines' => [
                'region' => ['width_px' => 1, 'style' => 'solid', 'color' => '#737373'],
                'container' => ['width_px' => 1, 'style' => 'dashed', 'color' => '#a3a3a3'],
                'primary_action' => ['width_px' => 2, 'style' => 'solid', 'color' => '#171717'],
            ],
            'geometry_mutation' => false,
            'forbidden_properties' => [
                'font', 'border', 'padding', 'margin', 'display', 'width', 'height', 'gap', 'position',
                'inset', 'transform', 'animation', 'transition',
            ],
        ];
    }

    public function css(): string
    {
        $skin = $this->skin();
        $decor = $this->decor();
        $canvas = $skin['canvas'];
        $surfaces = $skin['surfaces'];
        $outlines = $decor['outlines'];
        $css = <<<CSS
@layer mh-wireframe-skin, mh-wireframe-decor;
@layer mh-wireframe-skin {
  html, body { background: {$canvas['background']}; color: {$canvas['foreground']}; }
  [data-layout-kind="region"] { background: {$surfaces['container']}; }
  [data-layout-kind="leaf"] { background: {$surfaces['leaf']}; }
  .layout-image { background: {$surfaces['image']}; }
  .layout-button, .layout-link[data-layout-emphasis="primary"] { background: {$surfaces['button']}; color: #ffffff; text-decoration: none; }
  .layout-link { color: {$skin['link']['color']}; text-decoration: {$skin['link']['text_decoration']}; }
  .layout-field input, .layout-field textarea, .layout-field select { background: {$surfaces['input']}; color: {$canvas['foreground']}; }
}
@layer mh-wireframe-decor {
  [data-layout-kind="region"] { outline: {$outlines['region']['width_px']}px {$outlines['region']['style']} {$outlines['region']['color']}; outline-offset: -1px; }
  [data-layout-semantic="group"], [data-layout-semantic="article"], [data-layout-semantic="aside"], [data-layout-semantic="ordered-list"], [data-layout-semantic="unordered-list"], [data-layout-semantic="list-item"], [data-layout-semantic="document"], [data-layout-semantic="main"] { outline-color: {$outlines['container']['color']}; outline-style: {$outlines['container']['style']}; }
  .layout-link[data-layout-emphasis="primary"], .layout-button[data-layout-emphasis="primary"] { outline: {$outlines['primary_action']['width_px']}px {$outlines['primary_action']['style']} {$outlines['primary_action']['color']}; }
  .layout-field input, .layout-field textarea, .layout-field select { outline: 1px solid {$outlines['container']['color']}; outline-offset: -1px; }
}
CSS;
        $this->assertPaintOnly($css);

        return $css;
    }

    public function assertPaintOnly(string $css): void
    {
        preg_match_all('/(?<=[{;])\s*([a-z-]+)\s*:/i', $css, $matches);
        $properties = array_values(array_unique(array_map(strtolower(...), $matches[1] ?? [])));
        $unsupported = array_values(array_diff($properties, self::ALLOWED_PROPERTIES));
        if ($unsupported !== []) {
            throw new LogicException('Wireframe presentation CSS contains geometry-affecting properties: '.implode(', ', $unsupported));
        }
    }
}
