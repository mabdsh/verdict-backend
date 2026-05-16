<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation for POST /api/device/email.
 *
 * Stores an OPTIONAL email on a device record so support can identify a
 * customer who later loses access. Distinct from trial activation — saving
 * an email here doesn't start a trial.
 *
 * Validation matches the Node deviceRouter.ts:
 *   - Required (the endpoint exists to set the field, not clear it)
 *   - Must contain '@' and '.'  (intentionally loose; trial activation has
 *     the strict regex)
 *   - Max 254 chars (RFC-5321 limit)
 *
 * The looser format check here is a deliberate carryover from the Node
 * version. The reasoning: trial activation needs a real address (we'll
 * email them) but the device email is just a recovery hint that an admin
 * uses in /admin/subscription/devices?q=email when manually helping a
 * customer who lost access. Looser is fine for that use case.
 */
final class SaveDeviceEmailRequest extends FormRequest
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
                // Loose regex per Node parity — must contain @ and a dot
                'regex:/.+@.+\..+/',
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
        $errors = $validator->errors();
        $message = $errors->has('email') && empty($this->input('email'))
            ? 'Email is required.'
            : 'Enter a valid email address.';

        throw new HttpResponseException(
            response()->json([
                'error'   => 'invalid_email',
                'message' => $message,
            ], 400)
        );
    }
}
