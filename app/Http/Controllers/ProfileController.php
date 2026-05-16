<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Concerns\MapsGroqErrors;
use App\Http\Requests\ParseProfileRequest;
use App\Services\GroqService;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /api/profile/parse
 *
 * Port of the Node backend's profileRouter.ts. Takes a freeform description
 * of job-search preferences and returns a structured ParsedProfile object
 * with extracted skills, target roles, salary expectations, etc.
 *
 * Used by the extension's onboarding flow when a user pastes their CV or
 * a freeform description into the profile-setup popup.
 *
 * Uses Groq's FAST model (llama-3.1-8b-instant) — extraction tasks don't
 * need the SMART model's reasoning quality.
 *
 * Middleware applied by routes/api.php:
 *   - require.device
 *   - throttle:aiEdge
 *   - check.rate:profile    (per-device daily cap: free=1, trial=3, pro=null)
 */
final class ProfileController
{
    use MapsGroqErrors;

    public function __construct(
        private readonly GroqService $groq,
    ) {}

    public function __invoke(ParseProfileRequest $request): JsonResponse
    {
        try {
            $result = $this->groq->parseProfile(
                text: trim((string) $request->input('text'))
            );

            return response()->json([
                'ok'     => true,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->mapGroqError($e, fn () => [
                'rate'   => 'Profile parsing busy — try again shortly',
                'parse'  => 'AI response malformed — profile parsing temporarily unavailable',
                'server' => 'Profile parsing temporarily unavailable',
            ]);
        }
    }
}
