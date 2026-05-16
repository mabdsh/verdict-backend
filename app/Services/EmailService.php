<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailKind;
use App\Models\EmailOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email service — stub during Phase 5.
 *
 * SWAP-IMPLEMENTATION-LATER PATTERN.
 *
 * Real outbound mail (Resend / SES / Postmark) lands in Phase 7. Until then:
 *
 *   1. Every send() logs the message to the server with a [VERIFICATION]
 *      marker — operator copies it manually to the user during early-access
 *      / private beta. Stub-phase ergonomics only.
 *
 *   2. Every send() also writes a row into the email_outbox table so:
 *      - admin can list pending verifications via /admin/email/outbox (P9)
 *      - an audit trail exists even when console logs rotate
 *      - the Phase 7 real-email implementation can keep using the same table
 *        as a sent-mail history (just flip delivered_at from NULL to now())
 *
 * Callers should NEVER format their own subject/body — that lives in
 * renderTemplate() so the Phase 7 migration to real templates doesn't
 * require touching call sites.
 *
 * Failure modes:
 *   - DB write fails → log and continue. Email sending must NEVER break
 *     the calling flow (e.g. a failed verification email shouldn't roll
 *     back a code-creation transaction; the user can request a resend).
 *   - Console log fails → silently swallowed by PHP's error handler.
 *
 * Thread safety:
 *   - Each call generates a fresh UUID via Str::uuid(). No shared state.
 */
final class EmailService
{
    /**
     * Queue an email. Returns the outbox row id on success.
     *
     * @param  array<string, string|int>  $data  Template variables, e.g. ['code' => '123456']
     * @return string  The outbox row id (also written to the audit log line)
     */
    public function send(EmailKind $kind, string $to, array $data): string
    {
        $id        = (string) Str::uuid();
        $rendered  = $this->renderTemplate($kind, $data);

        // Persist to outbox. delivered_at = NULL until Phase 7's real sender
        // writes a success timestamp. Admin can SELECT * WHERE delivered_at
        // IS NULL to see what hasn't gone out yet.
        try {
            EmailOutbox::create([
                'id'           => $id,
                'kind'         => $kind->value,
                'to_email'     => $to,
                'subject'      => $rendered['subject'],
                'body'         => $rendered['body'],
                'delivered_at' => null,
            ]);
        } catch (Throwable $err) {
            // DB failure is loggable but non-fatal — the console-log fallback
            // below still gives the operator a way to deliver the code manually.
            Log::error('[Email] Failed to persist outbox row', [
                'error' => $err->getMessage(),
                'kind'  => $kind->value,
            ]);
        }

        // Console fallback. Includes the rendered body so operator can copy-paste.
        // CAUTION: this logs the full email body INCLUDING the verification code.
        // That's intentional during stub phase — we have no real outbound mail
        // and someone needs to read the code. Will be removed in Phase 7.
        Log::info(
            "[VERIFICATION] kind={$kind->value} to={$to} id={$id}\n" .
            "Subject: {$rendered['subject']}\n" .
            "{$rendered['body']}\n" .
            '[END VERIFICATION]'
        );

        return $id;
    }

    /**
     * Render a kind + data tuple into a subject/body pair.
     *
     * Plain text for now — Phase 7 will add HTML alternates and proper
     * Mailables. Keeping it dumb on purpose.
     *
     * @param  array<string, string|int>  $data
     * @return array{subject: string, body: string}
     */
    private function renderTemplate(EmailKind $kind, array $data): array
    {
        return match ($kind) {
            EmailKind::RestoreVerification => [
                'subject' => 'Your Verdict verification code',
                'body'    => implode("\n", [
                    "Your Verdict verification code is: {$data['code']}",
                    '',
                    "This code expires in 10 minutes. If you didn't request it, you can ignore this email — your subscription is safe.",
                    '',
                    '— Verdict',
                ]),
            ],

            // Phase 7 placeholders — real templates land with the real sender.
            EmailKind::TrialEndingSoon => [
                'subject' => 'Trial ending soon',
                'body'    => '(stub — Phase 7) ' . json_encode($data, JSON_THROW_ON_ERROR),
            ],
            EmailKind::PaymentFailed => [
                'subject' => 'Payment failed',
                'body'    => '(stub — Phase 7) ' . json_encode($data, JSON_THROW_ON_ERROR),
            ],
            EmailKind::WelcomePro => [
                'subject' => 'Welcome to Pro',
                'body'    => '(stub — Phase 7) ' . json_encode($data, JSON_THROW_ON_ERROR),
            ],
        };
    }
}
