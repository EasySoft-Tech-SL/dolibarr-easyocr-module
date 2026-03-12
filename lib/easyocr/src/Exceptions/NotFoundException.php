<?php

namespace EasySoft\EasyOCR\Exceptions;

/**
 * 404 — Recurso no encontrado.
 */
class NotFoundException extends EasyOCRException
{
    public function __construct(string $message = 'Recurso no encontrado', array $errorBody = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, 'NOT_FOUND', $errorBody, $previous);
    }
}
