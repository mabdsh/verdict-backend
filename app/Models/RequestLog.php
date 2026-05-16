<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\RequestLog
 *
 * One row per API request. Powers the /admin/logs and /admin/latency
 * endpoints, plus error-rate alerting.
 *
 * 30-day retention enforced by CleanupLogs Artisan command (P10).
 *
 * @property string                  $id
 * @property string|null             $device_id    NULL if auth failed before reaching a controller
 * @property string                  $endpoint
 * @property int                     $latency_ms
 * @property int                     $status       HTTP status code
 * @property string|null             $error        Short error code on failure
 * @property CarbonImmutable         $created_at
 */
final class RequestLog extends Model
{
    protected $table = 'request_logs';

    protected $primaryKey   = 'id';      // request correlation UUID
    public    $incrementing = false;
    protected $keyType      = 'string';

    /**
     * Custom `created_at` column already exists. Eloquent's default
     * timestamps() would also want `updated_at` which we don't have.
     * Disable auto-timestamps and handle created_at via cast + the
     * SQLite DEFAULT clause.
     */
    public $timestamps = false;

    protected $fillable = ['id', 'device_id', 'endpoint', 'latency_ms', 'status', 'error', 'created_at'];

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'status'     => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
