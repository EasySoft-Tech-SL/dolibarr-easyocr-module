<?php

namespace EasySoft\EasyOCR\Exceptions;

/**
 * 429 — Rate limit alcanzado o cuota agotada.
 */
class RateLimitException extends EasyOCRException
{
    protected ?int $retryAfter;

    public function __construct(string $message, ?int $retryAfter = null, array $errorBody = [], ?\Throwable $previous = null)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, 429, 'RATE_LIMIT_EXCEEDED', $errorBody, $previous);
    }

    /** Seconds to wait before retrying, or null if not provided. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
