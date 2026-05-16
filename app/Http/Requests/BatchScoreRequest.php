<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Limits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/score/batch.
 *
 * Body shape:
 *   {
 *     "profile": { ...candidate profile from extension's saved settings },
 *     "jobs":    [ { "jobId": "...", "title": "...", "company": "...",
 *                    "workType": "...", "salary": {...}, "rawText": "..." }, ... ]
 *   }
 *
 * Defers tier-limit enforcement (free=500, trial=1500, pro=null jobs/day)
 * to the CheckScoreVolumeLimit middleware — by the time this validator
 * runs, the middleware has already confirmed the daily quota allows it.
 *
 * sanitisation: rawText on each job is truncated to 4000 chars. The Node
 * version did this server-side as a defence against direct API callers
 * sending pathological payloads. Port verbatim.
 */
final class BatchScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // require.device middleware has already authorised the request
        // before we get here. The model layer doesn't have user-level
        // permissions to check (any authenticated device can score).
        return true;
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function rules(): array
    {
        return [
            'profile'             => ['required', 'array'],
            'jobs'                => ['required', 'array', 'min:1', 'max:' . Limits::MAX_JOBS_PER_BATCH],
            'jobs.*'              => ['array'],
            'jobs.*.jobId'        => ['nullable', 'string', 'max:128'],
            'jobs.*.title'        => ['nullable', 'string', 'max:512'],
            'jobs.*.company'      => ['nullable', 'string', 'max:256'],
            'jobs.*.workType'     => ['nullable', 'string', 'max:64'],
            'jobs.*.salary'       => ['nullable', 'array'],
            'jobs.*.salary.low'   => ['nullable', 'numeric'],
            'jobs.*.salary.high'  => ['nullable', 'numeric'],
            // rawText is sanitized in passedValidation rather than validated
            // for length here — we want to truncate rather than reject.
            'jobs.*.rawText'      => ['nullable', 'string'],
        ];
    }

    /**
     * Custom validation messages. Matches the Node backend's wording so the
     * extension's existing 400-handling shows the same copy.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'profile.required' => 'profile object and non-empty jobs array are required',
            'jobs.required'    => 'profile object and non-empty jobs array are required',
            'jobs.array'       => 'profile object and non-empty jobs array are required',
            'jobs.min'         => 'profile object and non-empty jobs array are required',
            'jobs.max'         => 'Maximum ' . Limits::MAX_JOBS_PER_BATCH . ' jobs per batch — LinkedIn shows ~25 per page',
        ];
    }

    /**
     * Truncate rawText defensively. Extension already self-limits to 4000
     * chars; this guards against direct API callers sending bigger
     * payloads that would inflate the Groq prompt.
     */
    protected function passedValidation(): void
    {
        $jobs = $this->input('jobs', []);
        foreach ($jobs as $i => $j) {
            if (is_array($j) && isset($j['rawText']) && is_string($j['rawText'])) {
                $jobs[$i]['rawText'] = substr($j['rawText'], 0, 4000);
            } elseif (is_array($j)) {
                $jobs[$i]['rawText'] = '';
            }
        }
        $this->merge(['jobs' => $jobs]);
    }
}
