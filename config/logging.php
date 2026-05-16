<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Logging Configuration
|--------------------------------------------------------------------------
|
| Channels:
|   single   — default Laravel single-file log (dev convenience)
|   daily    — rotating file logs, 14 days retained (production default)
|   json     — structured JSON-per-line, for shipping to Loki/Datadog/etc.
|   stderr   — for containerised deployments that capture stderr
|   stack    — combines channels (default channel) — env LOG_STACK picks which
|
| For Phase 1 the default stack uses 'single' in dev, 'daily' in production.
| When we add Sentry (Batch 5/H6) it lands as an additional channel here.
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver'            => 'stack',
            'channels'          => explode(',', env('LOG_STACK', env('APP_ENV') === 'production' ? 'daily,json' : 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        // ── JSON-per-line structured logs ──────────────────────────────────
        // Each log entry is one line of JSON. Easy to aggregate, query,
        // and alert on. When you ship logs to Datadog / Grafana Loki /
        // Papertrail / etc., point them at storage/logs/json.log.
        'json' => [
            'driver'    => 'monolog',
            'handler'   => StreamHandler::class,
            'level'     => env('LOG_LEVEL', 'debug'),
            'with'      => [
                'stream' => storage_path('logs/json.log'),
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'batchMode'        => JsonFormatter::BATCH_MODE_JSON,
                'appendNewline'    => true,
                'includeStacktraces' => true,
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver'    => 'monolog',
            'level'     => env('LOG_LEVEL', 'debug'),
            'handler'   => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with'      => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
