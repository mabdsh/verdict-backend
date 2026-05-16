<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of attempting to verify a 6-digit code in VerificationService::verify().
 *
 * The controller switches on this to produce the user-facing response.
 * Note that all FAILURE cases (Expired, TooManyAttempts, InvalidCode, NotFound)
 * MUST be exposed via the same generic message to the user — different
 * messages would let an attacker enumerate the system. The reason value is
 * for in-band logging + the extension's analytics, not for display.
 */
enum VerificationOutcome: string
{
    case Success          = 'success';
    case InvalidCode      = 'invalid_code';
    case Expired          = 'expired';
    case TooManyAttempts  = 'too_many_attempts';
    case NotFound         = 'not_found';
}
