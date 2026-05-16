<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
|
| Pointing at the SAME SQLite file the Node backend wrote to (data/verdict.db).
| This means cutover is zero-data-migration: stop Node, start Laravel, all the
| existing devices/usage/subscriptions are already there.
|
| WAL mode is critical — without it, every read blocks every write and
| performance collapses under concurrent requests. The Node backend set this
| via db.pragma('journal_mode = WAL'); Laravel does the same via the
| 'journal_mode' option below (introduced for SQLite in Laravel 11+).
*/

return [

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver'         => 'sqlite',
            'url'            => env('DB_URL'),
            'database'       => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'         => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // WAL = Write-Ahead Logging. Same value the Node backend used.
            'journal_mode'   => env('DB_JOURNAL_MODE', 'WAL'),
            // 5-second busy timeout: if another writer holds the lock,
            // SQLite waits up to 5s rather than failing immediately.
            // Maps to PRAGMA busy_timeout = 5000.
            'busy_timeout'   => 5000,
        ],

        // Other drivers kept available for the eventual Postgres/MySQL move
        // (post-scale milestone — see Batch 5/H4 from the audit). Not used
        // today but harmless to keep configured.
        'mysql' => [
            'driver'      => 'mysql',
            'url'         => env('DB_URL'),
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => env('DB_DATABASE', 'forge'),
            'username'    => env('DB_USERNAME', 'forge'),
            'password'    => env('DB_PASSWORD', ''),
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'prefix'      => '',
            'strict'      => true,
            'engine'      => null,
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'url'      => env('DB_URL'),
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases (placeholder)
    |--------------------------------------------------------------------------
    |
    | Not used today. When we go multi-instance (Batch 5/H4) we'll use Redis
    | for rate-limit storage and (optionally) cache/session. Keeping the
    | default block here so when that batch lands, only env vars need to
    | change — no config restructure.
    */
    'redis' => [
        'client'  => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],
        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];
