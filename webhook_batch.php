<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       webhook_batch.php
 * \ingroup    easyocr
 * \brief      Webhook receiver for EasyOCR batch processing notifications
 *
 * This endpoint receives POST notifications from the EasyOCR API when a batch
 * document has been processed. The API will call this URL for each document
 * in the batch as it completes processing.
 *
 * URL format: webhook_batch.php?instance_id={unique_id}
 * - instance_id: Dolibarr instance unique identifier for security validation
 *
 * Expected payload format (JSON POST body):
 * {
 *   "event": "batch.document.completed" | "batch.completed" | "batch.document.failed",
 *   "batch_id": "uuid-string",
 *   "document": {                          // (present on document events)
 *     "document_id": "uuid",
 *     "filename": "factura.pdf",
 *     "status": "completed" | "failed",
 *     "pages": 2,
 *     "structured_data": { ... },
 *     "extracted_text": "..."
 *   },
 *   "batch": {
 *     "batch_id": "uuid",
 *     "name": "Facturas Enero",
 *     "status": "processing" | "completed" | "partial" | "failed",
 *     "total_documents": 10,
 *     "completed_documents": 5,
 *     "failed_documents": 0,
 *     "progress": 50
 *   },
 *   "timestamp": "2026-02-16T12:00:00Z"
 * }
 *
 * NOTE: The exact payload structure depends on the EasyOCR API implementation.
 * This receiver is designed to be flexible and will store whatever it receives.
 */

// Dolibarr context — no session, no menu, no CSRF
if (!defined('NOTOKENRENEWAL'))    define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))     define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))     define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))     define('NOREQUIREAJAX', '1');
if (!defined('NOREQUIRESOC'))      define('NOREQUIRESOC', '1');
if (!defined('NOCSRFCHECK'))       define('NOCSRFCHECK', '1');
if (!defined('NOREQUIREDB'))       define('NOREQUIREDB', '0');   // we need DB
if (!defined('NOLOGIN'))           define('NOLOGIN', '1');       // webhook has no user session

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) {
	http_response_code(500);
	echo json_encode(['error' => 'Dolibarr environment not available']);
	exit;
}

// ─── Debug: Save ALL input data to JSON file ─────────────────────────────
$debugDir = DOL_DATA_ROOT . '/easyocr/webhook_debug';
if (!is_dir($debugDir)) {
	dol_mkdir($debugDir);
}

// Read raw body
$rawBody = file_get_contents('php://input');

// Collect all input data
$debugData = array(
	'timestamp'        => date('Y-m-d H:i:s'),
	'method'           => $_SERVER['REQUEST_METHOD'],
	'remote_ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
	'request_uri'      => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
	'query_string'     => isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '',
	'GET'              => $_GET,
	'POST'             => $_POST,
	'headers'          => array(
		'content_type'    => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
		'content_length'  => isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : '',
		'user_agent'      => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
		'webhook_secret'  => isset($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? '***present***' : 'not-set',
		'authorization'   => isset($_SERVER['HTTP_AUTHORIZATION']) ? '***present***' : 'not-set',
	),
	'raw_body'         => $rawBody,
	'raw_body_length'  => strlen($rawBody),
	'parsed_json'      => null,
	'json_parse_error' => null,
);

// Try to parse JSON body
if (!empty($rawBody)) {
	$parsedJson = json_decode($rawBody, true);
	if (json_last_error() === JSON_ERROR_NONE) {
		$debugData['parsed_json'] = $parsedJson;
	} else {
		$debugData['json_parse_error'] = json_last_error_msg();
	}
}

// Save debug file with timestamp
$debugFile = $debugDir . '/webhook_' . date('Y-m-d_His') . '_' . uniqid() . '.json';
@file_put_contents($debugFile, json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ─── Validate instance_id ────────────────────────────────────────────────
$receivedInstanceId = isset($_GET['instance_id']) ? $_GET['instance_id'] : '';
$expectedInstanceId = !empty($dolibarr_main_instance_unique_id) ? $dolibarr_main_instance_unique_id : '';

if (empty($receivedInstanceId)) {
	http_response_code(400);
	echo json_encode(['error' => 'Missing instance_id parameter']);
	exit;
}

if (empty($expectedInstanceId)) {
	// Instance ID not configured in Dolibarr - log warning but continue
	$warnMsg = 'WARNING: dolibarr_main_instance_unique_id not configured in conf.php';
	@file_put_contents($debugFile, "\n" . $warnMsg, FILE_APPEND);
} else {
	// Verify instance_id matches
	if (!hash_equals($expectedInstanceId, $receivedInstanceId)) {
		http_response_code(403);
		echo json_encode([
			'error' => 'Invalid instance_id',
			'received' => $receivedInstanceId,
			'expected_length' => strlen($expectedInstanceId),
		]);
		exit;
	}
}

// ─── Only accept POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(['error' => 'Method Not Allowed']);
	exit;
}

// ─── Optional: verify webhook secret ─────────────────────────────────────
// If configured, validate the X-Webhook-Secret header against our stored secret
$webhookSecret = !empty($conf->global->EASYOCR_WEBHOOK_SECRET) ? $conf->global->EASYOCR_WEBHOOK_SECRET : '';
if (!empty($webhookSecret)) {
	$receivedSecret = isset($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? $_SERVER['HTTP_X_WEBHOOK_SECRET'] : '';
	if (empty($receivedSecret) || !hash_equals($webhookSecret, $receivedSecret)) {
		http_response_code(403);
		echo json_encode(['error' => 'Invalid webhook secret']);
		exit;
	}
}

// ─── Validate JSON body ──────────────────────────────────────────────────
if (empty($rawBody)) {
	http_response_code(400);
	echo json_encode(['error' => 'Empty request body']);
	exit;
}

$payload = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
	http_response_code(400);
	echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
	exit;
}

// ─── Log webhook to file for debugging ───────────────────────────────────
$logDir = DOL_DATA_ROOT . '/easyocr/webhook_logs';
if (!is_dir($logDir)) {
	dol_mkdir($logDir);
}

$logEntry = array(
	'received_at' => date('Y-m-d H:i:s'),
	'remote_ip'   => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
	'instance_id' => $receivedInstanceId,
	'headers'     => array(
		'content_type'    => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
		'user_agent'      => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
		'webhook_secret'  => !empty($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? '***set***' : 'not-set',
	),
	'payload' => $payload,
);

$logFile = $logDir . '/webhook_' . date('Y-m-d') . '.log';
$logLine = date('H:i:s') . ' | ' . json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ─── Extract key data from payload ───────────────────────────────────────
$event    = isset($payload['event']) ? $payload['event'] : '';
$batchId  = isset($payload['batch_id']) ? $payload['batch_id'] : (isset($payload['batch']['batch_id']) ? $payload['batch']['batch_id'] : '');
$document = isset($payload['document']) ? $payload['document'] : null;
$batch    = isset($payload['batch']) ? $payload['batch'] : null;

// ─── Store in database ──────────────────────────────────────────────────
// Insert webhook event into llx_easyocr_webhook_log table
$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log";
$sql .= " (batch_id, event, document_id, document_filename, document_status,";
$sql .= " batch_status, batch_progress, payload, datec)";
$sql .= " VALUES (";
$sql .= " '" . $db->escape($batchId) . "',";
$sql .= " '" . $db->escape($event) . "',";
$sql .= " '" . $db->escape($document ? (isset($document['document_id']) ? $document['document_id'] : '') : '') . "',";
$sql .= " '" . $db->escape($document ? (isset($document['filename']) ? $document['filename'] : '') : '') . "',";
$sql .= " '" . $db->escape($document ? (isset($document['status']) ? $document['status'] : '') : '') . "',";
$sql .= " '" . $db->escape($batch ? (isset($batch['status']) ? $batch['status'] : '') : '') . "',";
$sql .= " " . ($batch && isset($batch['progress']) ? (int) $batch['progress'] : 0) . ",";
$sql .= " '" . $db->escape($rawBody) . "',";
$sql .= " NOW()";
$sql .= ")";

$dbResult = $db->query($sql);
if (!$dbResult) {
	// Log DB error but still return 200 to avoid retries
	$errMsg = 'DB insert failed: ' . $db->lasterror();
	@file_put_contents($logFile, date('H:i:s') . ' | ERROR: ' . $errMsg . "\n", FILE_APPEND | LOCK_EX);
}

// ─── Respond OK ──────────────────────────────────────────────────────────
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
	'status'   => 'ok',
	'message'  => 'Webhook received',
	'batch_id' => $batchId,
	'event'    => $event,
	'instance_id_validated' => !empty($expectedInstanceId),
]);
