<?php

namespace EasySoft\EasyOCR;

use GuzzleHttp\Client;
use EasySoft\EasyOCR\Exceptions\EasyOCRException;
use EasySoft\EasyOCR\Resources\OcrResource;
use EasySoft\EasyOCR\Resources\DocumentResource;
use EasySoft\EasyOCR\Resources\BatchResource;
use EasySoft\EasyOCR\Resources\AccountResource;
use EasySoft\EasyOCR\Resources\UsageResource;
use EasySoft\EasyOCR\Resources\KeyResource;
use EasySoft\EasyOCR\Resources\PlansResource;
use EasySoft\EasyOCR\Resources\WalletResource;

/**
 * Main client for the EasyOCR API.
 *
 * Uses lazy-loaded resource accessors — each resource is instantiated once
 * on first access and reused for subsequent calls (flyweight pattern).
 *
 * @example
 *   $client = new EasyOCRClient('sk_live_...');
 *   $result = $client->ocr()->processFile('/tmp/invoice.pdf');
 *
 * @example Override base URL (e.g. staging / self-hosted)
 *   $client = new EasyOCRClient('sk_live_...', ['base_url' => 'https://staging.easyocr.io']);
 */
class EasyOCRClient
{
    /** Production API base URL — override via constructor options only if needed. */
    private const DEFAULT_BASE_URL = 'https://app.easyocr.es';

    private const DEFAULT_TIMEOUT = 120;

    private Client $http;
    private string $apiKey;
    private string $baseUrl;

    /** @var array<string, object> Lazy-loaded resource cache */
    private array $resources = [];

    /**
     * Create an authenticated EasyOCR client.
     *
     * @param string $apiKey   Your EasyOCR API key (sent as X-API-Key header)
     * @param array  $options  Optional overrides:
     *                         - base_url (string): API base URL
     *                         - timeout  (int):    Request timeout in seconds
     *
     * @throws EasyOCRException If the API key is empty
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (empty(trim($apiKey))) {
            throw new EasyOCRException('La API Key no puede estar vacía', 0, 'INVALID_API_KEY');
        }

        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');

        $this->http = new Client([
            'base_uri'    => $this->baseUrl . '/api/v1/',
            'timeout'     => $options['timeout'] ?? self::DEFAULT_TIMEOUT,
            'http_errors' => true,
            'headers'     => [
                'X-API-Key' => $this->apiKey,
                'Accept'    => 'application/json',
            ],
        ]);
    }

    /**
     * Create an unauthenticated client for public endpoints (plans, packages).
     *
     * @param array $options Optional overrides (base_url, timeout)
     */
    public static function public(array $options = []): self
    {
        $instance = new self('__public__', $options);

        $baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $instance->http = new Client([
            'base_uri'    => $baseUrl . '/api/v1/',
            'timeout'     => $options['timeout'] ?? 30,
            'http_errors' => true,
            'headers'     => [
                'Accept' => 'application/json',
            ],
        ]);

        return $instance;
    }

    // ─── Resource Accessors (lazy-loaded singletons) ─────────────

    /** OCR processing endpoints (file, base64, url — normal + streaming). */
    public function ocr(): OcrResource
    {
        return $this->resolve(OcrResource::class);
    }

    /** Document management (list, get, download, delete). */
    public function documents(): DocumentResource
    {
        return $this->resolve(DocumentResource::class);
    }

    /** Batch processing (create, status, results, cancel). */
    public function batch(): BatchResource
    {
        return $this->resolve(BatchResource::class);
    }

    /** Account info (/me). */
    public function account(): AccountResource
    {
        return $this->resolve(AccountResource::class);
    }

    /** Usage & quotas (current month, history). */
    public function usage(): UsageResource
    {
        return $this->resolve(UsageResource::class);
    }

    /** API Key management (CRUD). */
    public function keys(): KeyResource
    {
        return $this->resolve(KeyResource::class);
    }

    /** Public plans listing (no auth required). */
    public function plans(): PlansResource
    {
        return $this->resolve(PlansResource::class);
    }

    /** Wallet / prepaid balance (balance, transactions, packages). */
    public function wallet(): WalletResource
    {
        return $this->resolve(WalletResource::class);
    }

    /** Direct HTTP access for custom requests. */
    public function getHttpClient(): Client
    {
        return $this->http;
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * Resolve and cache a resource instance (flyweight pattern).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function resolve(string $class): object
    {
        return $this->resources[$class] ??= new $class($this->http);
    }
}
