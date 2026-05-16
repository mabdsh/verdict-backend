<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/analyze/job.
 *
 * Body shape:
 *   {
 *     "profile":         { ...candidate profile },
 *     "jobData":         { "title", "company", "location", "workType", "salary" },
 *     "fullDescription": "the full text scraped from the job page"
 *   }
 *
 * fullDescription is truncated to 4500 chars (matches the Node backend's
 * pre-truncation; GroqService also re-truncates to 3800 before sending to
 * the model — defence in depth).
 */
final class AnalyzeJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function rules(): array
    {
        return [
            'profile'         => ['required', 'array'],
            'jobData'         => ['required', 'array'],
            'fullDescription' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'profile.required' => 'profile and jobData are required',
            'jobData.required' => 'profile and jobData are required',
        ];
    }

    /**
     * Truncate fullDescription before the controller handler sees it.
     * Extension self-limits to 4500; this guards against direct callers.
     */
    protected function passedValidation(): void
    {
        $fd = $this->input('fullDescription');
        $this->merge([
            'fullDescription' => is_string($fd) ? substr($fd, 0, 4500) : '',
        ]);
    }
}
