<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

class KeyResource extends BaseResource
{
    /**
     * List all API keys.
     *
     * @throws EasyOCRException
     */
    public function list(): array
    {
        return $this->request('GET', 'keys');
    }

    /**
     * Create a new API key.
     *
     * @param string $name        Descriptive name (max 100 chars)
     * @param string $environment 'live' or 'test'
     *
     * @throws EasyOCRException
     */
    public function create(string $name, string $environment = 'live'): array
    {
        if (empty($name)) {
            throw new EasyOCRException('El nombre de la API Key no puede estar vacío', 0, 'INVALID_INPUT');
        }

        if (strlen($name) > 100) {
            throw new EasyOCRException('El nombre de la API Key no puede exceder 100 caracteres', 0, 'INVALID_INPUT');
        }

        if (!in_array($environment, ['live', 'test'], true)) {
            throw new EasyOCRException("Entorno inválido: {$environment}. Use 'live' o 'test'", 0, 'INVALID_INPUT');
        }

        return $this->request('POST', 'keys', [
            'json' => compact('name', 'environment'),
        ]);
    }

    /**
     * Update an existing API key.
     *
     * @param int   $id    API Key ID
     * @param array $data  Supported keys: name (string, max 100), is_active (bool)
     *
     * @throws EasyOCRException
     */
    public function update(int $id, array $data): array
    {
        if ($id <= 0) {
            throw new EasyOCRException('El ID de la API Key debe ser un entero positivo', 0, 'INVALID_INPUT');
        }

        $allowed = array_intersect_key($data, array_flip(['name', 'is_active']));

        if (empty($allowed)) {
            throw new EasyOCRException('Debe proporcionar al menos un campo a actualizar (name, is_active)', 0, 'INVALID_INPUT');
        }

        if (isset($allowed['name']) && strlen($allowed['name']) > 100) {
            throw new EasyOCRException('El nombre de la API Key no puede exceder 100 caracteres', 0, 'INVALID_INPUT');
        }

        return $this->request('PATCH', "keys/{$id}", ['json' => $allowed]);
    }

    /**
     * Revoke (delete) an API key.
     *
     * @throws EasyOCRException
     */
    public function delete(int $id): array
    {
        if ($id <= 0) {
            throw new EasyOCRException('El ID de la API Key debe ser un entero positivo', 0, 'INVALID_INPUT');
        }

        return $this->request('DELETE', "keys/{$id}");
    }
}
