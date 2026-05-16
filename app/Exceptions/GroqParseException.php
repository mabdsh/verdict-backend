<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when Groq returns a response body that isn't valid JSON, or that's
 * valid JSON but missing the expected shape (e.g. no `choices[0].message.content`).
 *
 * Controllers catch this and map it to a 500 response with
 * error='GROQ_PARSE_ERROR' so the extension can render a "rule-based fallback"
 * message. The extension treats this case as recoverable — it falls back to
 * client-side rule scoring without the AI layer.
 *
 * Carries the raw response text in $raw for log forensics. Never leaked to
 * the client.
 */
final class GroqParseException extends RuntimeException
{
    public function __construct(
        public readonly string $raw,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Groq returned malformed JSON — model may be overloaded or response was truncated',
            0,
            $previous,
        );
    }
}
