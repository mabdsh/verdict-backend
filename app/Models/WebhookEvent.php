<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * App\Models\WebhookEvent
 *
 * Idempotency guard for LemonSqueezy webhook deliveries. Composite PK
 * (provider, event_id) is the dedup key.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ Inserts go through ::record() which does INSERT OR IGNORE atomically.   ║
 * ║ This is the only correct way to dedup under concurrent delivery —       ║
 * ║ LemonSqueezy may fire the same event twice in parallel during retries.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * 30-day retention via CleanupLogs (P10).
 *
 * @property string                  $provider
 * @property string                  $event_id
 * @property string|null             $event_name
 * @property CarbonImmutable         $received_at
 */
final class WebhookEvent extends Model
{
    protected $table = 'webhook_events';

    protected $primaryKey   = 'event_id';   // composite — see class docblock
    public    $incrementing = false;
    protected $keyType      = 'string';
    public    $timestamps   = false;

    protected $fillable = ['provider', 'event_id', 'event_name', 'received_at'];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * Atomically record an event. Returns TRUE if this is the first time
     * we've seen it (caller should process), FALSE if it's a duplicate
     * (caller should skip processing and return 200 OK so LemonSqueezy
     * stops retrying).
     *
     * Uses INSERT OR IGNORE + checking the row count — this is atomic in
     * SQLite even under concurrent webhook delivery. The .changes value
     * is 1 for a successful insert, 0 if the PK conflict caused IGNORE.
     */
    public static function record(string $provider, string $eventId, ?string $eventName): bool
    {
        if ($eventId === '') {
            // Events without an ID can't be deduped; let them through.
            return true;
        }

        $changes = DB::affectingStatement(
            'INSERT OR IGNORE INTO webhook_events (provider, event_id, event_name) VALUES (?, ?, ?)',
            [$provider, $eventId, $eventName]
        );

        return $changes === 1;
    }
}
