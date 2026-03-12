<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

class DocumentResource extends BaseResource
{
    /**
     * List documents with optional filters.
     *
     * @param array $filters  Supported keys: per_page (int), status (string: pending|processing|completed|failed),
     *                        type (string: invoice, receipt, ...), from (string: YYYY-MM-DD), to (string: YYYY-MM-DD)
     *
     * @throws EasyOCRException
     */
    public function list(array $filters = []): array
    {
        $allowed = ['per_page', 'status', 'type', 'from', 'to'];
        $query   = array_intersect_key($filters, array_flip($allowed));

        return $this->request('GET', 'documents', ['query' => $query]);
    }

    /**
     * Get a single document by UUID.
     *
     * @throws EasyOCRException
     */
    public function get(string $uuid): array
    {
        $this->validateUuid($uuid, 'document');

        return $this->request('GET', "documents/{$uuid}");
    }

    /**
     * Download the original file (returns raw binary content).
     *
     * @throws EasyOCRException
     */
    public function download(string $uuid): string
    {
        $this->validateUuid($uuid, 'document');

        $response = $this->requestRaw('GET', "documents/{$uuid}/download");

        return $response->getBody()->getContents();
    }

    /**
     * Delete a document permanently.
     *
     * @throws EasyOCRException
     */
    public function delete(string $uuid): array
    {
        $this->validateUuid($uuid, 'document');

        return $this->request('DELETE', "documents/{$uuid}");
    }

    /**
     * Validate a UUID string.
     */
    private function validateUuid(string $uuid, string $entity): void
    {
        if (empty($uuid)) {
            throw new EasyOCRException("El UUID de {$entity} no puede estar vacío", 0, 'INVALID_INPUT');
        }
    }
}
