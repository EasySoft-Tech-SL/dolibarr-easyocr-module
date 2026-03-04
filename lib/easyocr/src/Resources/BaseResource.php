<?php

namespace EasySoft\EasyOCR\Resources;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;
use EasySoft\EasyOCR\Exceptions\AuthenticationException;
use EasySoft\EasyOCR\Exceptions\EasyOCRException;
use EasySoft\EasyOCR\Exceptions\NotFoundException;
use EasySoft\EasyOCR\Exceptions\RateLimitException;
use EasySoft\EasyOCR\Exceptions\ValidationException;

abstract class BaseResource
{
    protected Client $http;

    public function __construct(Client $http)
    {
        $this->http = $http;
    }

    /**
     * Execute a request with comprehensive error handling.
     *
     * @throws AuthenticationException
     * @throws ValidationException
     * @throws NotFoundException
     * @throws RateLimitException
     * @throws EasyOCRException
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $uri, $options);
            return $this->decode($response);
        } catch (ClientException $e) {
            $this->handleClientError($e);
        } catch (ServerException $e) {
            $body = $this->safeDecodeBody($e->getResponse());
            throw new EasyOCRException(
                $body['error']['message'] ?? 'Error interno del servidor',
                $e->getResponse()->getStatusCode(),
                $body['error']['code'] ?? 'SERVER_ERROR',
                $body,
                $e
            );
        } catch (ConnectException $e) {
            throw new EasyOCRException(
                'No se pudo conectar con la API de EasyOCR: ' . $e->getMessage(),
                0,
                'CONNECTION_ERROR',
                [],
                $e
            );
        } catch (GuzzleException $e) {
            throw new EasyOCRException(
                'Error de red: ' . $e->getMessage(),
                0,
                'NETWORK_ERROR',
                [],
                $e
            );
        }
    }

    /**
     * Execute a request and return the raw response (for file downloads / SSE streams).
     *
     * @throws EasyOCRException
     */
    protected function requestRaw(string $method, string $uri, array $options = []): ResponseInterface
    {
        try {
            return $this->http->request($method, $uri, $options);
        } catch (ClientException $e) {
            $this->handleClientError($e);
        } catch (ServerException $e) {
            $body = $this->safeDecodeBody($e->getResponse());
            throw new EasyOCRException(
                $body['error']['message'] ?? 'Error interno del servidor',
                $e->getResponse()->getStatusCode(),
                $body['error']['code'] ?? 'SERVER_ERROR',
                $body,
                $e
            );
        } catch (ConnectException $e) {
            throw new EasyOCRException(
                'No se pudo conectar con la API de EasyOCR: ' . $e->getMessage(),
                0,
                'CONNECTION_ERROR',
                [],
                $e
            );
        } catch (GuzzleException $e) {
            throw new EasyOCRException(
                'Error de red: ' . $e->getMessage(),
                0,
                'NETWORK_ERROR',
                [],
                $e
            );
        }
    }

    /**
     * Decode JSON response body.
     */
    protected function decode(ResponseInterface $response): array
    {
        $body = $response->getBody()->getContents();

        // Strip UTF-8 BOM if present (EF BB BF)
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
        }

        if (empty($body)) {
            return [];
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = mb_substr($body, 0, 200);
            throw new EasyOCRException(
                'Respuesta inválida del servidor (JSON malformado). json_last_error: '
                    . json_last_error_msg() . '. HTTP ' . $response->getStatusCode()
                    . '. Body preview: ' . $preview,
                $response->getStatusCode(),
                'INVALID_RESPONSE'
            );
        }

        return $data ?? [];
    }

    /**
     * Map 4xx HTTP errors to typed exceptions.
     * 
     * @throws AuthenticationException
     * @throws NotFoundException
     * @throws ValidationException
     * @throws RateLimitException
     * @throws EasyOCRException
     */
    private function handleClientError(ClientException $e)
    {
        $response = $e->getResponse();
        $status   = $response->getStatusCode();
        $body     = $this->safeDecodeBody($response);
        $message  = $body['error']['message'] ?? $body['message'] ?? $e->getMessage();
        $code     = $body['error']['code'] ?? 'API_ERROR';

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($message, $status, $code, $body, $e);
        }
        
        if ($status === 404) {
            throw new NotFoundException($message, $body, $e);
        }
        
        if ($status === 422) {
            throw new ValidationException(
                $message,
                $body['errors'] ?? [],
                $body,
                $e
            );
        }
        
        if ($status === 429) {
            $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: 0);
            throw new RateLimitException(
                $message,
                $retryAfter > 0 ? $retryAfter : null,
                $body,
                $e
            );
        }
        
        throw new EasyOCRException($message, $status, $code, $body, $e);
    }

    /**
     * Safely decode an error response body without throwing.
     */
    private function safeDecodeBody($response)
    {
        if ($response === null) {
            return [];
        }
        try {
            $content = $response->getBody()->getContents();
            // Strip UTF-8 BOM if present
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }
            return json_decode($content, true) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
