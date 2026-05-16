<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/profile/parse.
 *
 * Body shape:
 *   { "text": "freeform description of job-search preferences" }
 *
 * Length constraints match the Node backend exactly:
 *   min 20 chars  — anything shorter is unlikely to contain useful prefs
 *   max 2000 chars — keeps the FAST model prompt within sensible bounds
 *
 * Returns the Node-shape 400 response on length violation, including the
 * received_length / max_length fields the extension's UI displays.
 */
final class ParseProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => 'text must be a string of at least 20 characters',
            'text.string'   => 'text must be a string of at least 20 characters',
            'text.min'      => 'text must be a string of at least 20 characters',
            'text.max'      => 'text must be 2000 characters or less',
        ];
    }

    /**
     * Override the failure response to match the Node backend's shape,
     * which includes additional fields (max_length, received_length) on
     * length violations.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors  = $validator->errors();
        $rawText = (string) $this->input('text', '');

        // Length-too-long needs the special response with the received_length
        // and max_length metadata the popup's profile-parse UI surfaces.
        if ($errors->has('text') && strlen($rawText) > 2000) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'error'           => 'text_too_long',
                    'message'         => 'text must be 2000 characters or less',
                    'max_length'      => 2000,
                    'received_length' => strlen($rawText),
                ], 400)
            );
        }

        // Generic invalid_input shape for everything else.
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'error'   => 'invalid_input',
                'message' => $errors->first('text') ?? 'text must be a string of at least 20 characters',
            ], 400)
        );
    }
}
