<?php

namespace EasySoft\EasyOCR\Exceptions;

/**
 * 422 — Errores de validación (parámetros inválidos).
 */
class ValidationException extends EasyOCRException
{
    protected array $errors;

    public function __construct(string $message, array $errors = [], array $errorBody = [], ?\Throwable $previous = null)
    {
        $this->errors = $errors;
        parent::__construct($message, 422, 'VALIDATION_ERROR', $errorBody, $previous);
    }

    /** @return array<string, string[]> Field-level errors */
    public function getValidationErrors(): array
    {
        return $this->errors;
    }
}
