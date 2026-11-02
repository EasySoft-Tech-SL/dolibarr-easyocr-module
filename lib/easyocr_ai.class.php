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
 *   EASYOCR_AI_URL        - Base URL of OCR API (https://app.easyocr.es by default;
 *                           plain http only for localhost / 127.0.0.1)
 *   EASYOCR_AI_APIKEY     - API key for X-API-Key header
 *   EASYOCR_AI_INSECURE_TLS - '1' turns certificate checks off (development only, logged)
 *
 * The endpoints are:
 *   POST {base}/api/v1/ocr/file     - multipart file upload
 *   POST {base}/api/v1/ocr/base64   - JSON body with base64_data
 */
class EasyOcrAI
{
	/** Default service URL */
	const DEFAULT_URL = 'https://app.easyocr.es';

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
		$this->baseUrl = rtrim(!empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : self::DEFAULT_URL, '/');
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
	public function processBase64($base64Data, $customInstructions = '', $filename = '', $preprocess = false)
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
		// Filename lets the API pick the right type (magic-byte validation).
		// Without it the endpoint assumes .pdf → images (photos) are rejected (415).
		if (!empty($filename)) {
			$payload['filename'] = $filename;
		}
		// Preprocess (contrast/grayscale/sharpening) improves OCR on phone photos.
		if ($preprocess) {
			$payload['preprocess'] = true;
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
		) + self::curlTlsOptions());

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		if (PHP_VERSION_ID < 80000) {
			curl_close($ch);
		}

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

	/**
	 * TLS options for every cURL call of the module.
	 *
	 * Certificates are always checked. Only the constant EASYOCR_AI_INSECURE_TLS=1 (a
	 * development box with a self-signed certificate) turns the check off, and it leaves
	 * a warning in the Dolibarr log each time.
	 *
	 * @return array<int, mixed>
	 */
	public static function curlTlsOptions()
	{
		if (getDolGlobalString('EASYOCR_AI_INSECURE_TLS') === '1') {
			dol_syslog('EasyOCR: TLS certificate verification DISABLED by EASYOCR_AI_INSECURE_TLS=1 (development only)', LOG_WARNING);
			return array(
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
			);
		}
		return array(
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		);
	}

	/**
	 * Whether a service URL may be saved: https, or plain http only towards this machine.
	 *
	 * @param  string $url URL typed in the setup
	 * @return bool
	 */
	public static function isAllowedServiceUrl($url)
	{
		$url = trim((string) $url);
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			return false;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
			return false;
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}
		if (strtolower($parts['scheme']) === 'https') {
			return true;
		}
		$host = strtolower(trim($parts['host'], '[]'));
		return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
	}

	/**
	 * Turn the outcome of the account check into something a person can read. Pure.
	 *
	 * @param  int        $httpStatus HTTP status (0 = no connection)
	 * @param  string     $errorCode  Error code of the client library ('' on success)
	 * @param  array|null $data       'data' part of /api/v1/me on success
	 * @return array{code:string,pages:int|null}  code: ok|invalid_key|no_connection|service_down|error
	 */
	public static function classifyAccountCheck($httpStatus, $errorCode, $data)
	{
		$httpStatus = (int) $httpStatus;
		if ($httpStatus >= 200 && $httpStatus < 300 && is_array($data)) {
			$pages = null;
			if (isset($data['quota']['pages_available_now'])) {
				$pages = (int) $data['quota']['pages_available_now'];
			} elseif (isset($data['quota']['pages_remaining'])) {
				$pages = (int) $data['quota']['pages_remaining'];
			}
			return array('code' => 'ok', 'pages' => $pages);
		}
		if ($httpStatus === 401 || $httpStatus === 403 || $errorCode === 'INVALID_API_KEY') {
			return array('code' => 'invalid_key', 'pages' => null);
		}
		if ($httpStatus === 0 || $errorCode === 'CONNECTION_ERROR') {
			return array('code' => 'no_connection', 'pages' => null);
		}
		if ($httpStatus >= 500) {
			return array('code' => 'service_down', 'pages' => null);
		}
		return array('code' => 'error', 'pages' => null);
	}

	/**
	 * Ask the service who we are (GET /api/v1/me, the call batch.php already makes) and
	 * classify the answer.
	 *
	 * @param  string $baseUrl Service URL
	 * @param  string $apiKey  API key
	 * @param  int    $timeout Seconds
	 * @return array{code:string,pages:int|null}
	 */
	public static function checkAccount($baseUrl, $apiKey, $timeout = 15)
	{
		if (trim((string) $apiKey) === '') {
			return array('code' => 'invalid_key', 'pages' => null);
		}
		require_once __DIR__ . '/easyocr_autoload.php';
		try {
			$client = new \EasySoft\EasyOCR\EasyOCRClient((string) $apiKey, array('base_url' => (string) $baseUrl, 'timeout' => (int) $timeout));
			$me = $client->account()->me();
			return self::classifyAccountCheck(200, '', (isset($me['data']) && is_array($me['data'])) ? $me['data'] : array());
		} catch (\EasySoft\EasyOCR\Exceptions\EasyOCRException $e) {
			return self::classifyAccountCheck($e->getHttpStatus(), $e->getErrorCode(), null);
		} catch (\Throwable $e) {
			return array('code' => 'error', 'pages' => null);
		}
	}
}
