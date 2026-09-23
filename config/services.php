<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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
        'token' => env('AWS_SESSION_TOKEN'),

        /**
         * The SNS topic SES publishes bounce and complaint feedback to. The
         * webhook rejects anything published by another topic.
         */
        'topic_arn' => env('AWS_SES_TOPIC_ARN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'enablebanking' => [
        'app_id' => env('ENABLEBANKING_APP_ID'),
        'private_key_path' => env('ENABLEBANKING_PRIVATE_KEY_PATH'),
        'redirect_url' => env('ENABLEBANKING_REDIRECT_URL'),

        /**
         * Open banking is optional. A self-hosted install without these
         * credentials still runs: manual accounts and the API-key brokers are
         * untouched, and the bank connection is simply not offered. Every
         * EnableBanking route and every screen that leads to one reads this.
         */
        'enabled' => filled(env('ENABLEBANKING_APP_ID'))
            && filled(env('ENABLEBANKING_PRIVATE_KEY_PATH'))
            && filled(env('ENABLEBANKING_REDIRECT_URL')),
    ],

    'typesafe' => [
        /**
         * TypeSafe AI's Jev, the alternative categorization backend. Only users
         * with the JevCategorization flag reach it, and without a key they stay
         * on the default provider.
         */
        'key' => env('TYPESAFE_API_KEY'),
        'model' => env('TYPESAFE_MODEL', 'jev-latest'),
        'enabled' => filled(env('TYPESAFE_API_KEY')),
    ],

    'openai' => [
        /**
         * Domain-ownership token for the ChatGPT app directory submission,
         * served at /.well-known/openai-apps-challenge.
         */
        'apps_challenge' => env('OPENAI_APPS_CHALLENGE'),
    ],

    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'ai_cohort_webhook_url' => env('DISCORD_AI_COHORT_WEBHOOK_URL'),
    ],

];
