<?php

namespace App\Services;

use App\Exceptions\InvalidWireframeException;
use App\Services\Contracts\ReportsWireframeTelemetry;
use App\Services\Contracts\WireframeGenerator;
use App\Support\ExecutionProfile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OpenAiWireframeGenerator implements ReportsWireframeTelemetry, WireframeGenerator
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $telemetry = null;

    public function __construct(
        private readonly WireframeValidator $validator,
        private readonly ExecutionProfile $executionProfiles,
    ) {}

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function generate(array $siteAst, array $brief, string $locale, int $wireframeAstVersion = 1, string $executionProfile = 'fast'): array
    {
        $profile = $this->executionProfiles->resolve($executionProfile);
        $this->validator->validateGenerationInput($siteAst, $locale, $wireframeAstVersion);
        $this->telemetry = null;
        $providerTelemetry = new OpenAiTelemetry((array) config('services.openai.rate_card', []));
        $feedback = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $providerTelemetry->beginSemanticAttempt();
            $request = Http::withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->timeout((int) config('services.openai.timeout', 300))
                ->connectTimeout((int) config('services.openai.connect_timeout', 10))
                ->beforeSending(function () use ($providerTelemetry): void {
                    $providerTelemetry->recordProviderRequest();
                });
            $retryDelays = $this->retryDelays();
            if ($retryDelays !== []) {
                $request->retry($retryDelays, throw: false);
            }

            $startedAt = hrtime(true);
            try {
                $response = $request->post((string) config('services.openai.url'), [
                    'model' => $profile['model'],
                    'instructions' => $this->instructions($wireframeAstVersion),
                    'input' => json_encode([
                        'site_ast' => $siteAst,
                        'brief' => $brief,
                        'locale' => $locale,
                        'wireframe_ast_version' => $wireframeAstVersion,
                        'validation_feedback' => $feedback,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'reasoning' => ['effort' => $profile['reasoning_effort']],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'wireframes',
                        'strict' => true,
                        'schema' => $this->schema($wireframeAstVersion),
                    ]],
                    'max_output_tokens' => $wireframeAstVersion === 2 ? 64000 : 12000,
                    'store' => false,
                    'metadata' => ['stage' => 'wireframes', 'execution_profile' => $profile['id']],
                ]);
            } finally {
                $providerTelemetry->recordProviderDuration(hrtime(true) - $startedAt);
            }

            if (! $response->successful()) {
                Log::warning('Wireframe provider rejected request.', [
                    'provider' => 'openai',
                    'model' => $this->identifier($profile['model']),
                    'status' => $response->status(),
                    'request_id' => $this->identifier($response->header('x-request-id')),
                    'error_type' => $this->identifier($response->json('error.type')),
                    'error_code' => $this->identifier($response->json('error.code')),
                    'error_param' => $this->identifier($response->json('error.param')),
                ]);

                throw new RuntimeException('The wireframe provider rejected the request.');
            }
            $providerResponse = $response->json();
            $providerTelemetry->recordResponse(is_array($providerResponse) ? $providerResponse : []);
            $text = $response->json('output_text');
            if (! is_string($text)) {
                $text = $this->outputText((array) $response->json('output', []));
            }
            $document = is_string($text) ? json_decode($text, true) : null;
            if (! is_array($document)) {
                if ($wireframeAstVersion === 1) {
                    throw new RuntimeException('The wireframe provider returned invalid JSON.');
                }
                $status = is_string($providerResponse['status'] ?? null) ? $providerResponse['status'] : 'unknown';
                $feedback = "The provider returned incomplete or invalid JSON (status: {$status}). Produce one complete JSON document within the declared limits.";

                continue;
            }
            $document = $this->normalizeProviderDocument($document, $wireframeAstVersion);

            try {
                $wireframe = $this->validator->validate($document, $siteAst, $wireframeAstVersion, $locale);
                $this->telemetry = $providerTelemetry->toArray();

                return $wireframe;
            } catch (InvalidWireframeException $exception) {
                $feedback = $exception->getMessage();
            }
        }

        throw new InvalidWireframeException('No valid wireframe was produced. '.($feedback ?? ''));
    }

    /** @return array<string,mixed>|null */
    public function telemetry(): ?array
    {
        return $this->telemetry;
    }

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function startBackground(
        array $siteAst,
        array $brief,
        string $locale,
        int $wireframeAstVersion = 1,
        ?string $validationFeedback = null,
        string $executionProfile = 'fast',
    ): array {
        $profile = $this->executionProfiles->resolve($executionProfile);
        $this->validator->validateGenerationInput($siteAst, $locale, $wireframeAstVersion);
        $response = $this->providerRequest()->post(
            (string) config('services.openai.url'),
            $this->providerPayload($siteAst, $brief, $locale, $wireframeAstVersion, $validationFeedback, true, $profile),
        );
        $this->assertSuccessfulProviderResponse($response);
        $providerResponse = $response->json();
        if (! is_array($providerResponse)
            || $this->identifier($providerResponse['id'] ?? null) === null
            || ! in_array($providerResponse['status'] ?? null, ['queued', 'in_progress', 'completed'], true)) {
            throw new RuntimeException('The wireframe provider returned an invalid background job.');
        }

        return $providerResponse;
    }

    /** @return array<string,mixed> */
    public function retrieveBackground(string $responseId): array
    {
        if ($this->identifier($responseId) === null || ! str_starts_with($responseId, 'resp_')) {
            throw new RuntimeException('The wireframe provider response identifier is invalid.');
        }
        $response = $this->providerRequest()->get(rtrim((string) config('services.openai.url'), '/').'/'.rawurlencode($responseId));
        $this->assertSuccessfulProviderResponse($response);
        $providerResponse = $response->json();
        if (! is_array($providerResponse) || ! is_string($providerResponse['status'] ?? null)) {
            throw new RuntimeException('The wireframe provider returned an invalid background status.');
        }

        return $providerResponse;
    }

    /** @param array<string,mixed> $providerResponse @param array<string,mixed> $siteAst @return array{wireframe:array<string,mixed>,telemetry:array<string,mixed>} */
    public function completeBackground(array $providerResponse, array $siteAst, string $locale, int $wireframeAstVersion): array
    {
        if (($providerResponse['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The wireframe provider response is not complete.');
        }
        $text = is_string($providerResponse['output_text'] ?? null)
            ? $providerResponse['output_text']
            : $this->outputText((array) ($providerResponse['output'] ?? []));
        $document = json_decode($text, true);
        if (! is_array($document)) {
            throw new RuntimeException('The wireframe provider returned invalid JSON.');
        }
        $document = $this->normalizeProviderDocument($document, $wireframeAstVersion);
        $wireframe = $this->validator->validate($document, $siteAst, $wireframeAstVersion, $locale);

        return ['wireframe' => $wireframe, 'telemetry' => $this->backgroundTelemetry($providerResponse)];
    }

    /** @param array<string,mixed> $providerResponse @return array<string,mixed> */
    public function backgroundTelemetry(array $providerResponse): array
    {
        $providerTelemetry = new OpenAiTelemetry((array) config('services.openai.rate_card', []));
        $providerTelemetry->beginSemanticAttempt();
        $providerTelemetry->recordProviderRequest();
        $providerTelemetry->recordResponse($providerResponse);

        return $providerTelemetry->toArray();
    }

    /** @param array<string,mixed> $providerResponse */
    public function outputLimitRetryFeedback(array $providerResponse): ?string
    {
        if (($providerResponse['status'] ?? null) !== 'incomplete'
            || ($providerResponse['incomplete_details']['reason'] ?? null) !== 'max_output_tokens') {
            return null;
        }

        return 'The previous response exhausted the output-token limit. Return one complete, concise document rather than continuing the partial response. Use at most 80 nodes per page, 240 nodes total, four nested Region levels, eight sections per page, and 240 characters per body Text node. Remove repetition before omitting required page, form, image, link, or CTA semantics.';
    }

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function startPlanBackground(array $siteAst, array $brief, string $locale, string $executionProfile): array
    {
        $this->validator->validateGenerationInput($siteAst, $locale, 2);

        return $this->startStructuredBackground(
            $this->planSchema(),
            $this->planningInstructions(),
            ['site_ast' => $siteAst, 'brief' => $brief, 'locale' => $locale],
            $executionProfile,
            'wireframe_plan',
            12000,
        );
    }

    /** @param array<string,mixed> $providerResponse @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function completePlanBackground(array $providerResponse, array $siteAst, array $brief): array
    {
        $plan = $this->completedDocument($providerResponse);
        $pages = $plan['pages'] ?? null;
        if (($plan['version'] ?? null) !== 1 || ! is_array($pages) || ! array_is_list($pages)) {
            throw new InvalidWireframeException('The section plan is invalid.');
        }
        $sitePages = [];
        foreach ($siteAst['pages'] ?? [] as $page) {
            if (is_array($page) && is_string($page['key'] ?? null)) {
                $sitePages[$page['key']] = $page;
            }
        }
        $seen = [];
        $totalSections = 0;
        $requiresForm = false;
        foreach ($pages as $page) {
            if (! is_array($page) || ! is_string($page['key'] ?? null) || isset($seen[$page['key']])) {
                throw new InvalidWireframeException('The section plan contains an invalid page key.');
            }
            $sitePage = $sitePages[$page['key']] ?? null;
            if (! is_array($sitePage)
                || ($page['path'] ?? null) !== ($sitePage['path'] ?? null)
                || ($page['title'] ?? null) !== ($sitePage['title'] ?? null)) {
                throw new InvalidWireframeException('The section plan must preserve every Site AST page.');
            }
            $sections = $page['sections'] ?? null;
            if (! is_array($sections) || ! array_is_list($sections) || count($sections) < 2 || count($sections) > 8) {
                throw new InvalidWireframeException('Every planned page must contain 2 to 8 sections.');
            }
            $sectionIds = [];
            $headingCount = 0;
            foreach ($sections as $section) {
                $id = is_array($section) ? ($section['id'] ?? null) : null;
                if (! is_string($id)
                    || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $id) !== 1
                    || str_starts_with($id, 'chrome-')
                    || in_array('image', explode('-', $id), true)
                    || isset($sectionIds[$id])) {
                    throw new InvalidWireframeException('Planned section IDs must be unique safe identifiers.');
                }
                $sectionIds[$id] = true;
                $headingCount += ($section['contains_heading_1'] ?? false) === true ? 1 : 0;
                $requiresForm = $requiresForm || ($section['requires_form'] ?? false) === true;
            }
            if ($headingCount !== 1) {
                throw new InvalidWireframeException('Every planned page must assign exactly one heading-1 section.');
            }
            $seen[$page['key']] = true;
            $totalSections += count($sections);
        }
        if (array_diff_key($sitePages, $seen) !== [] || count($seen) !== count($sitePages) || $totalSections > 24) {
            throw new InvalidWireframeException('The section plan exceeds the bounded site plan.');
        }
        $briefText = mb_strtolower(implode(' ', array_filter([
            $brief['goals'] ?? null,
            $brief['requirements'] ?? null,
        ], 'is_string')));
        if (preg_match('/(?:form|contact|request|フォーム|問い合わせ|資料請求)/u', $briefText) === 1 && ! $requiresForm) {
            throw new InvalidWireframeException('The section plan must include the requested interactive form.');
        }

        return $plan;
    }

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @param array<string,mixed> $plan @param array<string,mixed> $pagePlan @param array<string,mixed> $sectionPlan @return array<string,mixed> */
    public function startSectionBackground(array $siteAst, array $brief, string $locale, array $plan, array $pagePlan, array $sectionPlan, string $executionProfile, ?string $validationFeedback = null): array
    {
        return $this->startStructuredBackground(
            $this->sectionSchema($pagePlan, $sectionPlan),
            $this->sectionInstructions(),
            [
                'site_ast' => $siteAst,
                'brief' => $brief,
                'locale' => $locale,
                'site_plan' => $plan,
                'current_page' => $pagePlan,
                'current_section' => $sectionPlan,
                'validation_feedback' => $validationFeedback,
            ],
            $executionProfile,
            'wireframe_section',
            12000,
        );
    }

    /** @param array<string,mixed> $providerResponse @param array<string,mixed> $pagePlan @param array<string,mixed> $sectionPlan @return array<string,mixed> */
    public function completeSectionBackground(array $providerResponse, array $pagePlan, array $sectionPlan): array
    {
        $document = $this->completedDocument($providerResponse);
        if (($document['version'] ?? null) !== 1
            || ($document['page_key'] ?? null) !== ($pagePlan['key'] ?? null)
            || ($document['section_id'] ?? null) !== ($sectionPlan['id'] ?? null)
            || ! is_array($document['section'] ?? null)) {
            throw new InvalidWireframeException('The generated section does not match its plan.');
        }
        $section = $this->normalizeProviderNode($document['section']);
        if (($section['type'] ?? null) !== 'Region'
            || ($section['semantic'] ?? null) !== 'section'
            || ($section['id'] ?? null) !== ($sectionPlan['id'] ?? null)
            || ($section['layout'] ?? null) !== ($sectionPlan['layout'] ?? null)
            || ($section['journey_stage'] ?? null) !== ($sectionPlan['journey_stage'] ?? null)
            || ($section['emphasis'] ?? null) !== ($sectionPlan['emphasis'] ?? null)) {
            throw new InvalidWireframeException('The generated section changed its planned identity.');
        }
        $facts = ['heading_1' => 0, 'form' => 0, 'image' => 0, 'nodes' => 0];
        $walk = function (array $node) use (&$walk, &$facts): void {
            $facts['nodes']++;
            $facts['heading_1'] += ($node['type'] ?? null) === 'Text' && ($node['role'] ?? null) === 'heading-1' ? 1 : 0;
            $facts['form'] += ($node['type'] ?? null) === 'Region' && ($node['semantic'] ?? null) === 'form' ? 1 : 0;
            $facts['image'] += ($node['type'] ?? null) === 'Image' ? 1 : 0;
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($section);
        if (($sectionPlan['requires_image'] ?? false) === true && $facts['image'] === 0) {
            $purpose = is_string($sectionPlan['purpose'] ?? null) ? $sectionPlan['purpose'] : 'セクションの内容';
            $section['children'][] = [
                'type' => 'Image',
                'id' => (string) $sectionPlan['id'].'-image',
                'intent' => $purpose.'を視覚的に補足する画像',
                'alt' => $purpose,
                'aspect_ratio' => '16:9',
            ];
            $facts['image'] = 1;
            $facts['nodes']++;
        }
        if (($sectionPlan['requires_form'] ?? false) === true && $facts['form'] === 0) {
            $sectionId = (string) $sectionPlan['id'];
            $journeyStage = (string) ($sectionPlan['journey_stage'] ?? 'action');
            $section['children'][] = [
                'type' => 'Region',
                'id' => $sectionId.'-form',
                'semantic' => 'form',
                'layout' => 'stack',
                'journey_stage' => $journeyStage,
                'emphasis' => 'primary',
                'content' => [[
                    'type' => 'Text',
                    'id' => $sectionId.'-form-note',
                    'role' => 'body',
                    'content' => '必要事項をご入力ください。',
                ]],
                'controls' => [[
                    'type' => 'Input',
                    'id' => $sectionId.'-email',
                    'input_type' => 'email',
                    'label' => 'メールアドレス',
                    'name' => 'email',
                    'placeholder' => 'name@example.jp',
                    'required' => true,
                ]],
                'submit' => [
                    'type' => 'Button',
                    'id' => $sectionId.'-submit',
                    'label' => '送信する',
                    'button_type' => 'submit',
                    'emphasis' => 'primary',
                ],
            ];
            $facts['form'] = 1;
            $facts['nodes'] += 4;
        }
        $expectedHeadingCount = ($sectionPlan['contains_heading_1'] ?? false) === true ? 1 : 0;
        $violations = [];
        if ($facts['heading_1'] !== $expectedHeadingCount) {
            $violations[] = "heading-1 expected {$expectedHeadingCount}, received {$facts['heading_1']}";
        }
        if ($facts['nodes'] > 60) {
            $violations[] = "node count {$facts['nodes']} exceeded 60";
        }
        if ($violations !== []) {
            throw new InvalidWireframeException(sprintf(
                'Section [%s] did not satisfy its bounded semantic requirements: %s.',
                (string) ($sectionPlan['id'] ?? 'unknown'),
                implode('; ', $violations),
            ));
        }

        return $section;
    }

    /** @param array<string,mixed> $plan @param array<string,array<string,mixed>> $sectionResults @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    public function assembleSections(array $plan, array $sectionResults, array $siteAst, array $brief, string $locale): array
    {
        $navigation = is_array($siteAst['navigation'] ?? null) ? $siteAst['navigation'] : [];
        $organization = is_string($brief['organization'] ?? null) ? $brief['organization'] : 'Organization';
        $pages = [];
        foreach ($plan['pages'] as $pagePlan) {
            $pageKey = (string) $pagePlan['key'];
            $sections = [];
            foreach ($pagePlan['sections'] as $sectionPlan) {
                $taskKey = $pageKey.'::'.$sectionPlan['id'];
                if (! is_array($sectionResults[$taskKey] ?? null)) {
                    throw new InvalidWireframeException('A planned wireframe section is missing.');
                }
                $sections[] = $sectionResults[$taskKey];
            }
            $headerChildren = [[
                'type' => 'Text', 'id' => "chrome-{$pageKey}-brand", 'role' => 'label', 'content' => $organization,
            ]];
            $links = [];
            foreach ($navigation as $index => $item) {
                if (! is_array($item) || ! is_string($item['label'] ?? null) || ! is_string($item['path'] ?? null)) {
                    continue;
                }
                $links[] = [
                    'type' => 'Link', 'id' => "chrome-{$pageKey}-nav-link-{$index}",
                    'label' => $item['label'], 'href' => $item['path'], 'emphasis' => 'plain',
                ];
            }
            if ($links !== []) {
                $headerChildren[] = [
                    'type' => 'Region', 'id' => "chrome-{$pageKey}-navigation", 'semantic' => 'navigation',
                    'layout' => 'cluster', 'journey_stage' => 'none', 'emphasis' => 'standard', 'children' => $links,
                ];
            }
            $pages[] = [
                'key' => $pageKey,
                'path' => $pagePlan['path'],
                'title' => $pagePlan['title'],
                'root' => [
                    'type' => 'Region', 'id' => "chrome-{$pageKey}-document", 'semantic' => 'document',
                    'layout' => 'stack', 'journey_stage' => 'none', 'emphasis' => 'neutral', 'children' => [[
                        'type' => 'Region', 'id' => "chrome-{$pageKey}-header", 'semantic' => 'header',
                        'layout' => 'cluster', 'journey_stage' => 'none', 'emphasis' => 'supporting', 'children' => $headerChildren,
                    ], [
                        'type' => 'Region', 'id' => "chrome-{$pageKey}-main", 'semantic' => 'main',
                        'layout' => 'stack', 'journey_stage' => 'none', 'emphasis' => 'neutral', 'children' => $sections,
                    ], [
                        'type' => 'Region', 'id' => "chrome-{$pageKey}-footer", 'semantic' => 'footer',
                        'layout' => 'cluster', 'journey_stage' => 'none', 'emphasis' => 'supporting', 'children' => [[
                            'type' => 'Text', 'id' => "chrome-{$pageKey}-footer-note", 'role' => 'small', 'content' => $organization,
                        ]],
                    ]],
                ],
            ];
        }

        return ['version' => 2, 'locale' => $locale, 'pages' => $pages];
    }

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @param array<string,mixed> $assembled @return array<string,mixed> */
    public function startReviewBackground(array $siteAst, array $brief, string $locale, array $assembled, string $executionProfile): array
    {
        return $this->startStructuredBackground(
            $this->reviewSchema(),
            $this->reviewInstructions(),
            ['site_ast' => $siteAst, 'brief' => $brief, 'locale' => $locale, 'assembled_wireframe_ast' => $assembled],
            $executionProfile,
            'wireframe_review',
            8000,
        );
    }

    /** @param array<string,mixed> $providerResponse @param array<string,mixed> $assembled @param array<string,mixed> $siteAst @return array{wireframe:array<string,mixed>,review:array<string,int>} */
    public function completeReviewBackground(array $providerResponse, array $assembled, array $siteAst, string $locale): array
    {
        $review = $this->completedDocument($providerResponse);
        if (($review['version'] ?? null) !== 1
            || ! is_array($review['findings'] ?? null)
            || ! is_array($review['operations'] ?? null)) {
            throw new InvalidWireframeException('The whole-site review is invalid.');
        }
        $wireframe = $assembled;
        $appliedOperations = 0;
        $skippedOperations = 0;
        foreach ($review['operations'] as $operation) {
            if (! is_array($operation)) {
                $skippedOperations++;

                continue;
            }
            try {
                $wireframe = $this->applyReviewOperation($wireframe, $operation);
                $appliedOperations++;
            } catch (InvalidWireframeException) {
                $skippedOperations++;
            }
        }
        $wireframe = $this->normalizeProviderDocument($wireframe, 2);
        $wireframe = $this->validator->validate($wireframe, $siteAst, 2, $locale);

        return [
            'wireframe' => $wireframe,
            'review' => [
                'finding_count' => count($review['findings']),
                'operation_count' => $appliedOperations,
                'skipped_operation_count' => $skippedOperations,
            ],
        ];
    }

    /** @param array<string,mixed> $schema @param array<string,mixed> $input @return array<string,mixed> */
    private function startStructuredBackground(array $schema, string $instructions, array $input, string $executionProfile, string $stage, int $maxOutputTokens): array
    {
        $profile = $this->executionProfiles->resolve($executionProfile);
        $response = $this->providerRequest()->post((string) config('services.openai.url'), [
            'model' => $profile['model'],
            'instructions' => $instructions,
            'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reasoning' => ['effort' => $profile['reasoning_effort']],
            'text' => ['format' => [
                'type' => 'json_schema', 'name' => $stage, 'strict' => true, 'schema' => $schema,
            ]],
            'max_output_tokens' => $maxOutputTokens,
            'background' => true,
            'store' => false,
            'metadata' => ['stage' => $stage, 'execution_profile' => $profile['id']],
        ]);
        $this->assertSuccessfulProviderResponse($response);
        $providerResponse = $response->json();
        if (! is_array($providerResponse)
            || $this->identifier($providerResponse['id'] ?? null) === null
            || ! in_array($providerResponse['status'] ?? null, ['queued', 'in_progress', 'completed'], true)) {
            throw new RuntimeException('The wireframe provider returned an invalid background job.');
        }

        return $providerResponse;
    }

    /** @param array<string,mixed> $providerResponse @return array<string,mixed> */
    private function completedDocument(array $providerResponse): array
    {
        if (($providerResponse['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The wireframe provider response is not complete.');
        }
        $text = is_string($providerResponse['output_text'] ?? null)
            ? $providerResponse['output_text']
            : $this->outputText((array) ($providerResponse['output'] ?? []));
        $document = json_decode($text, true);
        if (! is_array($document)) {
            throw new RuntimeException('The wireframe provider returned invalid JSON.');
        }

        return $document;
    }

    /** @return array<string,mixed> */
    private function planSchema(): array
    {
        $text = fn (int $max): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => $max];
        $object = fn (array $properties): array => [
            'type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false,
        ];
        $section = $object([
            'id' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$', 'maxLength' => 100],
            'purpose' => $text(1000),
            'journey_stage' => ['type' => 'string', 'enum' => ['none', 'attention', 'interest', 'desire', 'memory', 'action']],
            'layout' => ['type' => 'string', 'enum' => self::layouts()],
            'emphasis' => ['type' => 'string', 'enum' => ['neutral', 'supporting', 'standard', 'strong', 'primary']],
            'contains_heading_1' => ['type' => 'boolean'],
            'requires_form' => ['type' => 'boolean'],
            'requires_image' => ['type' => 'boolean'],
        ]);
        $page = $object([
            'key' => $text(100),
            'path' => $text(200),
            'title' => $text(200),
            'sections' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 8, 'items' => $section],
        ]);

        return $object([
            'version' => ['type' => 'integer', 'const' => 1],
            'pages' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => $page],
        ]);
    }

    /** @param array<string,mixed> $pagePlan @param array<string,mixed> $sectionPlan @return array<string,mixed> */
    private function sectionSchema(array $pagePlan, array $sectionPlan): array
    {
        $schema = $this->v2Schema();
        $definitions = $schema['$defs'];
        $definitions['sectionRegion2']['properties']['id'] = ['type' => 'string', 'const' => $sectionPlan['id']];
        $definitions['sectionRegion2']['properties']['semantic'] = ['type' => 'string', 'const' => 'section'];
        $definitions['sectionRegion2']['properties']['layout'] = ['type' => 'string', 'const' => $sectionPlan['layout']];
        $definitions['sectionRegion2']['properties']['journey_stage'] = ['type' => 'string', 'const' => $sectionPlan['journey_stage']];
        $definitions['sectionRegion2']['properties']['emphasis'] = ['type' => 'string', 'const' => $sectionPlan['emphasis']];
        if (($sectionPlan['contains_heading_1'] ?? false) !== true) {
            $definitions['text']['properties']['role']['enum'] = array_values(array_filter(
                $definitions['text']['properties']['role']['enum'],
                static fn (string $role): bool => $role !== 'heading-1',
            ));
        }

        return [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'integer', 'const' => 1],
                'page_key' => ['type' => 'string', 'const' => $pagePlan['key']],
                'section_id' => ['type' => 'string', 'const' => $sectionPlan['id']],
                'section' => ['$ref' => '#/$defs/sectionRegion2'],
            ],
            'required' => ['version', 'page_key', 'section_id', 'section'],
            'additionalProperties' => false,
            '$defs' => $definitions,
        ];
    }

    /** @return array<string,mixed> */
    private function reviewSchema(): array
    {
        $text = fn (int $max): array => ['type' => 'string', 'minLength' => 1, 'maxLength' => $max];
        $object = fn (array $properties): array => [
            'type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false,
        ];
        $finding = $object([
            'code' => ['type' => 'string', 'enum' => ['hierarchy', 'journey', 'duplication', 'clarity', 'action', 'accessibility', 'consistency']],
            'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'critical']],
            'page_key' => $text(100),
            'node_id' => ['type' => ['string', 'null'], 'maxLength' => 100],
            'detail' => $text(500),
        ]);
        $replaceCopy = $object([
            'op' => ['type' => 'string', 'const' => 'replace_copy'],
            'page_key' => $text(100),
            'node_id' => $text(100),
            'property' => ['type' => 'string', 'enum' => ['content', 'label', 'alt', 'caption']],
            'value' => $text(2000),
        ]);
        $replaceHref = $object([
            'op' => ['type' => 'string', 'const' => 'replace_href'],
            'page_key' => $text(100),
            'node_id' => $text(100),
            'href' => $text(500),
        ]);
        $setRole = $object([
            'op' => ['type' => 'string', 'const' => 'set_text_role'],
            'page_key' => $text(100),
            'node_id' => $text(100),
            'role' => ['type' => 'string', 'enum' => ['eyebrow', 'heading-1', 'heading-2', 'heading-3', 'body', 'small', 'label', 'price', 'step-number', 'summary']],
        ]);
        $reorder = $object([
            'op' => ['type' => 'string', 'const' => 'reorder_sections'],
            'page_key' => $text(100),
            'section_ids' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 8, 'items' => $text(100)],
        ]);

        return $object([
            'version' => ['type' => 'integer', 'const' => 1],
            'findings' => ['type' => 'array', 'maxItems' => 40, 'items' => $finding],
            'operations' => ['type' => 'array', 'maxItems' => 40, 'items' => ['anyOf' => [$replaceCopy, $replaceHref, $setRole, $reorder]]],
        ]);
    }

    private function planningInstructions(): string
    {
        return 'Create a bounded whole-site wireframe section plan. Preserve every Site AST page key, path, and title exactly once. Plan 2 to 8 sections per page and no more than 24 sections for the whole request. Each page assigns exactly one contains_heading_1=true section. Use AIDMA and the supplied brief to determine section order, purpose, layout, emphasis, form need, and image need. If the goal or requirements ask for contact, inquiry, request, application, or a form, at least one section must set requires_form=true. Section IDs are unique lowercase kebab-case, do not start with chrome-, and do not contain the token image. Do not write the section body yet.';
    }

    private function sectionInstructions(): string
    {
        return 'Generate only the current semantic section while using the complete Site AST, brief, site plan, and page plan as context. Preserve the planned section ID, layout, journey stage, and emphasis exactly. Produce concrete customer-facing copy and typed leaves. Honor contains_heading_1 exactly: one heading-1 when true and none when false. When requires_form is true, include a semantic form with introductory content, labeled controls, consent or helper copy when appropriate, and exactly one submit button. When requires_image is true, include at least one Image leaf with useful intent and alt text. Keep the section under 60 nodes and avoid repeating content owned by other planned sections. When validation_feedback is present, repair exactly those stated violations without weakening the plan. Do not output HTML, CSS, visual skin, remote URLs, or explanations.';
    }

    private function reviewInstructions(): string
    {
        return 'Review the fully assembled Wireframe AST as a senior information architect. Check cross-section hierarchy, AIDMA progression, repeated claims, CTA rhythm, exact local links, heading roles, form clarity, image intent, and consistency across pages. Return findings and only bounded safe operations; never regenerate the whole AST. Use reorder_sections for page-level rhythm, replace_copy for copy corrections, replace_href for exact internal references, and set_text_role for heading hierarchy. Do not change IDs, add or delete nodes, add style, or invent facts. Return no operation when the assembled value is already correct.';
    }

    /** @param array<string,mixed> $wireframe @param array<string,mixed> $operation @return array<string,mixed> */
    private function applyReviewOperation(array $wireframe, array $operation): array
    {
        $pageKey = $operation['page_key'] ?? null;
        $pageIndex = null;
        foreach ($wireframe['pages'] ?? [] as $index => $page) {
            if (is_array($page) && ($page['key'] ?? null) === $pageKey) {
                $pageIndex = $index;
                break;
            }
        }
        if ($pageIndex === null) {
            throw new InvalidWireframeException('A review operation references an unknown page.');
        }
        if (($operation['op'] ?? null) === 'reorder_sections') {
            $mainIndex = null;
            foreach ($wireframe['pages'][$pageIndex]['root']['children'] as $index => $child) {
                if (($child['semantic'] ?? null) === 'main') {
                    $mainIndex = $index;
                    break;
                }
            }
            if ($mainIndex === null) {
                throw new InvalidWireframeException('A review operation could not locate main.');
            }
            $sections = $wireframe['pages'][$pageIndex]['root']['children'][$mainIndex]['children'];
            $byId = [];
            foreach ($sections as $section) {
                $byId[$section['id']] = $section;
            }
            $sectionIds = $operation['section_ids'] ?? null;
            if (! is_array($sectionIds) || count($sectionIds) !== count($byId) || array_diff($sectionIds, array_keys($byId)) !== []) {
                throw new InvalidWireframeException('A review section order must contain every section exactly once.');
            }
            $wireframe['pages'][$pageIndex]['root']['children'][$mainIndex]['children'] = array_map(
                static fn (string $id): array => $byId[$id],
                $sectionIds,
            );

            return $wireframe;
        }

        $nodeId = $operation['node_id'] ?? null;
        $matched = 0;
        $mutate = function (array $node) use (&$mutate, &$matched, $nodeId, $operation): array {
            if (($node['id'] ?? null) === $nodeId) {
                $matched++;
                $op = $operation['op'] ?? null;
                if ($op === 'replace_copy') {
                    $property = $operation['property'] ?? null;
                    if (! is_string($property) || ! array_key_exists($property, $node) || ! is_string($node[$property] ?? null)) {
                        throw new InvalidWireframeException('A review copy operation targets an incompatible node.');
                    }
                    $node[$property] = $operation['value'];
                } elseif ($op === 'replace_href' && ($node['type'] ?? null) === 'Link') {
                    $node['href'] = $operation['href'];
                } elseif ($op === 'set_text_role' && ($node['type'] ?? null) === 'Text') {
                    $node['role'] = $operation['role'];
                } else {
                    throw new InvalidWireframeException('A review operation targets an incompatible node.');
                }
            }
            foreach ($node['children'] ?? [] as $index => $child) {
                if (is_array($child)) {
                    $node['children'][$index] = $mutate($child);
                }
            }

            return $node;
        };
        $wireframe['pages'][$pageIndex]['root'] = $mutate($wireframe['pages'][$pageIndex]['root']);
        if ($matched !== 1) {
            throw new InvalidWireframeException('A review operation must target exactly one node.');
        }

        return $wireframe;
    }

    private function providerRequest(): PendingRequest
    {
        $request = Http::withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout', 300))
            ->connectTimeout((int) config('services.openai.connect_timeout', 10));
        $retryDelays = $this->retryDelays();

        return $retryDelays === [] ? $request : $request->retry($retryDelays, throw: false);
    }

    /** @param array<string,mixed> $siteAst @param array<string,mixed> $brief @return array<string,mixed> */
    private function providerPayload(
        array $siteAst,
        array $brief,
        string $locale,
        int $wireframeAstVersion,
        ?string $feedback,
        bool $background,
        array $profile,
    ): array {
        return [
            'model' => $profile['model'],
            'instructions' => $this->instructions($wireframeAstVersion),
            'input' => json_encode([
                'site_ast' => $siteAst,
                'brief' => $brief,
                'locale' => $locale,
                'wireframe_ast_version' => $wireframeAstVersion,
                'validation_feedback' => $feedback,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reasoning' => ['effort' => $profile['reasoning_effort']],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'wireframes',
                'strict' => true,
                'schema' => $this->schema($wireframeAstVersion),
            ]],
            'max_output_tokens' => $wireframeAstVersion === 2 ? 64000 : 12000,
            'background' => $background,
            'store' => false,
            'metadata' => ['stage' => 'wireframes', 'execution_profile' => $profile['id']],
        ];
    }

    private function assertSuccessfulProviderResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        Log::warning('Wireframe provider rejected request.', [
            'provider' => 'openai',
            'model' => $this->identifier(config('services.openai.model')),
            'status' => $response->status(),
            'request_id' => $this->identifier($response->header('x-request-id')),
            'error_type' => $this->identifier($response->json('error.type')),
            'error_code' => $this->identifier($response->json('error.code')),
            'error_param' => $this->identifier($response->json('error.param')),
        ]);

        throw new RuntimeException('The wireframe provider rejected the request.');
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)
            || mb_strlen($value) > 200
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:@+\/-]*\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function schema(int $wireframeAstVersion): array
    {
        if ($wireframeAstVersion === 2) {
            return $this->v2Schema();
        }

        $text = fn (int $max): array => ['type' => 'string', 'maxLength' => $max];
        $object = fn (array $properties, array $required): array => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ];

        return $object([
            'pages' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $object([
                'key' => $text(100),
                'sections' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 8, 'items' => $object([
                    'key' => $text(100),
                    'composition' => ['type' => 'string', 'enum' => ['hero', 'feature-grid', 'content', 'steps', 'testimonials', 'faq', 'cta', 'contact']],
                    'roles' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => ['Eyebrow', 'Title', 'Text', 'Items', 'Actions', 'Image']]],
                ], ['key', 'composition', 'roles'])],
            ], ['key', 'sections'])],
        ], ['pages']);
    }

    private function instructions(int $wireframeAstVersion): string
    {
        if ($wireframeAstVersion === 1) {
            return 'You are a semantic wireframe architect. Preserve every Site AST page key exactly once. Each page has 2 to 8 unique sections. Describe information hierarchy only. Do not write copy, HTML, CSS, colors, dimensions, selectors, or asset URLs.';
        }

        return <<<'PROMPT'
You are a senior information architect and conversion-focused wireframe director. Produce Wireframe AST version 2 in the requested locale, grounded only in the supplied Site AST, brief, and materials. Preserve every Site AST page key, path, and title exactly once. Layout-only means skin-free, not content-free: write the real customer-facing copy required to judge hierarchy and conversion. You may reorganize content while preserving factual constraints.

Use an AIDMA-aware order on conversion pages: attention, interest, desire, memory, then action. Vary rhythm with split, grid, stack, timeline-like lists, FAQ, and form structures. Put a clear promise, primary action, and trust facts above the fold; place objections and decision information before the final action. Use three deliberate CTA moments when appropriate. Every page needs exactly one heading-1 and 2 to 12 section Regions. The request may contain at most 8 pages. Keep the generated document concise: target at most 120 nodes per page and 400 nodes total, never nest Region nodes more than five levels deep, and keep each body Text node under 400 characters. Remove repetitive copy and duplicate groups before adding more nodes.

Only Region may branch. Every branch must end in a concrete Text, Image, Link, Button, Input, Textarea, Select, or Checkbox leaf. Use actual labels, targets, image intent/alt text, form fields, options, helper copy, and consent links. Node IDs must agree with their declared type: any ID containing the token image is an Image node, never descriptive Text. Do not emit generic Item, Action, Title, Text, Image, Section, or placeholder-only content. Use semantic form only for an actual interactive form. Its schema deliberately separates introductory content, one-or-more labeled controls, and exactly one submit Button; populate all three faithfully. A visual inquiry section without controls is a group, never a form. Use ordered-list or unordered-list only when every direct child is a list-item Region. Images describe placement and intent only and never contain URLs.

Do not output HTML, CSS, classes, selectors, colors, dimensions, fonts, decoration, remote URLs, scripts, or vendor components. The renderer owns a fixed neutral wireframe decoration. Node IDs are internal lowercase kebab-case identifiers and must never be written into visible copy. Links must use a resolvable local #anchor or a site-relative /path. Keep claims, prices, hours, guarantees, legal statements, and contact constraints faithful to the source materials.
PROMPT;
    }

    /** @return array<string,mixed> */
    private function v2Schema(): array
    {
        $text = fn (int $max, int $min = 1): array => ['type' => 'string', 'minLength' => $min, 'maxLength' => $max];
        $nullableText = fn (int $max): array => ['type' => ['string', 'null'], 'maxLength' => $max];
        $object = fn (array $properties, array $required): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $id = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*$', 'maxLength' => 100];
        $fieldName = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$', 'maxLength' => 100];

        $regionProperties = fn (array $semanticSchema): array => [
            'type' => ['type' => 'string', 'const' => 'Region'],
            'id' => $id,
            'semantic' => $semanticSchema,
            'layout' => ['type' => 'string', 'enum' => self::layouts()],
            'journey_stage' => ['type' => 'string', 'enum' => ['none', 'attention', 'interest', 'desire', 'memory', 'action']],
            'emphasis' => ['type' => 'string', 'enum' => ['neutral', 'supporting', 'standard', 'strong', 'primary']],
        ];

        $definitions = [];
        $definitions['formRegion'] = $object([...$regionProperties(
            ['type' => 'string', 'const' => 'form'],
        ),
            'content' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ['$ref' => '#/$defs/formContentNode']],
            'controls' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => ['$ref' => '#/$defs/formControlNode']],
            'submit' => ['$ref' => '#/$defs/submitButton'],
        ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'content', 'controls', 'submit']);
        $definitions['text'] = $object([
            'type' => ['type' => 'string', 'const' => 'Text'],
            'id' => $id,
            'role' => ['type' => 'string', 'enum' => ['eyebrow', 'heading-1', 'heading-2', 'heading-3', 'body', 'small', 'label', 'price', 'step-number', 'summary']],
            'content' => $text(8000),
        ], ['type', 'id', 'role', 'content']);
        $definitions['image'] = $object([
            'type' => ['type' => 'string', 'const' => 'Image'],
            'id' => $id,
            'alt' => $text(500),
            'caption' => $nullableText(1000),
            'aspect' => ['type' => 'string', 'enum' => ['16:9', '4:3', '3:2', '1:1', '2:3']],
        ], ['type', 'id', 'alt', 'caption', 'aspect']);
        $definitions['link'] = $object([
            'type' => ['type' => 'string', 'const' => 'Link'],
            'id' => $id,
            'label' => $text(300),
            'href' => $text(500),
            'emphasis' => ['type' => 'string', 'enum' => ['plain', 'secondary', 'primary']],
        ], ['type', 'id', 'label', 'href', 'emphasis']);
        $definitions['button'] = $object([
            'type' => ['type' => 'string', 'const' => 'Button'],
            'id' => $id,
            'label' => $text(300),
            'button_type' => ['type' => 'string', 'const' => 'button'],
            'emphasis' => ['type' => 'string', 'enum' => ['secondary', 'primary']],
        ], ['type', 'id', 'label', 'button_type', 'emphasis']);
        $definitions['submitButton'] = $object([
            'type' => ['type' => 'string', 'const' => 'Button'],
            'id' => $id,
            'label' => $text(300),
            'button_type' => ['type' => 'string', 'const' => 'submit'],
            'emphasis' => ['type' => 'string', 'enum' => ['secondary', 'primary']],
        ], ['type', 'id', 'label', 'button_type', 'emphasis']);
        $definitions['input'] = $object([
            'type' => ['type' => 'string', 'const' => 'Input'],
            'id' => $id,
            'input_type' => ['type' => 'string', 'enum' => ['text', 'email', 'tel', 'url']],
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'input_type', 'label', 'name', 'placeholder', 'required']);
        $definitions['textarea'] = $object([
            'type' => ['type' => 'string', 'const' => 'Textarea'],
            'id' => $id,
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'label', 'name', 'placeholder', 'required']);
        $definitions['option'] = $object([
            'label' => $text(200),
            'value' => $text(100),
        ], ['label', 'value']);
        $definitions['select'] = $object([
            'type' => ['type' => 'string', 'const' => 'Select'],
            'id' => $id,
            'label' => $text(200),
            'name' => $fieldName,
            'placeholder' => $nullableText(300),
            'required' => ['type' => 'boolean'],
            'options' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['$ref' => '#/$defs/option']],
        ], ['type', 'id', 'label', 'name', 'placeholder', 'required', 'options']);
        $definitions['checkbox'] = $object([
            'type' => ['type' => 'string', 'const' => 'Checkbox'],
            'id' => $id,
            'label' => $text(300),
            'name' => $fieldName,
            'value' => $text(100),
            'required' => ['type' => 'boolean'],
        ], ['type', 'id', 'label', 'name', 'value', 'required']);
        $definitions['formContentNode'] = ['anyOf' => array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['text', 'image', 'link', 'button'],
        )];
        $definitions['formControlNode'] = ['anyOf' => array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['input', 'textarea', 'select', 'checkbox'],
        )];

        $leafReferences = array_map(
            fn (string $name): array => ['$ref' => "#/\$defs/{$name}"],
            ['text', 'image', 'link', 'button'],
        );
        $definitions['node5'] = ['anyOf' => $leafReferences];
        $branchingSemantics = ['navigation', 'article', 'aside', 'group'];
        $childLimits = [2 => 24, 3 => 16, 4 => 12];
        for ($depth = 4; $depth >= 2; $depth--) {
            $nextDepth = $depth + 1;
            $definitions["region{$depth}"] = $object([
                ...$regionProperties(['type' => 'string', 'enum' => $branchingSemantics]),
                'children' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => $childLimits[$depth],
                    'items' => ['$ref' => "#/\$defs/node{$nextDepth}"],
                ],
            ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
            $definitions["listItemRegion{$depth}"] = $object([
                ...$regionProperties(['type' => 'string', 'const' => 'list-item']),
                'children' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => ['$ref' => "#/\$defs/node{$nextDepth}"],
                ],
            ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
            $definitions["listRegion{$depth}"] = $object([
                ...$regionProperties(['type' => 'string', 'enum' => ['ordered-list', 'unordered-list']]),
                'children' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => ['$ref' => "#/\$defs/listItemRegion{$depth}"],
                ],
            ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
            $definitions["node{$depth}"] = ['anyOf' => [
                ['$ref' => "#/\$defs/region{$depth}"],
                ['$ref' => "#/\$defs/listRegion{$depth}"],
                ['$ref' => '#/$defs/formRegion'],
                ...$leafReferences,
            ]];
        }
        $definitions['sectionRegion2'] = $object([
            ...$regionProperties(['type' => 'string', 'const' => 'section']),
            'children' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 24,
                'items' => ['$ref' => '#/$defs/node3'],
            ],
        ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        foreach (['header', 'footer'] as $semantic) {
            $definitions["{$semantic}Region1"] = $object([
                ...$regionProperties(['type' => 'string', 'const' => $semantic]),
                'children' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => ['$ref' => '#/$defs/node2'],
                ],
            ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        }
        $definitions['mainRegion1'] = $object([
            ...$regionProperties(['type' => 'string', 'const' => 'main']),
            'children' => [
                'type' => 'array',
                'minItems' => 2,
                'maxItems' => 12,
                'items' => ['$ref' => '#/$defs/sectionRegion2'],
            ],
        ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);
        $definitions['node1'] = ['anyOf' => [
            ['$ref' => '#/$defs/headerRegion1'],
            ['$ref' => '#/$defs/mainRegion1'],
            ['$ref' => '#/$defs/footerRegion1'],
        ]];
        $definitions['region0'] = $object([
            ...$regionProperties(['type' => 'string', 'const' => 'document']),
            'children' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 3,
                'items' => ['$ref' => '#/$defs/node1'],
            ],
        ], ['type', 'id', 'semantic', 'layout', 'journey_stage', 'emphasis', 'children']);

        return [
            ...$object([
                'version' => ['type' => 'integer', 'const' => 2],
                'locale' => $text(20, 2),
                'pages' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ['$ref' => '#/$defs/page']],
            ], ['version', 'locale', 'pages']),
            '$defs' => [
                ...$definitions,
                'page' => $object([
                    'key' => $text(100),
                    'path' => $text(200),
                    'title' => $text(200),
                    'root' => ['$ref' => '#/$defs/region0'],
                ], ['key', 'path', 'title', 'root']),
            ],
        ];
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function normalizeProviderDocument(array $document, int $wireframeAstVersion): array
    {
        if ($wireframeAstVersion !== 2 || ! is_array($document['pages'] ?? null)) {
            return $document;
        }
        foreach ($document['pages'] as $pageIndex => $page) {
            if (is_array($page) && is_array($page['root'] ?? null)) {
                $root = $this->normalizeProviderNode($page['root']);
                $document['pages'][$pageIndex]['root'] = $this->normalizeProviderAnchors($root);
            }
        }

        return $document;
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function normalizeProviderNode(array $node): array
    {
        $id = is_string($node['id'] ?? null) ? $node['id'] : '';
        if (($node['type'] ?? null) === 'Text'
            && in_array('image', explode('-', $id), true)) {
            $content = is_string($node['content'] ?? null) && trim($node['content']) !== ''
                ? trim($node['content'])
                : '内容を視覚的に補足する画像';

            return [
                'type' => 'Image',
                'id' => $id,
                'intent' => $content,
                'alt' => $content,
                'aspect_ratio' => '16:9',
            ];
        }
        if (($node['type'] ?? null) !== 'Region') {
            return $node;
        }
        if (($node['semantic'] ?? null) === 'form'
            && is_array($node['content'] ?? null)
            && is_array($node['controls'] ?? null)
            && is_array($node['submit'] ?? null)) {
            $node['children'] = [...$node['content'], ...$node['controls'], $node['submit']];
            unset($node['content'], $node['controls'], $node['submit']);
        }
        if (is_array($node['children'] ?? null)) {
            foreach ($node['children'] as $childIndex => $child) {
                if (is_array($child)) {
                    $node['children'][$childIndex] = $this->normalizeProviderNode($child);
                }
            }
        }

        return $node;
    }

    /** @param array<string,mixed> $root @return array<string,mixed> */
    private function normalizeProviderAnchors(array $root): array
    {
        $ids = [];
        $collectIds = function (array $node) use (&$collectIds, &$ids): void {
            if (is_string($node['id'] ?? null)) {
                $ids[$node['id']] = true;
            }
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $collectIds($child);
                }
            }
        };
        $collectIds($root);

        $rewrite = function (array $node) use (&$rewrite, $ids): array {
            $href = $node['href'] ?? null;
            if (($node['type'] ?? null) === 'Link'
                && is_string($href)
                && preg_match('/\A#([a-z0-9][a-z0-9-]*)\z/D', $href, $matches) === 1
                && ! isset($ids[$matches[1]])) {
                $candidate = $this->unambiguousAnchorCandidate($matches[1], $ids);
                if ($candidate !== null) {
                    $node['href'] = "#{$candidate}";
                }
            }
            foreach ($node['children'] ?? [] as $index => $child) {
                if (is_array($child)) {
                    $node['children'][$index] = $rewrite($child);
                }
            }

            return $node;
        };

        return $rewrite($root);
    }

    /** @param array<string,bool> $ids */
    private function unambiguousAnchorCandidate(string $anchor, array $ids): ?string
    {
        $section = "{$anchor}-section";
        if (isset($ids[$section])) {
            return $section;
        }
        $candidates = array_values(array_filter(
            array_keys($ids),
            static fn (string $id): bool => str_starts_with($id, "{$anchor}-"),
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @return list<string> */
    private static function layouts(): array
    {
        return [
            'stack', 'cluster', 'grid-2', 'grid-3', 'grid-4', 'split', 'split-wide-start',
            'split-wide-end', 'centered',
        ];
    }

    /** @param array<int,mixed> $output */
    private function outputText(array $output): string
    {
        foreach ($output as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return '';
    }

    /** @return list<int> */
    private function retryDelays(): array
    {
        $delays = config('services.openai.retry_delays_ms', []);
        if (! is_array($delays)) {
            return [];
        }

        return array_values(array_filter($delays, fn (mixed $delay): bool => is_int($delay) && $delay >= 0 && $delay <= 60_000));
    }
}
