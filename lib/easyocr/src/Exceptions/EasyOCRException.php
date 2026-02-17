<?php

namespace EasySoft\EasyOCR\Exceptions;

/**
 * Base exception for all EasyOCR API errors.
 */
class EasyOCRException extends \RuntimeException
{
    protected int $httpStatus;
    protected string $errorCode;
    protected array $errorBody;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        string $errorCode = 'UNKNOWN_ERROR',
        array $errorBody = [],
        ?\Throwable $previous = null
    ) {
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $errorCode;
        $this->errorBody  = $errorBody;
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorBody(): array
    {
        return $this->errorBody;
    }
}
