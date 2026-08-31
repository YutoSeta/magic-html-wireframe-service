<?php

namespace App\Services;

final class WireframeDecorateAst
{
    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'version' => 1,
            'preset' => 'wireframe-neutral-v1',
            'purpose' => 'Expose semantic nesting without applying brand skin.',
            'canvas' => [
                'background' => '#ffffff',
                'foreground' => '#171717',
                'max_width_px' => 1120,
            ],
            'spacing' => [
                'base_px' => 8,
                'leaf_padding_px' => 8,
                'container_padding_px' => 16,
                'section_padding_desktop_px' => 64,
                'section_padding_mobile_px' => 28,
                'node_margin_px' => 4,
            ],
            'borders' => [
                'region' => ['width_px' => 1, 'style' => 'solid', 'color' => '#737373'],
                'container' => ['width_px' => 1, 'style' => 'dashed', 'color' => '#a3a3a3'],
                'leaf' => ['width_px' => 1, 'style' => 'solid', 'color' => '#d4d4d4'],
                'primary_action' => ['width_px' => 2, 'style' => 'solid', 'color' => '#171717'],
            ],
            'surfaces' => [
                'container' => 'transparent',
                'leaf' => 'rgba(255,255,255,0.88)',
                'image' => '#d1d5db',
            ],
            'responsive' => [
                'breakpoint_px' => 720,
                'minimum_action_height_px' => 44,
            ],
            'forbidden' => [
                'brand_color',
                'gradient',
                'shadow',
                'border_radius',
                'animation',
                'external_asset',
            ],
        ];
    }

    public function css(): string
    {
        $definition = $this->definition();
        $canvas = $definition['canvas'];
        $spacing = $definition['spacing'];
        $borders = $definition['borders'];
        $surfaces = $definition['surfaces'];
        $responsive = $definition['responsive'];

        return <<<CSS
*{box-sizing:border-box}html{color:{$canvas['foreground']};background:{$canvas['background']};font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.65}body{margin:0;background:{$canvas['background']}}[data-wf-node]{min-width:0;overflow-wrap:anywhere}[data-wf-kind="region"]{margin:{$spacing['node_margin_px']}px;padding:{$spacing['container_padding_px']}px;border:{$borders['region']['width_px']}px {$borders['region']['style']} {$borders['region']['color']};background:{$surfaces['container']}}[data-wf-semantic="group"],[data-wf-semantic="article"],[data-wf-semantic="aside"],[data-wf-semantic="ordered-list"],[data-wf-semantic="unordered-list"],[data-wf-semantic="list-item"],[data-wf-semantic="field-group"]{border-style:{$borders['container']['style']};border-color:{$borders['container']['color']}}[data-wf-semantic="document"]{width:min({$canvas['max_width_px']}px,100%);margin:0 auto;padding:8px;border-style:dashed;border-color:{$borders['leaf']['color']}}[data-wf-semantic="header"],[data-wf-semantic="footer"]{padding:16px}[data-wf-semantic="header"].wf-layout-cluster{justify-content:space-between;gap:12px}[data-wf-semantic="header"].wf-layout-cluster>[data-wf-node]{flex:0 1 auto}[data-wf-semantic="navigation"].wf-layout-cluster{justify-content:flex-end;gap:8px}[data-wf-semantic="navigation"].wf-layout-cluster>[data-wf-node]{flex:0 1 auto}[data-wf-semantic="main"]{padding:8px;border-style:dashed;border-color:{$borders['container']['color']}}[data-wf-semantic="section"]{margin:16px 4px;padding:clamp({$spacing['section_padding_mobile_px']}px,5vw,{$spacing['section_padding_desktop_px']}px) clamp(20px,4vw,48px)}[data-wf-kind="leaf"]{margin:{$spacing['node_margin_px']}px;padding:{$spacing['leaf_padding_px']}px;border:{$borders['leaf']['width_px']}px {$borders['leaf']['style']} {$borders['leaf']['color']};background:{$surfaces['leaf']}}.wf-layout-stack{display:grid;gap:16px}.wf-layout-cluster{display:flex;align-items:center;flex-wrap:wrap;gap:16px}.wf-layout-grid-2,.wf-layout-grid-3,.wf-layout-grid-4,.wf-layout-split,.wf-layout-split-wide-start,.wf-layout-split-wide-end{display:grid;gap:24px}.wf-layout-grid-2,.wf-layout-split{grid-template-columns:repeat(2,minmax(0,1fr))}.wf-layout-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.wf-layout-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}.wf-layout-split-wide-start{grid-template-columns:minmax(0,3fr) minmax(0,2fr)}.wf-layout-split-wide-end{grid-template-columns:minmax(0,2fr) minmax(0,3fr)}.wf-layout-centered{display:grid;gap:20px;width:min(760px,100%);margin-inline:auto}.wf-layout-cluster>[data-wf-node]{flex:1 1 160px}.wf-text-eyebrow,.wf-text-small,.wf-text-label,.wf-text-step-number{font-size:.8125rem}.wf-text-eyebrow,.wf-text-label,.wf-text-step-number{font-weight:700}.wf-text-heading-1{font-size:clamp(2rem,5vw,4rem);line-height:1.15;font-weight:800}.wf-text-heading-2{font-size:clamp(1.6rem,3vw,2.5rem);line-height:1.25;font-weight:750}.wf-text-heading-3{font-size:1.2rem;line-height:1.35;font-weight:700}.wf-text-price{font-size:1.35rem;font-weight:800}.wf-image{display:grid;place-items:center;min-height:220px;background:{$surfaces['image']};text-align:center}.wf-image[data-aspect="16:9"]{aspect-ratio:16/9}.wf-image[data-aspect="4:3"]{aspect-ratio:4/3}.wf-image[data-aspect="3:2"]{aspect-ratio:3/2}.wf-image[data-aspect="1:1"]{aspect-ratio:1}.wf-image[data-aspect="2:3"]{aspect-ratio:2/3}.wf-image figcaption{align-self:end;width:100%;padding:8px;border-top:1px solid {$borders['leaf']['color']};background:rgba(255,255,255,.72);font-size:.8125rem}.wf-link,.wf-button{display:inline-flex;align-items:center;justify-content:center;min-height:{$responsive['minimum_action_height_px']}px;width:max-content;max-width:100%;padding:10px 18px;color:inherit;text-decoration:none;font:inherit;font-weight:650}.wf-link[data-emphasis="primary"],.wf-button[data-emphasis="primary"]{border:{$borders['primary_action']['width_px']}px {$borders['primary_action']['style']} {$borders['primary_action']['color']};font-weight:800}.wf-button{cursor:default}.wf-field{display:grid;gap:8px}.wf-field>span,.wf-field legend{font-weight:700}.wf-field input:not([type="checkbox"]):not([type="radio"]),.wf-field textarea,.wf-field select{width:100%;min-height:44px;padding:10px;border:1px solid #737373;background:#fff;color:#171717;font:inherit}.wf-field textarea{min-height:128px;resize:none}.wf-fieldset{min-width:0}.wf-options{display:grid;gap:8px}.wf-option{display:flex;align-items:flex-start;gap:8px}.wf-option input{margin-top:.35em}ol[data-wf-node],ul[data-wf-node]{padding-left:32px}h1,h2,h3,p,figure{margin-top:0;margin-bottom:0}.wf-skip{position:absolute;left:-9999px}.wf-skip:focus{position:fixed;left:8px;top:8px;z-index:10;background:#fff}@media(max-width:{$responsive['breakpoint_px']}px){[data-wf-semantic="document"]{width:100%}[data-wf-semantic="header"],[data-wf-semantic="footer"]{padding:12px}[data-wf-semantic="header"].wf-layout-cluster{display:grid;grid-template-columns:1fr;gap:8px}[data-wf-semantic="navigation"].wf-layout-cluster{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}[data-wf-semantic="navigation"].wf-layout-cluster>[data-wf-node]{width:auto;margin:0;padding:8px 6px;min-height:40px}[data-wf-semantic="section"]{margin:10px 4px;padding:{$spacing['section_padding_mobile_px']}px 14px}.wf-layout-grid-2,.wf-layout-grid-3,.wf-layout-grid-4,.wf-layout-split,.wf-layout-split-wide-start,.wf-layout-split-wide-end{grid-template-columns:1fr}.wf-layout-cluster{align-items:stretch}.wf-layout-cluster>[data-wf-node]{flex-basis:100%}.wf-link,.wf-button{width:100%}.wf-image{min-height:160px}}
CSS;
    }
}
