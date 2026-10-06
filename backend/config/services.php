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
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'qa_system' => [
        'url' => env('QA_SYSTEM_API_URL', 'http://qa-backend-web/api/v1'),
        'token' => env('QA_SYSTEM_API_TOKEN'),
        'email' => env('QA_SYSTEM_SERVICE_EMAIL'),
        'password' => env('QA_SYSTEM_SERVICE_PASSWORD'),
        'device_name' => env('QA_SYSTEM_DEVICE_NAME', 'maintenance-dashboard'),
        'token_ttl' => (int) env('QA_SYSTEM_TOKEN_TTL', 3300),
    ],

    'mira' => [
        'ollama_url' => env('OLLAMA_URL', 'http://host.docker.internal:11434'),
        'ollama_model' => env('OLLAMA_MODEL', 'qwen3:0.6b'),
        'telegram_token' => env('TELEGRAM_BOT_TOKEN'),
        'telegram_maintenance_chat_id' => env('TELEGRAM_MAINTENANCE_CHAT_ID', '-5398792908'),
    ],

];
