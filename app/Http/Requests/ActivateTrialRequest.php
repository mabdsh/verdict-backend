<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation for POST /api/trial/activate.
 *
 * Body shape: { "email": "user@example.com" }
 *
 * The regex matches the Node backend's EMAIL_RE exactly:
 *   /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/
 * Not RFC-exhaustive, but catches all realistic garbage. 254 chars is the
 * RFC-5321 maximum email length.
 *
 * Disposable-domain check happens in the controller — it requires reading
 * the operator-mutable settings.disposable_email_domains and produces a
 * specific error code (EMAIL_NOT_ALLOWED) that's distinct from generic
 * format-invalid. Validators here only handle syntactic validity.
 */
final class ActivateTrialRequest extends FormRequest
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

    /**
     * Lowercase + trim BEFORE validation runs so the email-rule check sees
     * the normalised form and downstream code never has to re-normalise.
     *
     * @return array<string, string>
     */
    protected function prepareForValidation(): array
    {
        $raw = $this->input('email');
        if (is_string($raw)) {
            $this->merge(['email' => strtolower(trim($raw))]);
        }
        return $this->all();
    }

    /**
     * Match the Node backend's 400 response shape — the extension reads
     * 'error' and 'message' specifically.
     */
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
