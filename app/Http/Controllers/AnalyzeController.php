<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Concerns\MapsGroqErrors;
use App\Http\Requests\AnalyzeJobRequest;
use App\Services\GroqService;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /api/analyze/job
 *
 * Port of the Node backend's analyzeRouter.ts. Performs deep AI analysis
 * of a single job posting against the user's profile, returning:
 *   decision, summary, keyRequirements, strengths, gaps, tips, insights
 *
 * Used by the extension's AI panel when a user clicks "AI Coaching" on a
 * job detail page.
 *
 * Middleware applied by routes/api.php:
 *   - require.device
 *   - throttle:aiEdge
 *   - check.rate:analyze    (per-device daily cap: free=3, trial=10, pro=null)
 */
final class AnalyzeController
{
    use MapsGroqErrors;

    public function __construct(
        private readonly GroqService $groq,
    ) {}

    public function __invoke(AnalyzeJobRequest $request): JsonResponse
    {
        try {
            $result = $this->groq->analyzeJob(
                profile:         $request->input('profile'),
                jobData:         $request->input('jobData'),
                fullDescription: (string) $request->input('fullDescription', ''),
            );

            return response()->json([
                'ok'     => true,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->mapGroqError($e, fn () => [
                'rate'   => 'Analysis service busy — try again shortly',
                'parse'  => 'AI response malformed — analysis temporarily unavailable',
                'server' => 'Deep analysis temporarily unavailable',
            ]);
        }
    }
}
