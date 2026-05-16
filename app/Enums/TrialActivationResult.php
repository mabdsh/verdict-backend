<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of attempting to start a 7-day free trial via TrialService::activate().
 *
 * The controller maps this to user-facing JSON. Three outcomes:
 *
 *   Activated     — first-time activation succeeded
 *   AlreadyActive — device already has trial_started_at set (idempotent return)
 *   EmailUsed     — another device has already used this email's canonical
 *                   form for a trial — one-per-email rule enforced
 */
enum TrialActivationResult: string
{
    case Activated     = 'activated';
    case AlreadyActive = 'already_active';
    case EmailUsed     = 'email_used';
}
