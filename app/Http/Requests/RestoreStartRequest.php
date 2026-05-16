<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation for POST /api/subscription/restore/start.
 *
 * Body: { "email": "user@example.com" }
 *
 * Strict-regex validation (same as trial activation). On invalid input,
 * returns 400 with error: 'invalid_email'.
 *
 * IMPORTANT: This is the first half of the verified-restore flow (C3 fix
 * from Batch 2). The controller MUST always return 200 from the success
 * path regardless of whether the email matches a real customer — that
 * enumeration-safe behaviour is what protects customers from email-based
 * takeover. Validation errors here (400) are FINE because they're about
 * malformed input, not about "this email isn't a customer."
 */
final class RestoreStartRequest extends FormRequest
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
            'email' => [
                'required',
                'string',
                'max:254',
                'regex:/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/',
            ],
        ];
    }

    /** @return array<string, string> */
    protected function prepareForValidation(): array
    {
        $raw = $this->input('email');
        if (is_string($raw)) {
            $this->merge(['email' => strtolower(trim($raw))]);
        }
        return $this->all();
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'error'   => 'invalid_email',
                'message' => 'Enter a valid email address.',
            ], 400)
        );
    }
}
