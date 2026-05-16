<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\EmailOutbox
 *
 * Audit log of every email the application has queued for sending.
 *
 * During the stub phase (pre-Batch-7 in the Node project / Phase 7 here)
 * actual delivery happens by operator console-copy; this table records
 * what was queued so admin can list pending verifications. Once the real
 * SMTP/SES sender lands, it will set delivered_at = now() on successful
 * delivery. delivered_at IS NULL means "queued, not yet sent".
 *
 * Inserts happen via App\Services\EmailService (P5) — keep them out of the
 * model so the same EmailService interface can be swapped to a real
 * transactional-email provider later without touching call sites.
 *
 * @property string                  $id
 * @property string                  $kind         restore_verification, trial_ending_soon, etc.
 * @property string                  $to_email
 * @property string                  $subject
 * @property string                  $body
 * @property CarbonImmutable         $queued_at
 * @property CarbonImmutable|null    $delivered_at
 */
final class EmailOutbox extends Model
{
    protected $table = 'email_outbox';

    protected $primaryKey   = 'id';
    public    $incrementing = false;
    protected $keyType      = 'string';
    public    $timestamps   = false;

    protected $fillable = ['id', 'kind', 'to_email', 'subject', 'body', 'queued_at', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'queued_at'    => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
