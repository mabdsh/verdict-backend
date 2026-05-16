<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PanelOpen
 *
 * One row per (device, day, distinct job) the user opened. Composite PK
 * (device_id, date, job_id) enforces uniqueness so re-opening the same job
 * on the same day is a free slot.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ READS via this model. WRITES via DB::statement() in PanelController.     ║
 * ║                                                                          ║
 * ║ The panel gate uses INSERT OR IGNORE — the composite PK silently no-ops  ║
 * ║ a duplicate insert. Combined with a SELECT-then-INSERT-in-transaction    ║
 * ║ pattern in PanelController (P6), this gives atomic "check + insert"      ║
 * ║ semantics so two concurrent panel opens can't both pass the gate.        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Empty job_id is REJECTED at the controller level (M10 fix from Batch 2 in
 * the Node backend). Previously a random UUID was substituted which silently
 * inflated quota usage. PanelController in P6 will throw on empty job_id.
 *
 * @property string                  $device_id
 * @property CarbonImmutable         $date         (date-only)
 * @property string                  $job_id
 * @property CarbonImmutable         $opened_at
 */
final class PanelOpen extends Model
{
    protected $table = 'panel_opens';

    protected $primaryKey   = 'device_id';   // composite — see class docblock
    public    $incrementing = false;
    protected $keyType      = 'string';
    public    $timestamps   = false;

    protected $fillable = ['device_id', 'date', 'job_id', 'opened_at'];

    protected function casts(): array
    {
        return [
            'date'      => 'immutable_date',
            'opened_at' => 'immutable_datetime',
        ];
    }

    /**
     * Count today's panel opens for a device. Used by the panel-open gate
     * to check whether the daily quota has been reached.
     */
    public static function countToday(string $deviceId): int
    {
        return static::query()
            ->where('device_id', $deviceId)
            ->where('date', now()->toDateString())
            ->count();
    }

    /**
     * Has this device already opened this job today? If yes, the panel
     * gate returns 'already opened' which doesn't consume a quota slot.
     */
    public static function existsForDevice(string $deviceId, string $jobId): bool
    {
        return static::query()
            ->where('device_id', $deviceId)
            ->where('date', now()->toDateString())
            ->where('job_id', $jobId)
            ->exists();
    }
}
