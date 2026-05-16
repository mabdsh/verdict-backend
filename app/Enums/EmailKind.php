<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Discriminator for EmailService::send($kind, ...). Same set as the Node
 * EmailKind type — used by render() to pick the subject/body template.
 *
 * Only RestoreVerification is wired up in this phase. The others are stubs
 * with TODO bodies that will get real templates when Phase 7 (real outbound
 * mail) lands.
 */
enum EmailKind: string
{
    case RestoreVerification = 'restore_verification';
    case TrialEndingSoon     = 'trial_ending_soon';   // P7
    case PaymentFailed       = 'payment_failed';     // P7
    case WelcomePro          = 'welcome_pro';        // P7
}
