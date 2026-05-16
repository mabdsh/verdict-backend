<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Exceptions\GroqApiException;
use App\Exceptions\GroqParseException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Shared error-mapping trait for the three AI controllers
 * (ScoreController, AnalyzeController, ProfileController).
 *
 * The Node version had nearly-identical try/catch blocks in each of the
 * three routers — same exception types, same HTTP status decision, same
 * response shape. This trait extracts that pattern so a behaviour change
 * (new error code, copy tweak) needs only one edit.
 *
 * Maps domain exceptions to user-facing JSON responses matching the Node
 * backend's shapes — the extension's service-worker.js already handles
 * these `error` codes specifically.
 */
trait MapsGroqErrors
{
    /**
     * Map a thrown exception from the GroqService to a JSON response.
     *
     * Differentiates between:
     *   - GroqApiException with status 429  → 429 GROQ_RATE_LIMIT
     *   - GroqParseException                 → 500 GROQ_PARSE_ERROR
     *   - GroqApiException with other status → 500 SERVER_ERROR
     *
     * @param  callable(): array<string, string>  $copyFor  Returns ['rate' => '...', 'parse' => '...', 'server' => '...']
     *                                                       — endpoint-specific user-facing strings.
     */
    protected function mapGroqError(Throwable $e, callable $copyFor): JsonResponse
    {
        $copy = $copyFor();

        if ($e instanceof GroqApiException && $e->status === 429) {
            return response()->json([
                'ok'      => false,
                'error'   => 'GROQ_RATE_LIMIT',
                'message' => $copy['rate'],
            ], 429);
        }

        if ($e instanceof GroqParseException) {
            return response()->json([
                'ok'      => false,
                'error'   => 'GROQ_PARSE_ERROR',
                'message' => $copy['parse'],
            ], 500);
        }

        // Any other GroqApiException or generic Throwable.
        return response()->json([
            'ok'      => false,
            'error'   => 'SERVER_ERROR',
            'message' => $copy['server'],
        ], 500);
    }
}
