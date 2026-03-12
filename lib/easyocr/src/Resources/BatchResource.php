<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

class BatchResource extends BaseResource
{
    /**
     * Create a batch from multiple files.
     *
     * @param array  $filePaths  Array of absolute file paths
     * @param array  $options    Supported keys: name (string), structure (bool), custom_instructions (string),
     *                           include_extracted_text (bool), auto_correct (bool)
     *
     * @throws EasyOCRException
     */
    public function create(array $filePaths, array $options = []): array
    {
        if (empty($filePaths)) {
            throw new EasyOCRException('Debe proporcionar al menos un archivo', 0, 'INVALID_INPUT');
        }

        $multipart = [];
        $handles   = [];

        try {
            foreach ($filePaths as $path) {
                if (!file_exists($path) || !is_readable($path)) {
                    throw new EasyOCRException("El archivo no existe o no es legible: {$path}", 0, 'FILE_NOT_FOUND');
                }

                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    throw new EasyOCRException("No se pudo abrir el archivo: {$path}", 0, 'FILE_OPEN_ERROR');
                }

                $handles[]   = $handle;
                $multipart[] = [
                    'name'     => 'files[]',
                    'contents' => $handle,
                    'filename' => basename($path),
                ];
            }

            $allowedOpts = ['name', 'structure', 'custom_instructions', 'include_extracted_text', 'auto_correct', 'webhook_url'];
            foreach ($allowedOpts as $key) {
                if (isset($options[$key])) {
                    $value = $options[$key];
                    $multipart[] = [
                        'name'     => $key,
                        'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                    ];
                }
            }

            return $this->request('POST', 'batch', ['multipart' => $multipart]);
        } finally {
            foreach ($handles as $h) {
                if (is_resource($h)) {
                    fclose($h);
                }
            }
        }
    }

    /**
     * List all batches with optional filters.
     *
     * Supported query params (Laravel paginated response):
     *   - per_page  (int)    Items per page (default: 20)
     *   - page      (int)    Page number
     *   - status    (string) pending|processing|completed|partial|failed|cancelled
     *   - name      (string) Partial name search
     *   - from      (string) Start date ISO 8601 (e.g. 2026-01-01)
     *   - to        (string) End date ISO 8601 (e.g. 2026-01-31)
     *
     * Each batch in response includes:
     *   batch_id, name, status, total_documents, completed_documents,
     *   failed_documents, progress, started_at, completed_at, created_at
     *
     * @throws EasyOCRException
     */
    public function list(array $params = []): array
    {
        $query = [];
        $allowed = ['page', 'per_page', 'status', 'name', 'from', 'to'];
        foreach ($allowed as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query[$key] = $params[$key];
            }
        }

        $options = !empty($query) ? ['query' => $query] : [];

        return $this->request('GET', 'batch', $options);
    }

    /**
     * Get batch status.
     *
     * @throws EasyOCRException
     */
    public function status(string $uuid): array
    {
        $this->validateUuid($uuid);

        return $this->request('GET', "batch/{$uuid}");
    }

    /**
     * Get batch results.
     *
     * @throws EasyOCRException
     */
    public function results(string $uuid): array
    {
        $this->validateUuid($uuid);

        return $this->request('GET', "batch/{$uuid}/results");
    }

    /**
     * Cancel a pending/processing batch.
     *
     * @throws EasyOCRException
     */
    public function cancel(string $uuid): array
    {
        $this->validateUuid($uuid);

        return $this->request('DELETE', "batch/{$uuid}");
    }

    private function validateUuid(string $uuid): void
    {
        if (empty($uuid)) {
            throw new EasyOCRException('El UUID del batch no puede estar vacío', 0, 'INVALID_INPUT');
        }
    }
}
