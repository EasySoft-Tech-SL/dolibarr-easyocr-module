<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;
use Psr\Http\Message\ResponseInterface;

class OcrResource extends BaseResource
{
    // ─── Standard OCR Processing ─────────────────────────────────────

    /**
     * Process a local file via multipart upload.
     *
     * @param string $filePath  Absolute path to the file
     * @param array  $options   OCR options (structure, include_text, auto_correct, use_vision,
     *                          preprocess, custom_instructions, few_shot_examples)
     *
     * @throws EasyOCRException
     */
    public function processFile(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath)) {
            throw new EasyOCRException("El archivo no existe: {$filePath}", 0, 'FILE_NOT_FOUND');
        }

        if (!is_readable($filePath)) {
            throw new EasyOCRException("El archivo no es legible: {$filePath}", 0, 'FILE_NOT_READABLE');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new EasyOCRException("No se pudo abrir el archivo: {$filePath}", 0, 'FILE_OPEN_ERROR');
        }

        try {
            $multipart = [
                ['name' => 'file', 'contents' => $handle, 'filename' => basename($filePath)],
            ];

            foreach ($this->buildOptions($options) as $key => $value) {
                if ($value !== null) {
                    $multipart[] = ['name' => $key, 'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
                }
            }

            return $this->request('POST', 'ocr/file', ['multipart' => $multipart]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Process a base64-encoded document.
     *
     * @param string      $base64Data  Base64-encoded content
     * @param string|null $filename    Original filename (default: document.pdf)
     * @param array       $options     OCR options
     *
     * @throws EasyOCRException
     */
    public function processBase64(string $base64Data, ?string $filename = null, array $options = []): array
    {
        if (empty($base64Data)) {
            throw new EasyOCRException('El contenido base64 no puede estar vacío', 0, 'INVALID_INPUT');
        }

        $body = array_filter(
            array_merge($this->buildOptions($options), [
                'base64_data' => $base64Data,
                'filename'    => $filename ?? 'document.pdf',
            ]),
            fn ($v) => $v !== null
        );

        return $this->request('POST', 'ocr/base64', ['json' => $body]);
    }

    /**
     * Process a document from a public URL.
     *
     * @param string      $url       Public URL of the document (max 2048 chars)
     * @param string|null $filename  Filename hint (optional)
     * @param array       $options   OCR options
     *
     * @throws EasyOCRException
     */
    public function processUrl(string $url, ?string $filename = null, array $options = []): array
    {
        if (empty($url)) {
            throw new EasyOCRException('La URL no puede estar vacía', 0, 'INVALID_INPUT');
        }

        if (strlen($url) > 2048) {
            throw new EasyOCRException('La URL excede los 2048 caracteres permitidos', 0, 'INVALID_INPUT');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new EasyOCRException('La URL proporcionada no es válida', 0, 'INVALID_INPUT');
        }

        $body = array_filter(
            array_merge($this->buildOptions($options), [
                'url'      => $url,
                'filename' => $filename,
            ]),
            fn ($v) => $v !== null
        );

        return $this->request('POST', 'ocr/url', ['json' => $body]);
    }

    // ─── SSE Streaming ───────────────────────────────────────────────

    /**
     * Process a local file with SSE streaming response.
     *
     * @return ResponseInterface  Stream response (read with getBody()->read() or getBody()->getContents())
     * @throws EasyOCRException
     */
    public function processFileStream(string $filePath, array $options = []): ResponseInterface
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new EasyOCRException("El archivo no existe o no es legible: {$filePath}", 0, 'FILE_NOT_FOUND');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new EasyOCRException("No se pudo abrir el archivo: {$filePath}", 0, 'FILE_OPEN_ERROR');
        }

        $multipart = [
            ['name' => 'file', 'contents' => $handle, 'filename' => basename($filePath)],
        ];

        foreach ($this->buildOptions($options) as $key => $value) {
            if ($value !== null) {
                $multipart[] = ['name' => $key, 'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
            }
        }

        return $this->requestRaw('POST', 'ocr/file/stream', [
            'multipart' => $multipart,
            'headers'   => ['Accept' => 'text/event-stream'],
            'stream'    => true,
        ]);
    }

    /**
     * Process base64-encoded document with SSE streaming response.
     *
     * @return ResponseInterface
     * @throws EasyOCRException
     */
    public function processBase64Stream(string $base64Data, ?string $filename = null, array $options = []): ResponseInterface
    {
        if (empty($base64Data)) {
            throw new EasyOCRException('El contenido base64 no puede estar vacío', 0, 'INVALID_INPUT');
        }

        $body = array_filter(
            array_merge($this->buildOptions($options), [
                'base64_data' => $base64Data,
                'filename'    => $filename ?? 'document.pdf',
            ]),
            fn ($v) => $v !== null
        );

        return $this->requestRaw('POST', 'ocr/base64/stream', [
            'json'    => $body,
            'headers' => ['Accept' => 'text/event-stream'],
            'stream'  => true,
        ]);
    }

    /**
     * Process a URL document with SSE streaming response.
     *
     * @return ResponseInterface
     * @throws EasyOCRException
     */
    public function processUrlStream(string $url, ?string $filename = null, array $options = []): ResponseInterface
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new EasyOCRException('La URL proporcionada no es válida', 0, 'INVALID_INPUT');
        }

        $body = array_filter(
            array_merge($this->buildOptions($options), [
                'url'      => $url,
                'filename' => $filename,
            ]),
            fn ($v) => $v !== null
        );

        return $this->requestRaw('POST', 'ocr/url/stream', [
            'json'    => $body,
            'headers' => ['Accept' => 'text/event-stream'],
            'stream'  => true,
        ]);
    }

    // ─── Options Builder ─────────────────────────────────────────────

    /**
     * Build OCR options array from user input. Only includes non-null values.
     */
    private function buildOptions(array $options): array
    {
        $built = [
            'structure'    => $options['structure'] ?? true,
            'include_text' => $options['include_text'] ?? false,
            'auto_correct' => $options['auto_correct'] ?? false,
            'use_vision'   => $options['use_vision'] ?? false,
            'preprocess'   => $options['preprocess'] ?? false,
        ];

        // Optional advanced parameters
        if (isset($options['custom_instructions'])) {
            $built['custom_instructions'] = (string) $options['custom_instructions'];
        }

        if (isset($options['few_shot_examples'])) {
            $built['few_shot_examples'] = is_string($options['few_shot_examples'])
                ? $options['few_shot_examples']
                : json_encode($options['few_shot_examples']);
        }

        return $built;
    }
}
