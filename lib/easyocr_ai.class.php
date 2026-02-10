<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       lib/easyocr_ai.class.php
 * \ingroup    easyocr
 * \brief      AI OCR service client - easily swappable endpoint
 *
 * Configuration keys (stored in llx_const via admin/setup.php):
 *   EASYOCR_AI_ENABLED    - '1' to enable, '0' to disable
 *   EASYOCR_AI_URL        - Base URL of OCR API (e.g. http://127.0.0.1:8000)
 *   EASYOCR_AI_APIKEY     - API key for X-API-Key header
 *
 * The endpoints are:
 *   POST {base}/api/v1/ocr/file     - multipart file upload
 *   POST {base}/api/v1/ocr/base64   - JSON body with base64_data
 */
class EasyOcrAI
{
	/** @var string Base URL of the OCR service */
	private $baseUrl;

	/** @var string API key */
	private $apiKey;

	/** @var bool Whether AI is enabled */
	private $enabled;

	/** @var string Last error message */
	public $error = '';

	/** @var int Timeout in seconds */
	private $timeout = 120;

	/**
	 * Constructor. Reads config from Dolibarr global constants.
	 *
	 * @param DoliDB $db Database handler (unused, for future)
	 */
	public function __construct($db = null)
	{
		global $conf;

		$this->enabled = !empty($conf->global->EASYOCR_AI_ENABLED);
		$this->baseUrl = rtrim(!empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'http://127.0.0.1:8000', '/');
		$this->apiKey  = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
		$this->timeout = !empty($conf->global->EASYOCR_AI_TIMEOUT) ? (int) $conf->global->EASYOCR_AI_TIMEOUT : 120;
	}

	/**
	 * Check if AI service is enabled and configured.
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		return $this->enabled && !empty($this->baseUrl) && !empty($this->apiKey);
	}

	/**
	 * Get the base URL (for frontend display in settings).
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->baseUrl;
	}

	/**
	 * Get the API key (for frontend SSE direct connection).
	 *
	 * @return string
	 */
	public function getApiKey()
	{
		return $this->apiKey;
	}

	/**
	 * Process a PDF file via the /api/v1/ocr/file endpoint.
	 *
	 * @param  string $filePath Absolute path to the PDF file on disk
	 * @return array|false      Parsed response array, or false on error
	 */
	public function processFile($filePath)
	{
		if (!$this->isEnabled()) {
			$this->error = 'AI OCR service is not enabled or not configured';
			return false;
		}

		if (!file_exists($filePath)) {
			$this->error = 'File not found: ' . $filePath;
			return false;
		}

		$url = $this->baseUrl . '/api/v1/ocr/file';

		$curlFile = new CURLFile($filePath, 'application/pdf', basename($filePath));

		$postFields = array(
			'file' => $curlFile
		);

		return $this->doRequest($url, $postFields, true);
	}

	/**
	 * Process a PDF via the /api/v1/ocr/base64 endpoint.
	 *
	 * @param  string $base64Data          Base64-encoded PDF content
	 * @param  string $customInstructions  Optional custom instructions for the AI model
	 * @return array|false                 Parsed response array, or false on error
	 */
	public function processBase64($base64Data, $customInstructions = '')
	{
		if (!$this->isEnabled()) {
			$this->error = 'AI OCR service is not enabled or not configured';
			return false;
		}

		$url = $this->baseUrl . '/api/v1/ocr/base64';

		$payload = array(
			'base64_data'  => $base64Data,
			'include_text' => false
		);

		if (!empty($customInstructions)) {
			$payload['custom_instructions'] = $customInstructions;
		}

		$jsonPayload = json_encode($payload);

		return $this->doRequest($url, $jsonPayload, false);
	}

	/**
	 * Execute the HTTP request to the OCR service.
	 *
	 * @param  string       $url         Full endpoint URL
	 * @param  mixed        $postData    Either array (multipart) or string (JSON)
	 * @param  bool         $isMultipart Whether this is a multipart upload
	 * @return array|false               Decoded JSON response, or false on error
	 */
	private function doRequest($url, $postData, $isMultipart = false)
	{
		$ch = curl_init();

		$headers = array(
			'X-API-Key: ' . $this->apiKey,
			'Accept: application/json',
		);

		if (!$isMultipart) {
			$headers[] = 'Content-Type: application/json';
		}

		curl_setopt_array($ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $postData,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => false, // Allow self-signed certs in dev
			CURLOPT_SSL_VERIFYHOST => 0,
		));

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($curlError) {
			$this->error = 'cURL error: ' . $curlError;
			return false;
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			$this->error = 'HTTP ' . $httpCode . ': ' . substr($response, 0, 500);
			return false;
		}

		$decoded = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error = 'Invalid JSON response: ' . json_last_error_msg();
			return false;
		}

		return $decoded;
	}
}
