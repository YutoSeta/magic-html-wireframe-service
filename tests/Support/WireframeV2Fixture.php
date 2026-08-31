<?php

namespace Tests\Support;

final class WireframeV2Fixture
{
    /** @return array<string,mixed> */
    public static function siteAst(): array
    {
        return [
            'version' => 1,
            'site' => ['name' => 'ウェブ修理工房', 'description' => '壊れたWebサイトを診断し、必要最小限で修理するサービス。'],
            'pages' => [
                ['key' => 'home', 'path' => '/', 'title' => 'ウェブ修理工房', 'purpose' => '無料相談の獲得'],
                ['key' => 'privacy', 'path' => '/privacy', 'title' => 'プライバシーポリシー', 'purpose' => '個人情報の取扱説明'],
                ['key' => 'legal', 'path' => '/legal', 'title' => '法的事項', 'purpose' => '運営者と取引条件の説明'],
            ],
            'navigation' => [
                ['label' => 'ホーム', 'path' => '/'],
                ['label' => 'プライバシー', 'path' => '/privacy'],
                ['label' => '法的事項', 'path' => '/legal'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function document(): array
    {
        return [
            'version' => 2,
            'locale' => 'ja',
            'pages' => [[
                'key' => 'home',
                'path' => '/',
                'title' => 'ウェブ修理工房',
                'root' => self::region('page-home', 'document', 'stack', 'none', 'neutral', [
                    self::region('site-header', 'header', 'cluster', 'none', 'supporting', [
                        self::text('brand-name', 'label', 'ウェブ修理工房'),
                        self::region('primary-navigation', 'navigation', 'cluster', 'none', 'standard', [
                            self::link('symptoms-link', '症状', '#symptoms', 'plain'),
                            self::link('consultation-link', '無料で相談する', '#consultation', 'primary'),
                        ]),
                    ]),
                    self::region('main-content', 'main', 'stack', 'none', 'neutral', [
                        self::region('hero', 'section', 'split-wide-start', 'attention', 'primary', [
                            self::region('hero-copy', 'group', 'stack', 'attention', 'strong', [
                                self::text('hero-eyebrow', 'eyebrow', 'サイトの不具合・引き継ぎにお困りの方へ'),
                                self::text('hero-title', 'heading-1', '作り直す前に、まず直せるか診断。'),
                                self::text('hero-lead', 'body', 'フォーム不達やスマホ表示の崩れを、必要最小限の修理から検討します。<安心>'),
                                self::link('hero-action', 'URLと症状だけ送る', '#consultation', 'primary'),
                            ]),
                            self::image('hero-image', 'PCとスマートフォンのサイト診断イメージ', '診断対象を整理する画面', '4:3'),
                        ]),
                        self::region('symptoms', 'section', 'grid-2', 'interest', 'standard', [
                            self::text('symptoms-title', 'heading-2', 'こんな症状を放置していませんか'),
                            self::region('symptom-card', 'article', 'stack', 'interest', 'standard', [
                                self::image('symptom-image', '問い合わせフォームの不達を示すイメージ', null, '16:9'),
                                self::text('symptom-name', 'heading-3', 'フォームが届かない'),
                                self::text('symptom-body', 'body', '送信できたように見えて通知されない状態を確認します。'),
                            ]),
                        ]),
                        self::region('consultation', 'section', 'split', 'action', 'primary', [
                            self::region('consultation-copy', 'group', 'stack', 'action', 'strong', [
                                self::text('consultation-title', 'heading-2', 'まずはURLと症状をお送りください'),
                                self::text('consultation-hours', 'body', '相談フォームは24時間受付。営業時間は9:00〜18:00で、ご回答は翌営業日以降です。'),
                            ]),
                            self::region('free-consultation', 'form', 'stack', 'action', 'primary', [
                                self::input('contact-name', 'text', 'お名前', 'name', null, true),
                                self::input('contact-email', 'email', 'メールアドレス', 'email', 'name@example.jp', true),
                                self::input('contact-url', 'url', 'サイトURL', 'site_url', 'https://example.jp', false),
                                self::select('contact-symptom', '主な症状', 'symptom', '選択してください', true, [
                                    ['label' => 'フォームが届かない', 'value' => 'form'],
                                    ['label' => 'スマホ表示が崩れる', 'value' => 'mobile'],
                                ]),
                                self::textarea('contact-detail', '困っていること', 'detail', '分かる範囲でご記入ください', true),
                                self::checkbox('privacy-consent', 'プライバシーポリシーに同意する', 'privacy', 'accepted', true),
                                self::link('privacy-link', 'プライバシーポリシーを確認', '/privacy', 'plain'),
                                self::button('submit-consultation', '無料相談を送信する', 'submit', 'primary'),
                            ]),
                        ]),
                    ]),
                    self::region('site-footer', 'footer', 'cluster', 'none', 'supporting', [
                        self::text('operator-name', 'small', '運営：合同会社eclair'),
                        self::link('legal-link', '法的事項', '/legal', 'plain'),
                    ]),
                ]),
            ], self::utilityPage('privacy', '/privacy', 'プライバシーポリシー'), self::utilityPage('legal', '/legal', '法的事項')],
        ];
    }

    /** @return array<string,mixed> */
    private static function utilityPage(string $key, string $path, string $title): array
    {
        return [
            'key' => $key,
            'path' => $path,
            'title' => $title,
            'root' => self::region("page-{$key}", 'document', 'stack', 'none', 'neutral', [
                self::region("{$key}-main", 'main', 'stack', 'none', 'neutral', [
                    self::region("{$key}-intro", 'section', 'centered', 'attention', 'strong', [
                        self::text("{$key}-title", 'heading-1', $title),
                        self::text("{$key}-lead", 'body', 'ウェブ修理工房の運営とサービス利用に関する大切な情報です。'),
                    ]),
                    self::region("{$key}-details", 'section', 'centered', 'interest', 'standard', [
                        self::text("{$key}-details-title", 'heading-2', 'ご確認いただく内容'),
                        self::text("{$key}-details-body", 'body', '公開前に確定情報を確認し、最新の内容を掲載します。'),
                        self::link("{$key}-home-link", 'ホームへ戻る', '/', 'plain'),
                    ]),
                ]),
            ]),
        ];
    }

    /** @param list<array<string,mixed>> $children @return array<string,mixed> */
    public static function region(string $id, string $semantic, string $layout, string $stage, string $emphasis, array $children): array
    {
        return [
            'type' => 'Region', 'id' => $id, 'semantic' => $semantic, 'layout' => $layout,
            'journey_stage' => $stage, 'emphasis' => $emphasis, 'children' => $children,
        ];
    }

    /** @return array<string,mixed> */
    public static function text(string $id, string $role, string $content): array
    {
        return ['type' => 'Text', 'id' => $id, 'role' => $role, 'content' => $content];
    }

    /** @return array<string,mixed> */
    public static function image(string $id, string $alt, ?string $caption, string $aspect): array
    {
        return ['type' => 'Image', 'id' => $id, 'alt' => $alt, 'caption' => $caption, 'aspect' => $aspect];
    }

    /** @return array<string,mixed> */
    public static function link(string $id, string $label, string $href, string $emphasis): array
    {
        return ['type' => 'Link', 'id' => $id, 'label' => $label, 'href' => $href, 'emphasis' => $emphasis];
    }

    /** @return array<string,mixed> */
    public static function button(string $id, string $label, string $buttonType, string $emphasis): array
    {
        return ['type' => 'Button', 'id' => $id, 'label' => $label, 'button_type' => $buttonType, 'emphasis' => $emphasis];
    }

    /** @return array<string,mixed> */
    public static function input(string $id, string $inputType, string $label, string $name, ?string $placeholder, bool $required): array
    {
        return ['type' => 'Input', 'id' => $id, 'input_type' => $inputType, 'label' => $label, 'name' => $name, 'placeholder' => $placeholder, 'required' => $required];
    }

    /** @return array<string,mixed> */
    public static function textarea(string $id, string $label, string $name, ?string $placeholder, bool $required): array
    {
        return ['type' => 'Textarea', 'id' => $id, 'label' => $label, 'name' => $name, 'placeholder' => $placeholder, 'required' => $required];
    }

    /** @param list<array{label:string,value:string}> $options @return array<string,mixed> */
    public static function select(string $id, string $label, string $name, ?string $placeholder, bool $required, array $options): array
    {
        return ['type' => 'Select', 'id' => $id, 'label' => $label, 'name' => $name, 'placeholder' => $placeholder, 'required' => $required, 'options' => $options];
    }

    /** @return array<string,mixed> */
    public static function checkbox(string $id, string $label, string $name, string $value, bool $required): array
    {
        return ['type' => 'Checkbox', 'id' => $id, 'label' => $label, 'name' => $name, 'value' => $value, 'required' => $required];
    }
}
