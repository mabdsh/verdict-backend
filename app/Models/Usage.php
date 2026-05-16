<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Usage
 *
 * Per-device, per-day API call counters. Composite primary key
 * (device_id, date) — there's at most one row per (device, day).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ READS via this model. WRITES via DB::statement() in middleware.          ║
 * ║                                                                          ║
 * ║ Eloquent doesn't support composite primary keys natively — $model->save()║
 * ║ won't work correctly here. All increments happen via atomic upsert in    ║
 * ║ CheckRateLimit middleware (P4):                                          ║
 * ║                                                                          ║
 * ║   INSERT INTO usage (device_id, jobs_scored) VALUES (?, ?)               ║
 * ║   ON CONFLICT(device_id, date) DO UPDATE                                 ║
 * ║       SET jobs_scored = jobs_scored + excluded.jobs_scored;              ║
 * ║                                                                          ║
 * ║ Don't call ->save() on Usage instances. Use this model for queries only. ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * @property string                  $device_id
 * @property CarbonImmutable         $date         (date-only)
 * @property int                     $jobs_scored
 * @property int                     $analyze_calls
 * @property int                     $profile_calls
 */
final class Usage extends Model
{
    protected $table = 'usage';

    /**
     * No single primary key — composite (device_id, date). Set primaryKey to
     * device_id so the default query builder doesn't break, but we never
     * actually use Usage::find().
     */
    protected $primaryKey   = 'device_id';
    public    $incrementing = false;
    protected $keyType      = 'string';
    public    $timestamps   = false;

    protected $fillable = ['device_id', 'date', 'jobs_scored', 'analyze_calls', 'profile_calls'];

    protected function casts(): array
    {
        return [
            'date'          => 'immutable_date',  // date-only, not datetime
            'jobs_scored'   => 'integer',
            'analyze_calls' => 'integer',
            'profile_calls' => 'integer',
        ];
    }

    /**
     * Sum a usage column across a date range. Used by /admin/daily and
     * /admin/stats. Defaults match the admin dashboard's "calls this week"
     * cards.
     *
     * @param 'jobs_scored'|'analyze_calls'|'profile_calls' $column
     */
    public static function sumColumn(string $column, ?string $fromDate = null, ?string $toDate = null): int
    {
        $q = static::query();
        if ($fromDate) { $q->where('date', '>=', $fromDate); }
        if ($toDate)   { $q->where('date', '<=', $toDate);   }
        return (int) $q->sum($column);
    }
}
