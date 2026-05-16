<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation for POST /api/subscription/restore/confirm.
 *
 * Body: { "email": "user@example.com", "code": "123456" }
 *
 * Code is strict: exactly 6 digits. Wrong-length codes are rejected here
 * with a 400 invalid_input so they never reach VerificationService — saves
 * a DB round-trip on obviously-bad inputs.
 *
 * The controller catches verify failures separately and maps ALL failure
 * reasons (expired / too_many_attempts / invalid_code / not_found) to the
 * same generic user message — that's the enumeration-safety part.
 */
final class RestoreConfirmRequest extends FormRequest
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
            'code'  => [
                'required',
                'string',
                'regex:/^\d{6}$/',
            ],
        ];
    }

    /** @return array<string, string> */
    protected function prepareForValidation(): array
    {
        $merge = [];
        if (is_string($e = $this->input('email'))) {
            $merge['email'] = strtolower(trim($e));
        }
        if (is_string($c = $this->input('code'))) {
            $merge['code'] = trim($c);
        }
        $this->merge($merge);
        return $this->all();
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'error'   => 'invalid_input',
                'message' => 'Email or code format invalid.',
            ], 400)
        );
    }
}
