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
 * Optional fields (when include_original_document is enabled on the batch):
 *   document.original_document: {
 *     filename: "factura.pdf",
 *     mime_type: "application/pdf",
 *     size_bytes: 123456,
 *     base64: "JVBERi0x..."   <- decoded and attached to the created invoice
 *   }
 *
 * NOTE: base64 content is stripped from logs/DB storage to avoid bloating.
 * NOTE: The exact payload structure depends on the EasyOCR API implementation.
 * This receiver is designed to be flexible and will store whatever it receives.
 */

// Dolibarr context — no session, no menu, no CSRF
if (!defined('NOTOKENRENEWAL'))    define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU'))     define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))     define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))     define('NOREQUIREAJAX', '1');
// NOTE: Do NOT define NOREQUIRESOC — we need Societe class + $mysoc for invoice creation
// NOTE: Do NOT define NOREQUIREDB — we need $db for database operations
if (!defined('NOCSRFCHECK'))       define('NOCSRFCHECK', '1');
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
if (!@is_dir($debugDir)) {
	@mkdir($debugDir, 0755, true); // Use native mkdir to avoid dol_mkdir open_basedir issue
}

// Read raw body
$rawBody = file_get_contents('php://input');

/**
 * Strip large base64 blobs from a payload array (recursive).
 * Replaces 'base64' keys and strings over 65000 chars with placeholders,
 * to avoid bloating logs, debug files and DB storage.
 */
function easyocrSanitizePayloadForStorage($data, $depth = 0)
{
	if ($depth > 8 || !is_array($data)) return $data;
	$clean = array();
	foreach ($data as $key => $value) {
		if ($key === 'base64' && is_string($value) && strlen($value) > 100) {
			$clean[$key] = '[base64 stripped, ' . strlen($value) . ' chars ~' . round(strlen($value) * 3 / 4 / 1024) . 'KB]';
		} elseif (is_array($value)) {
			$clean[$key] = easyocrSanitizePayloadForStorage($value, $depth + 1);
		} elseif (is_string($value) && strlen($value) > 65000) {
			$clean[$key] = substr($value, 0, 500) . '... [truncated, total ' . strlen($value) . ' chars]';
		} else {
			$clean[$key] = $value;
		}
	}
	return $clean;
}

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
	// raw_body and raw_body_length are set below after sanitization
	'parsed_json'      => null,
	'json_parse_error' => null,
);

// Try to parse JSON body
if (!empty($rawBody)) {
	$parsedJson = json_decode($rawBody, true);
	if (json_last_error() === JSON_ERROR_NONE) {
		// Strip base64 blobs from debug storage to avoid huge files
		$debugData['parsed_json'] = easyocrSanitizePayloadForStorage($parsedJson);
	} else {
		$debugData['json_parse_error'] = json_last_error_msg();
	}
}
// Strip base64 from raw_body preview too (PDFs can be several MB)
$debugData['raw_body'] = (strlen($rawBody) > 2000)
	? substr($rawBody, 0, 500) . '... [body truncated for debug, total ' . strlen($rawBody) . ' bytes]'
	: $rawBody;
$debugData['raw_body_length'] = strlen($rawBody);

// Save debug file with timestamp
$debugFile = $debugDir . '/webhook_' . date('Y-m-d_His') . '_' . uniqid() . '.json';
@file_put_contents($debugFile, json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ─── Global error handler to catch fatal errors ─────────────────────────
// This ensures we always return a JSON response even on PHP fatal errors
// IMPORTANT: Respect @ error suppression — on PHP 8.0+ the @ operator
// no longer sets error_reporting() to 0, so we must check the bitmask.
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false; // Respect @ suppression and current error_reporting level
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

// ─── Validate instance_id ────────────────────────────────────────────────
$receivedInstanceId = isset($_GET['instance_id']) ? $_GET['instance_id'] : '';
// Try raw conf.php variable first, then $conf object fallback
$expectedInstanceId = !empty($dolibarr_main_instance_unique_id) ? $dolibarr_main_instance_unique_id : '';
if (empty($expectedInstanceId) && isset($conf) && is_object($conf) && !empty($conf->file->instance_unique_id)) {
	$expectedInstanceId = $conf->file->instance_unique_id;
}

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
$webhookSecret = '';
if (isset($conf) && is_object($conf) && isset($conf->global) && is_object($conf->global) && !empty($conf->global->EASYOCR_WEBHOOK_SECRET)) {
	$webhookSecret = $conf->global->EASYOCR_WEBHOOK_SECRET;
}
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
if (!@is_dir($logDir)) {
	@mkdir($logDir, 0755, true); // Use native mkdir to avoid dol_mkdir open_basedir issue
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
	// Strip base64 blobs — PDFs in base64 can be several MB
	'payload' => easyocrSanitizePayloadForStorage($payload),
);

$logFile = $logDir . '/webhook_' . date('Y-m-d') . '.log';
$logLine = date('H:i:s') . ' | ' . json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ─── Extract key data from payload ───────────────────────────────────────
$event    = isset($payload['event']) ? $payload['event'] : '';
$batchId  = isset($payload['batch_id']) ? $payload['batch_id'] : (isset($payload['batch']['batch_id']) ? $payload['batch']['batch_id'] : '');
$document = isset($payload['document']) ? $payload['document'] : null;
$batch    = isset($payload['batch']) ? $payload['batch'] : null;

// DEBUG: Log extracted fields
@file_put_contents($logFile, date('H:i:s') . " | DEBUG-FLOW: event='$event', batchId='$batchId', document=" . ($document ? 'present(keys:' . implode(',', array_keys($document)) . ')' : 'NULL') . ", batch=" . ($batch ? 'present' : 'NULL') . "\n", FILE_APPEND | LOCK_EX);

// Initialize processing result variables
$webhookProcessingStatus = null;
$webhookProcessingMessage = null;
$webhookInvoiceId = null;
$webhookInvoiceRef = null;
$webhookSupplierId = null;

// ─── Process document.completed event ────────────────────────────────────
// When a document is completed, automatically create an invoice
$_condEvent = ($event === 'batch.document.completed');
$_condDoc   = !empty($document);
$_condStat  = (isset($document['status']) && $document['status'] === 'completed');
@file_put_contents($logFile, date('H:i:s') . " | DEBUG-FLOW: Conditions: event_match=$_condEvent, doc_present=$_condDoc, status_completed=$_condStat" . ($document ? ', document[status]=' . ($document['status'] ?? 'NOT_SET') : '') . "\n", FILE_APPEND | LOCK_EX);

if ($_condEvent && $_condDoc && $_condStat) {
	
	@file_put_contents($logFile, date('H:i:s') . " | DEBUG-FLOW: >>> ENTERED processing block\n", FILE_APPEND | LOCK_EX);

	// Load necessary libraries
	require_once __DIR__ . '/lib/easyocr.lib.php';
	
	// Extract structured data — check both document-level and data-level paths
	$structuredData = array();
	$_sdSource = 'none';
	if (isset($document['structured_data']) && !empty($document['structured_data'])) {
		$structuredData = $document['structured_data'];
		$_sdSource = 'document.structured_data';
	} elseif (isset($payload['data']['structured_data']) && !empty($payload['data']['structured_data'])) {
		$structuredData = $payload['data']['structured_data'];
		$_sdSource = 'payload.data.structured_data';
	} elseif (isset($payload['data']) && is_array($payload['data'])) {
		// The entire 'data' may BE the structured_data
		$structuredData = $payload['data'];
		$_sdSource = 'payload.data (whole)';
	}

	@file_put_contents($logFile, date('H:i:s') . " | DEBUG-FLOW: structured_data source='$_sdSource', empty=" . (empty($structuredData) ? 'YES' : 'NO') . ', keys=' . (is_array($structuredData) ? implode(',', array_keys($structuredData)) : 'NOT_ARRAY') . "\n", FILE_APPEND | LOCK_EX);

	if (!empty($structuredData)) {
		// Log that we're processing the document
		@file_put_contents($logFile, date('H:i:s') . ' | INFO: Processing completed document: ' . (isset($document['filename']) ? $document['filename'] : 'unknown') . "\n", FILE_APPEND | LOCK_EX);
		
		$webhookTempFile = ''; // must be initialized before try so cleanup always works
		try {
			// Map structured_data to params format expected by easyocrCreateInvoiceFromOCR
			$supplier = isset($structuredData['supplier']) ? $structuredData['supplier'] : array();
			$totals   = isset($structuredData['totals']) ? $structuredData['totals'] : array();
			$payment  = isset($structuredData['payment']) ? $structuredData['payment'] : array();

			$params = array(
				'fk_soc'           => 0, // Auto-detect from tax_id
				'ref_supplier'     => isset($structuredData['document_number']) ? $structuredData['document_number'] : '',
				'datef'            => isset($structuredData['issue_date']) ? $structuredData['issue_date'] : date('Y-m-d'),
				'total_ttc'        => isset($totals['total_payable']) ? $totals['total_payable'] : (isset($totals['total']) ? $totals['total'] : '0'),
				'total_ht'         => isset($totals['net_subtotal']) ? $totals['net_subtotal'] : (isset($totals['subtotal']) ? $totals['subtotal'] : '0'),
				'total_tva'        => isset($totals['tax_total']) ? $totals['tax_total'] : '',
				'total_localtax1'  => isset($totals['localtax1']) ? $totals['localtax1'] : '0',
				'total_localtax2'  => isset($totals['localtax2']) ? $totals['localtax2'] : '0',
				'date_echeance'    => isset($structuredData['due_date']) ? $structuredData['due_date'] : (isset($payment['due_date']) ? $payment['due_date'] : ''),
				'notes'            => isset($structuredData['notes']) ? $structuredData['notes'] : ('Webhook batch: ' . $batchId),
				'items'            => isset($structuredData['items']) ? $structuredData['items'] : array(),
				'default_tax_rate' => 0,
				'supplier_name'    => isset($supplier['name']) ? $supplier['name'] : '',
				'supplier_tax_id'  => isset($supplier['tax_id']) ? $supplier['tax_id'] : '',
				'supplier_address' => isset($supplier['address']) ? $supplier['address'] : '',
				'supplier_city'    => isset($supplier['city']) ? $supplier['city'] : '',
				'supplier_zip'     => isset($supplier['zip']) ? $supplier['zip'] : (isset($supplier['postal_code']) ? $supplier['postal_code'] : ''),
				'supplier_country' => isset($supplier['country']) ? $supplier['country'] : '',
				'supplier_phone'   => isset($supplier['phone']) ? $supplier['phone'] : '',
				'supplier_email'   => isset($supplier['email']) ? $supplier['email'] : '',
				'invoice_status'   => '', // Use module config default
				'invoice_type'     => 0,
				'journal_code'     => '',
				'import_key'       => 'easyocr-webhook',
				'create_payment'   => '',
				'payment_bank_id'  => 0,
				'payment_type_id'  => 0,
			);

			// ── Extract original PDF from base64 if present ─────────────────
			// The API can include the original document encoded in base64
			// Check both document-level and payload-level paths
			$originalDoc = null;
			if (isset($document['original_document']) && !empty($document['original_document']['base64'])) {
				$originalDoc = $document['original_document'];
			} elseif (isset($payload['original_document']) && !empty($payload['original_document']['base64'])) {
				$originalDoc = $payload['original_document'];
			}

			if (!empty($originalDoc)) {
				$pdfData = base64_decode($originalDoc['base64'], true);
				if ($pdfData !== false && strlen($pdfData) > 0) {
					// Save to temp file
					$tempDir = DOL_DATA_ROOT . '/easyocr/temp';
					if (!@is_dir($tempDir)) {
						@mkdir($tempDir, 0755, true);
					}
					$origFilename = !empty($originalDoc['filename']) ? $originalDoc['filename'] : (!empty($document['filename']) ? $document['filename'] : 'document.pdf');
					$webhookTempFile = $tempDir . '/webhook_' . date('Ymd_His') . '_' . uniqid() . '_' . dol_sanitizeFileName($origFilename);
					if (@file_put_contents($webhookTempFile, $pdfData) !== false) {
						$params['file_tmp_path'] = $webhookTempFile;
						$params['file_name'] = $origFilename;
						@file_put_contents($logFile, date('H:i:s') . ' | INFO: Decoded original PDF from base64 (' . strlen($pdfData) . " bytes) => $webhookTempFile\n", FILE_APPEND | LOCK_EX);
					} else {
						@file_put_contents($logFile, date('H:i:s') . " | WARNING: Failed to write decoded PDF to temp file\n", FILE_APPEND | LOCK_EX);
					}
					unset($pdfData); // Free memory
				} else {
					@file_put_contents($logFile, date('H:i:s') . " | WARNING: base64_decode failed for original_document\n", FILE_APPEND | LOCK_EX);
				}
			}

			// Log params for debugging
			@file_put_contents($logFile, date('H:i:s') . ' | DEBUG-PARAMS: ' . json_encode(array(
				'ref_supplier' => $params['ref_supplier'],
				'datef' => $params['datef'],
				'supplier_name' => $params['supplier_name'],
				'supplier_tax_id' => $params['supplier_tax_id'],
				'total_ht' => $params['total_ht'],
				'total_ttc' => $params['total_ttc'],
				'total_tva' => $params['total_tva'],
				'items_count' => count($params['items']),
				'items_sample' => !empty($params['items']) ? array_slice($params['items'], 0, 2) : [],
				'has_pdf' => !empty($params['file_tmp_path']),
				'pdf_filename' => isset($params['file_name']) ? $params['file_name'] : '',
			), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

			// Check globals before calling
			@file_put_contents($logFile, date('H:i:s') . ' | DEBUG-GLOBALS: $db=' . (isset($GLOBALS['db']) && is_object($GLOBALS['db']) ? 'OK(class=' . get_class($GLOBALS['db']) . ')' : 'NULL') . ', $conf=' . (isset($GLOBALS['conf']) ? 'OK' : 'NULL') . ', $mysoc=' . (isset($GLOBALS['mysoc']) ? 'OK' : 'NULL') . ', $langs=' . (isset($GLOBALS['langs']) ? 'OK' : 'NULL') . "\n", FILE_APPEND | LOCK_EX);

			// Call shared invoice creation function (same logic as AJAX newInvoiceAI)
			@file_put_contents($logFile, date('H:i:s') . " | DEBUG-FLOW: >>> Calling easyocrCreateInvoiceFromOCR()...\n", FILE_APPEND | LOCK_EX);
			$webhookResult = easyocrCreateInvoiceFromOCR($params);
			@file_put_contents($logFile, date('H:i:s') . ' | DEBUG-FLOW: <<< Result: ' . json_encode($webhookResult, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
			
			// Store processing result
			$webhookProcessingStatus = $webhookResult['status'];
			$webhookProcessingMessage = isset($webhookResult['message']) ? $webhookResult['message'] : '';
			$webhookInvoiceId = isset($webhookResult['invoice_id']) ? $webhookResult['invoice_id'] : null;
			$webhookInvoiceRef = isset($webhookResult['invoice_ref']) ? $webhookResult['invoice_ref'] : null;
			$webhookSupplierId = isset($webhookResult['supplier_id']) ? $webhookResult['supplier_id'] : null;
			
			if ($webhookProcessingStatus === 'ok') {
				@file_put_contents($logFile, date('H:i:s') . ' | SUCCESS: Invoice created: ' . $webhookInvoiceId . ' (' . $webhookInvoiceRef . ")\n", FILE_APPEND | LOCK_EX);
			} elseif ($webhookProcessingStatus === 'repeat') {
				// Duplicate detected — not an error, just a skip
				$webhookInvoiceId = isset($webhookResult['existing_id']) ? $webhookResult['existing_id'] : null;
				$webhookInvoiceRef = isset($webhookResult['existing_ref']) ? $webhookResult['existing_ref'] : null;
				$webhookSupplierId = isset($webhookResult['supplier_id']) ? $webhookResult['supplier_id'] : null;
				$webhookProcessingMessage = isset($webhookResult['message']) ? $webhookResult['message'] : 'Duplicate invoice';
				@file_put_contents($logFile, date('H:i:s') . ' | SKIP-DUPLICATE: ' . $webhookProcessingMessage . ' (existing: ' . $webhookInvoiceRef . ", id=" . $webhookInvoiceId . ")\n", FILE_APPEND | LOCK_EX);
			} else {
				@file_put_contents($logFile, date('H:i:s') . ' | ERROR: Processing failed: ' . $webhookProcessingMessage . "\n", FILE_APPEND | LOCK_EX);
			}
		} catch (Throwable $e) {
			$webhookProcessingStatus = 'error';
			$webhookProcessingMessage = get_class($e) . ': ' . $e->getMessage();
			@file_put_contents($logFile, date('H:i:s') . ' | EXCEPTION [' . get_class($e) . ']: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
		}

		// Clean up temp PDF file (already copied to invoice dir by the lib)
		if (!empty($webhookTempFile) && file_exists($webhookTempFile)) {
			@unlink($webhookTempFile);
		}
	} else {
		@file_put_contents($logFile, date('H:i:s') . " | WARNING: No structured_data found in webhook payload\n", FILE_APPEND | LOCK_EX);
	}
}

// ─── Store in database ──────────────────────────────────────────────────
// Verify $db is available before attempting DB operations
if (!isset($db) || !is_object($db)) {
	// No database connection - log to file and return OK anyway
	@file_put_contents($logFile, date('H:i:s') . " | WARNING: \$db not available, skipping DB insert\n", FILE_APPEND | LOCK_EX);
} else {
	// Insert webhook event into llx_easyocr_webhook_log table
	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log";
	$sql .= " (batch_id, event, document_id, document_filename, document_status,";
	$sql .= " batch_status, batch_progress, invoice_id, invoice_ref, supplier_id,";
	$sql .= " processing_status, processing_message, payload, datec)";
	$sql .= " VALUES (";
	$sql .= " '" . $db->escape($batchId) . "',";
	$sql .= " '" . $db->escape($event) . "',";
	$sql .= " '" . $db->escape($document ? (isset($document['document_id']) ? $document['document_id'] : '') : '') . "',";
	$sql .= " '" . $db->escape($document ? (isset($document['filename']) ? $document['filename'] : '') : '') . "',";
	$sql .= " '" . $db->escape($document ? (isset($document['status']) ? $document['status'] : '') : '') . "',";
	$sql .= " '" . $db->escape($batch ? (isset($batch['status']) ? $batch['status'] : '') : '') . "',";
	$sql .= " " . ($batch && isset($batch['progress']) ? (int) $batch['progress'] : 0) . ",";
	$sql .= " " . (!empty($webhookInvoiceId) ? (int) $webhookInvoiceId : 'NULL') . ",";
	$sql .= " " . (!empty($webhookInvoiceRef) ? "'" . $db->escape($webhookInvoiceRef) . "'" : 'NULL') . ",";
	$sql .= " " . (!empty($webhookSupplierId) ? (int) $webhookSupplierId : 'NULL') . ",";
	$sql .= " " . (!empty($webhookProcessingStatus) ? "'" . $db->escape($webhookProcessingStatus) . "'" : 'NULL') . ",";
	$sql .= " " . (!empty($webhookProcessingMessage) ? "'" . $db->escape($webhookProcessingMessage) . "'" : 'NULL') . ",";
	// Strip base64 from stored payload — TEXT columns max ~65KB, PDFs in base64 are much larger
	$payloadForDb = json_encode(easyocrSanitizePayloadForStorage($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$sql .= " '" . $db->escape($payloadForDb) . "',";
	$sql .= " NOW()";
	$sql .= ")";

	$dbResult = $db->query($sql);
	if (!$dbResult) {
		// Log DB error but still return 200 to avoid retries
		$errMsg = 'DB insert failed: ' . $db->lasterror();
		@file_put_contents($logFile, date('H:i:s') . ' | ERROR: ' . $errMsg . "\n", FILE_APPEND | LOCK_EX);
	}
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

} catch (Throwable $e) {
	// ─── Catch ANY error (including fatal) and return informative JSON ────
	$errorInfo = [
		'error'   => 'Internal server error in webhook_batch.php',
		'message' => $e->getMessage(),
		'file'    => basename($e->getFile()),
		'line'    => $e->getLine(),
		'type'    => get_class($e),
	];

	// Try to log the error
	$crashFile = (defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : '/tmp') . '/easyocr/webhook_debug/crash_' . date('Y-m-d_His') . '.json';
	@file_put_contents($crashFile, json_encode($errorInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

	http_response_code(500);
	header('Content-Type: application/json');
	echo json_encode($errorInfo);
}
