<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Concerns\MapsGroqErrors;
use App\Http\Requests\BatchScoreRequest;
use App\Services\GroqService;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /api/score/batch
 *
 * Port of the Node backend's scoreRouter.ts. Scores a batch of jobs from
 * a search results page against the user's saved profile.
 *
 * Middleware applied by routes/api.php:
 *   - require.device                  (auth via X-Client-Secret + X-Device-ID)
 *   - throttle:scoreEdge              (per-IP edge limit, 120/min)
 *   - check.score.volume              (per-device daily VOLUME cap)
 *
 * By the time __invoke runs, the middleware has confirmed:
 *   - The device is valid (Device model on $request->device())
 *   - jobs.length is within MAX_JOBS_PER_BATCH
 *   - The user's daily score-volume quota has been atomically decremented
 *     by count($jobs)
 *
 * Controller responsibilities:
 *   - Validate body shape via BatchScoreRequest
 *   - Call GroqService
 *   - Map Groq exceptions to user-facing JSON
 *
 * Note: there is intentionally no try/catch here for non-Groq errors —
 * generic exceptions bubble to Laravel's render() in bootstrap/app.php
 * which returns a consistent {error: 'internal_error'} JSON shape.
 */
final class ScoreController
{
    use MapsGroqErrors;

    public function __construct(
        private readonly GroqService $groq,
    ) {}

    public function __invoke(BatchScoreRequest $request): JsonResponse
    {
        try {
            $results = $this->groq->batchScoreJobs(
                profile: $request->input('profile'),
                jobs:    $request->input('jobs'),
            );

            return response()->json([
                'ok'      => true,
                'results' => $results,
            ]);
        } catch (Throwable $e) {
            return $this->mapGroqError($e, fn () => [
                'rate'   => 'Groq rate limit hit — rule-based scoring applied',
                'parse'  => 'AI response malformed — rule-based scoring applied',
                'server' => 'Scoring service temporarily unavailable',
            ]);
        }
    }
}
