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

    'wordpress' => [
        'site_url' => env('WP_BASE_URL', 'https://lppm.unila.ac.id'),
        'connection' => env('WP_DB_CONNECTION', 'wordpress'),
        'prefix' => env('DB_WP_PREFIX', ''),
        'uploads_root' => env('LEGACY_UPLOADS_ROOT', ''),
        'uploads_base_url' => env('LEGACY_UPLOADS_BASE_URL', ''),
        'document_roots' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LEGACY_DOCUMENT_ROOTS', ''))
        ))),
        // New Download Manager-compatible files are written only to this
        // explicitly configured root. When omitted locally, the first audited
        // document root is used; production should set it explicitly.
        'document_upload_root' => env('LEGACY_DOCUMENT_UPLOAD_ROOT', ''),
        'admin_read_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WP_ADMIN_READ_ROLES', 'administrator'))
        ))),
        'admin_token_ttl_minutes' => (int) env('WP_ADMIN_TOKEN_TTL_MINUTES', 120),
        // Scheduling is opt-in outside local development until the WordPress
        // content timezone and the hosting cron have passed UAT.
        'scheduling_enabled' => filter_var(
            env('CMS_SCHEDULING_ENABLED', env('APP_ENV', 'production') === 'local'),
            FILTER_VALIDATE_BOOL
        ),
    ],

];
