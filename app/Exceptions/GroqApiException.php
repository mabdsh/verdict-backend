<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when Groq returns a non-2xx HTTP response after exhausting retries.
 *
 * The HTTP status code is preserved on $status so the calling controller can
 * map it appropriately:
 *   429 → user-visible "rate limit hit, try again shortly"
 *   5xx → "service temporarily unavailable"
 *
 * Note: the retry layer (GroqService::callGroq) only retries 429 + 5xx.
 * A 400-level error other than 429 is fatal on the first attempt because
 * retrying would not change the outcome (we're sending bad input).
 */
final class GroqApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Groq API returned HTTP {$status}",
            $status,
            $previous,
        );
    }
}
