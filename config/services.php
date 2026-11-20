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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'fastapi' => [
        'url' => env('FASTAPI_URL', 'http://127.0.0.1:8001'),
    ],

    'review' => [
        'daily_quota' => (int) env('DAILY_REVIEW_QUOTA', 20),
        // 0 = no file-count cap
        'max_files' => (int) env('REVIEW_MAX_FILES', 0),
        'max_file_bytes' => (int) env('REVIEW_MAX_FILE_BYTES', 1048576),
        'max_depth' => (int) env('REVIEW_MAX_DEPTH', 8),
        'allowed_roots' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REVIEW_ALLOWED_ROOTS', 'D:\\laragon\\www,D:\\ai-learning'))
        ))),
        'linters' => [
            'phpcs' => env('REVIEW_LINTER_PHPCS', 'phpcs'),
            'ruff' => env('REVIEW_LINTER_RUFF', 'ruff'),
            'flake8' => env('REVIEW_LINTER_FLAKE8', 'flake8'),
            'eslint' => env('REVIEW_LINTER_ESLINT', 'eslint'),
            'timeout_seconds' => (int) env('REVIEW_LINTER_TIMEOUT', 15),
        ],
    ],

];
