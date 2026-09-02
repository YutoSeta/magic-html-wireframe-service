<?php

return [

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_RESPONSES_URL', 'https://api.openai.com/v1/responses'),
        'model' => env('OPENAI_MODEL', 'gpt-5.6-sol'),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'medium'),
        'default_execution_profile' => env('OPENAI_EXECUTION_PROFILE', 'fast'),
        'execution_profiles' => [
            'fast' => [
                'model' => env('OPENAI_FAST_MODEL', 'gpt-5.6-luna'),
                'reasoning_effort' => env('OPENAI_FAST_REASONING_EFFORT', 'low'),
            ],
            'balanced' => [
                'model' => env('OPENAI_BALANCED_MODEL', 'gpt-5.6-terra'),
                'reasoning_effort' => env('OPENAI_BALANCED_REASONING_EFFORT', 'medium'),
            ],
            'quality' => [
                'model' => env('OPENAI_QUALITY_MODEL', 'gpt-5.6-sol'),
                'reasoning_effort' => env('OPENAI_QUALITY_REASONING_EFFORT', 'high'),
            ],
        ],
        'timeout' => (int) env('OPENAI_TIMEOUT', 300),
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 10),
        'retry_delays_ms' => [1000, 3000, 7000],
        'rate_card' => [
            'version' => env('OPENAI_RATE_CARD_VERSION', '2026-08-31'),
            'effective_at' => env('OPENAI_RATE_CARD_EFFECTIVE_AT', '2026-08-31'),
            'source' => env('OPENAI_RATE_CARD_SOURCE', 'https://developers.openai.com/api/docs/models/gpt-5.6-sol'),
            'currency' => env('OPENAI_RATE_CARD_CURRENCY', 'USD'),
            'models' => [
                'gpt-5.6-luna' => [
                    'snapshot_prefixes' => ['gpt-5.6-luna-'],
                    'input_per_million' => (float) env('OPENAI_GPT_5_6_LUNA_INPUT_PER_MILLION', 0.2),
                    'cached_input_per_million' => (float) env('OPENAI_GPT_5_6_LUNA_CACHED_INPUT_PER_MILLION', 0.02),
                    'output_per_million' => (float) env('OPENAI_GPT_5_6_LUNA_OUTPUT_PER_MILLION', 1.2),
                    'long_context_threshold_tokens' => (int) env('OPENAI_GPT_5_6_LUNA_LONG_CONTEXT_THRESHOLD', 272000),
                    'long_context_input_multiplier' => (float) env('OPENAI_GPT_5_6_LUNA_LONG_CONTEXT_INPUT_MULTIPLIER', 2),
                    'long_context_output_multiplier' => (float) env('OPENAI_GPT_5_6_LUNA_LONG_CONTEXT_OUTPUT_MULTIPLIER', 1.5),
                ],
                'gpt-5.6-terra' => [
                    'snapshot_prefixes' => ['gpt-5.6-terra-'],
                    'input_per_million' => (float) env('OPENAI_GPT_5_6_TERRA_INPUT_PER_MILLION', 2),
                    'cached_input_per_million' => (float) env('OPENAI_GPT_5_6_TERRA_CACHED_INPUT_PER_MILLION', 0.2),
                    'output_per_million' => (float) env('OPENAI_GPT_5_6_TERRA_OUTPUT_PER_MILLION', 12),
                    'long_context_threshold_tokens' => (int) env('OPENAI_GPT_5_6_TERRA_LONG_CONTEXT_THRESHOLD', 272000),
                    'long_context_input_multiplier' => (float) env('OPENAI_GPT_5_6_TERRA_LONG_CONTEXT_INPUT_MULTIPLIER', 2),
                    'long_context_output_multiplier' => (float) env('OPENAI_GPT_5_6_TERRA_LONG_CONTEXT_OUTPUT_MULTIPLIER', 1.5),
                ],
                'gpt-5.6-sol' => [
                    'aliases' => ['gpt-5.6'],
                    'snapshot_prefixes' => ['gpt-5.6-sol-'],
                    'input_per_million' => (float) env('OPENAI_GPT_5_6_SOL_INPUT_PER_MILLION', 4),
                    'cached_input_per_million' => (float) env('OPENAI_GPT_5_6_SOL_CACHED_INPUT_PER_MILLION', 0.4),
                    'output_per_million' => (float) env('OPENAI_GPT_5_6_SOL_OUTPUT_PER_MILLION', 20),
                    'long_context_threshold_tokens' => (int) env('OPENAI_GPT_5_6_SOL_LONG_CONTEXT_THRESHOLD', 272000),
                    'long_context_input_multiplier' => (float) env('OPENAI_GPT_5_6_SOL_LONG_CONTEXT_INPUT_MULTIPLIER', 2),
                    'long_context_output_multiplier' => (float) env('OPENAI_GPT_5_6_SOL_LONG_CONTEXT_OUTPUT_MULTIPLIER', 1.5),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
