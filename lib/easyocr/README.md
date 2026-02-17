# EasyOCR PHP Client

Official PHP client library for the EasyOCR API.

## Installation

```bash
composer require easysoft/easyocr-php
```

## Quick Start

```php
use EasySoft\EasyOCR\EasyOCRClient;
use EasySoft\EasyOCR\Exceptions\EasyOCRException;
use EasySoft\EasyOCR\Exceptions\AuthenticationException;
use EasySoft\EasyOCR\Exceptions\ValidationException;
use EasySoft\EasyOCR\Exceptions\RateLimitException;
use EasySoft\EasyOCR\Exceptions\NotFoundException;

$client = new EasyOCRClient('your-api-key');

// Process a file
$result = $client->ocr()->processFile('/path/to/invoice.pdf');
echo $result['data']['document_type']; // "invoice"

// Process from URL with optional filename and advanced options
$result = $client->ocr()->processUrl('https://example.com/receipt.pdf', 'receipt.pdf', [
    'structure'            => true,
    'include_text'         => true,
    'auto_correct'         => true,
    'custom_instructions'  => 'Extract all line items',
    'few_shot_examples'    => '[{"input": "...", "output": "..."}]',
]);

// Process base64
$result = $client->ocr()->processBase64(base64_encode(file_get_contents('doc.pdf')), 'doc.pdf');
```

## Configuration

The default base URL (`https://app.easyocr.es`) and timeout (120s) are built-in constants. You only need to override them for staging or self-hosted instances:

```php
// Defaults — just pass your API key
$client = new EasyOCRClient('sk_live_...');

// Override for staging / self-hosted
$client = new EasyOCRClient('sk_live_...', [
    'base_url' => 'https://staging.easyocr.io',
    'timeout'  => 60,
]);
```

## Error Handling

All API errors throw typed exceptions that extend `EasyOCRException`:

```php
try {
    $result = $client->ocr()->processFile('/path/to/file.pdf');
} catch (AuthenticationException $e) {
    // 401/403 — API key inválida o desactivada
    echo "Auth error: " . $e->getMessage();
} catch (ValidationException $e) {
    // 422 — Errores de validación
    echo "Validation: " . $e->getMessage();
    print_r($e->getValidationErrors()); // ['file' => ['The file field is required.']]
} catch (RateLimitException $e) {
    // 429 — Límite de tasa excedido
    echo "Retry after: " . $e->getRetryAfter() . " seconds";
} catch (NotFoundException $e) {
    // 404 — Recurso no encontrado
    echo "Not found: " . $e->getMessage();
} catch (EasyOCRException $e) {
    // Cualquier otro error de API
    echo "Error [{$e->getErrorCode()}]: " . $e->getMessage();
    echo "HTTP Status: " . $e->getHttpStatus();
    print_r($e->getErrorBody());
}
```

## Available Methods

### OCR Processing
- `$client->ocr()->processFile($path, $options)` — Procesamiento por archivo
- `$client->ocr()->processBase64($data, $filename, $options)` — Procesamiento por base64
- `$client->ocr()->processUrl($url, $filename, $options)` — Procesamiento por URL

### OCR Streaming (SSE)
- `$client->ocr()->processFileStream($path, $options)` — Stream por archivo
- `$client->ocr()->processBase64Stream($data, $filename, $options)` — Stream por base64
- `$client->ocr()->processUrlStream($url, $filename, $options)` — Stream por URL

### OCR Options
```php
$options = [
    'structure'            => true,   // Extraer con estructura (default: true)
    'include_text'         => false,  // Incluir texto crudo (default: false)
    'auto_correct'         => false,  // Corrección automática (default: false)
    'use_vision'           => false,  // Usar modelo de visión (default: false)
    'preprocess'           => false,  // Pre-procesar imagen (default: false)
    'custom_instructions'  => '',     // Instrucciones personalizadas
    'few_shot_examples'    => '[]',   // Ejemplos de few-shot (JSON string)
];
```

### Documents
- `$client->documents()->list($filters)` — Listar documentos (per_page, status, type, from, to)
- `$client->documents()->get($uuid)` — Obtener documento por UUID
- `$client->documents()->download($uuid)` — Descargar archivo original
- `$client->documents()->delete($uuid)` — Eliminar documento

### Batch Processing
- `$client->batch()->create($files, $options)` — Crear batch (name, structure, custom_instructions, include_extracted_text, auto_correct)
- `$client->batch()->status($uuid)` — Estado del batch
- `$client->batch()->results($uuid)` — Resultados del batch
- `$client->batch()->cancel($uuid)` — Cancelar batch

### Plans (public, no auth)
- `$client->plans()->list()` — Listar planes disponibles
- `EasyOCRClient::public()->plans()->list()` — Sin autenticación

### Account & Usage
- `$client->account()->me()` — Información de cuenta
- `$client->usage()->current()` — Uso actual del mes
- `$client->usage()->history()` — Historial de uso (12 meses)

### API Keys
- `$client->keys()->list()` — Listar API keys
- `$client->keys()->create($name, $environment)` — Crear nueva key ('live' o 'test')
- `$client->keys()->update($id, $data)` — Actualizar key (name, is_active)
- `$client->keys()->delete($id)` — Revocar (eliminar) key

### Wallet (Prepago)
- `$client->wallet()->balance()` — Saldo actual del monedero
- `$client->wallet()->transactions($perPage)` — Historial de transacciones (paginado)
- `$client->wallet()->packages()` — Paquetes de recarga disponibles

## License

MIT
