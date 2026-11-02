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
 * in the batch as it completes processing (one document per request).
 *
 * URL format: webhook_batch.php?token={EASYOCR_WEBHOOK_TOKEN}[&entity=N]
 * - token:  secret of this module (40 hex chars, one per entity). It is NOT the
 *           instance unique id of Dolibarr: that one is the key of dolEncrypt() and
 *           must never travel in a URL. The service treats the URL as opaque.
 * - entity: only with multicompany.
 *
 * Legacy URLs (?instance_id=...) are still accepted until EASYOCR_WEBHOOK_LEGACY_UNTIL
 * (fixed to now + 30 days the first time one arrives). In legacy mode the invoice is
 * created but no payment is ever registered; after that date the answer is 403.
 *
 * Real payload format sent by EasyOCR API (JSON POST body):
 * {
 *   "event": "batch.document.completed" | "batch.completed" | "batch.document.failed",
 *   "timestamp": "2026-02-19T09:18:18+01:00",
 *   "data": {                              // document info is inside 'data', not 'document'
 *     "document_id": "uuid",
 *     "filename": "factura.pdf",
 *     "status": "completed" | "failed",
 *     "page_count": 2,
 *     "ocr_confidence": 1,
 *     "processing_time_ms": 11004,
 *     "structured_data": {
 *       "document_type": "invoice",
 *       "document_number": "B3186006",
 *       "issue_date": "2026-01-31",
 *       "due_date": null,
 *       "currency": "EUR",
 *       "supplier": { "name": "...", "tax_id": "...", "address": "...", ... },
 *       "customer": { ... },
 *       "items": [ ... ],
 *       "totals": { "net_subtotal": 0, "tax_total": 0, "total_payable": 0, ... }
 *     },
 *     "original_document": {               // optional, when enabled on the batch
 *       "filename": "factura.pdf",
 *       "mime_type": "application/pdf",
 *       "size_bytes": 123456,
 *       "base64": "JVBERi0x..."            // decoded and attached to the invoice
 *     }
 *   }
 * }
 *
 * NOTE: $payload['data'] is mapped to $document for backwards compatibility.
 * NOTE: base64 content is stripped from logs/DB storage to avoid bloating.
 *
 * The functions below are also used by admin/setup.php, batch.php, webhook_logs.php and
 * the tests: they include this file with EASYOCR_WEBHOOK_LIB_ONLY defined, which stops
 * right after the definitions, before anything runs.
 */

/** Days a legacy ?instance_id= URL keeps working after it is first seen. */
if (!defined('EASYOCR_WEBHOOK_LEGACY_DAYS')) {
	define('EASYOCR_WEBHOOK_LEGACY_DAYS', 30);
}
/** Largest debug file written per request (bytes). */
if (!defined('EASYOCR_WEBHOOK_DEBUG_MAX_BYTES')) {
	define('EASYOCR_WEBHOOK_DEBUG_MAX_BYTES', 262144);
}

if (!function_exists('easyocrWebhookError')) {

/**
 * Respond an error as plain JSON and stop.
 *
 * The header is the whole point: PHP defaults to text/html here, and a client that
 * validates the Content-Type (or a proxy that inspects it) would take the body for a
 * web page and drop it.
 *
 * @param int   $httpCode HTTP status to send
 * @param array $payload  Body to encode
 * @param array $headers  Extra headers ("Name: value")
 * @return void
 */
function easyocrWebhookError($httpCode, $payload, $headers = array())
{
	http_response_code($httpCode);
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		foreach ($headers as $h) {
			header($h);
		}
	}
	echo json_encode($payload);
	exit;
}

/**
 * Strip large base64 blobs from a payload array (recursive).
 * Replaces 'base64' keys and strings over 65000 chars with placeholders,
 * to avoid bloating logs, debug files and DB storage.
 *
 * @param mixed $data  Payload (or part of it)
 * @param int   $depth Recursion depth
 * @return mixed
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

/**
 * Hide the webhook secrets in a URL or a query string.
 *
 * @param  string $str Request URI or query string
 * @return string      Same text with token= and instance_id= values replaced by ***
 */
function easyocrWebhookMaskQuery($str)
{
	return preg_replace('/((?:^|[?&;])(?:token|instance_id)=)[^&;#]*/i', '$1***', (string) $str);
}

/**
 * Hide the webhook secrets in a parameter array ($_GET, $_REQUEST...).
 *
 * @param  array $params Parameters
 * @return array         Copy with token and instance_id masked
 */
function easyocrWebhookMaskParams($params)
{
	$out = array();
	foreach ((array) $params as $k => $v) {
		$out[$k] = (in_array(strtolower((string) $k), array('token', 'instance_id'), true) && $v !== '') ? '***' : $v;
	}
	return $out;
}

/**
 * Read a constant straight from llx_const for one entity.
 *
 * The webhook runs without a session: $conf->global holds the constants of the default
 * entity, not of the one in ?entity=N, so the token of entity N has to be read here.
 * A value encrypted by Dolibarr 19+ ("dolcrypt:") is decrypted.
 *
 * @param  DoliDB $db     Database handler
 * @param  string $name   Constant name
 * @param  int    $entity Entity
 * @return string         Value, '' when it does not exist
 */
function easyocrWebhookReadConst($db, $name, $entity)
{
	$sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
	$sql .= " WHERE name = '" . $db->escape($name) . "'";
	$sql .= " AND entity IN (0, " . ((int) $entity) . ")";
	$sql .= " ORDER BY entity DESC";
	$sql .= $db->plimit(1);
	$res = $db->query($sql);
	if (!$res) {
		return '';
	}
	$obj = $db->fetch_object($res);
	$db->free($res);
	if (!$obj) {
		return '';
	}
	$value = (string) $obj->value;
	if (strpos($value, 'dolcrypt:') === 0 && function_exists('dolDecrypt')) {
		$value = (string) dolDecrypt($value);
	}
	return $value;
}

/**
 * Store a constant for one entity and keep $conf->global in step when it is the current one.
 *
 * @param  DoliDB $db     Database handler
 * @param  string $name   Constant name
 * @param  string $value  Value
 * @param  int    $entity Entity
 * @return int            >0 if OK
 */
function easyocrWebhookSetConst($db, $name, $value, $entity)
{
	global $conf;

	if (!function_exists('dolibarr_set_const')) {
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
	}
	$res = dolibarr_set_const($db, $name, $value, 'chaine', 0, '', (int) $entity);
	if ($res > 0 && is_object($conf) && (int) $conf->entity === (int) $entity) {
		$conf->global->$name = $value;
	}
	return $res;
}

/**
 * New random webhook token: 40 hex chars (160 bits).
 *
 * @return string
 */
function easyocrWebhookNewToken()
{
	return bin2hex(random_bytes(20));
}

/**
 * Webhook token of an entity, created the first time it is asked for.
 *
 * @param  DoliDB $db     Database handler
 * @param  int    $entity Entity
 * @param  bool   $create Create it when missing
 * @return string         Token, '' if it does not exist and was not created
 */
function easyocrWebhookGetToken($db, $entity, $create = true)
{
	$token = easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_TOKEN', $entity);
	if ($token === '' && $create) {
		$token = easyocrWebhookNewToken();
		if (easyocrWebhookSetConst($db, 'EASYOCR_WEBHOOK_TOKEN', $token, $entity) <= 0) {
			return '';
		}
	}
	return $token;
}

/**
 * Replace the webhook token of an entity. Batches already sent with the old URL stop
 * being accepted.
 *
 * @param  DoliDB $db     Database handler
 * @param  int    $entity Entity
 * @return string         New token, '' on error
 */
function easyocrWebhookRegenerateToken($db, $entity)
{
	$token = easyocrWebhookNewToken();
	return easyocrWebhookSetConst($db, 'EASYOCR_WEBHOOK_TOKEN', $token, $entity) > 0 ? $token : '';
}

/**
 * Public URL of the webhook for the current entity (creates the token if needed).
 *
 * @param  DoliDB $db     Database handler
 * @param  bool   $create Create the token when missing
 * @return string         Full https URL, '' when there is no token
 */
function easyocrWebhookBuildUrl($db, $create = true)
{
	global $conf;

	$entity = (int) $conf->entity;
	$token = easyocrWebhookGetToken($db, $entity, $create);
	if ($token === '') {
		return '';
	}
	$url = dol_buildpath('/easyocr/webhook_batch.php', 2);
	// External callbacks must be secure
	$url = preg_replace('/^http:\/\//i', 'https://', $url);
	$url .= '?token=' . urlencode($token);
	$multicompany = function_exists('isModEnabled') ? isModEnabled('multicompany') : !empty($conf->multicompany->enabled);
	if ($multicompany) {
		$url .= '&entity=' . $entity;
	}
	return $url;
}

/**
 * Decide whether a webhook request is authenticated. Pure: no I/O.
 *
 * - token: compared with hash_equals against the entity token.
 * - instance_id (legacy): accepted only while the legacy window is open. With no window
 *   yet, the first legacy call opens it (set_legacy_until). Never pays.
 * - nothing configured or nothing received: refused.
 *
 * @param  string $receivedToken      ?token=
 * @param  string $receivedInstanceId ?instance_id=
 * @param  string $expectedToken      EASYOCR_WEBHOOK_TOKEN of the entity
 * @param  string $expectedInstanceId $dolibarr_main_instance_unique_id
 * @param  int    $legacyUntil        EASYOCR_WEBHOOK_LEGACY_UNTIL (timestamp, 0 = not set)
 * @param  int    $now                Current time
 * @return array{ok:bool,mode:string,reason:string,set_legacy_until:int}
 */
function easyocrWebhookAuthenticate($receivedToken, $receivedInstanceId, $expectedToken, $expectedInstanceId, $legacyUntil, $now)
{
	$out = array('ok' => false, 'mode' => '', 'reason' => 'auth_failed', 'set_legacy_until' => 0);
	$receivedToken = is_string($receivedToken) ? $receivedToken : '';
	$receivedInstanceId = is_string($receivedInstanceId) ? $receivedInstanceId : '';
	$expectedToken = (string) $expectedToken;
	$expectedInstanceId = (string) $expectedInstanceId;

	if ($receivedToken !== '') {
		if ($expectedToken !== '' && hash_equals($expectedToken, $receivedToken)) {
			$out['ok'] = true;
			$out['mode'] = 'token';
			$out['reason'] = '';
		}
		return $out;
	}

	if ($receivedInstanceId !== '') {
		if ($expectedInstanceId === '' || !hash_equals($expectedInstanceId, $receivedInstanceId)) {
			return $out;
		}
		$legacyUntil = (int) $legacyUntil;
		if ($legacyUntil <= 0) {
			$out['set_legacy_until'] = (int) $now + EASYOCR_WEBHOOK_LEGACY_DAYS * 86400;
		} elseif ((int) $now > $legacyUntil) {
			$out['reason'] = 'legacy_expired';
			return $out;
		}
		$out['ok'] = true;
		$out['mode'] = 'legacy';
		$out['reason'] = '';
		return $out;
	}

	return $out;
}

/**
 * Payment settings for this notification. Pure: no I/O.
 *
 * Module config first, then the query string (?pay=1&bank_id=N&payment_type=N), which
 * only counts when the request came with the token. A legacy request never pays.
 *
 * @param  string $authMode 'token' or 'legacy'
 * @param  array  $get      Query parameters
 * @param  array  $cfg      mark_paid, bank_id, payment_type from the module config
 * @return array{mark_paid:int,bank_id:int,payment_type:int,skip_reason:string}
 */
function easyocrWebhookPaymentSettings($authMode, $get, $cfg)
{
	$markPaid = !empty($cfg['mark_paid']) ? 1 : 0;
	$bankId = !empty($cfg['bank_id']) ? (int) $cfg['bank_id'] : 0;
	$payType = !empty($cfg['payment_type']) ? (int) $cfg['payment_type'] : 0;

	if ($authMode === 'token') {
		if (isset($get['pay'])) {
			$markPaid = ((int) $get['pay'] === 1) ? 1 : 0;
		}
		if (isset($get['bank_id']) && (int) $get['bank_id'] > 0) {
			$bankId = (int) $get['bank_id'];
		}
		if (isset($get['payment_type']) && (int) $get['payment_type'] > 0) {
			$payType = (int) $get['payment_type'];
		}
		return array('mark_paid' => $markPaid, 'bank_id' => $bankId, 'payment_type' => $payType, 'skip_reason' => '');
	}

	$asked = $markPaid || (isset($get['pay']) && (int) $get['pay'] === 1);
	return array('mark_paid' => 0, 'bank_id' => 0, 'payment_type' => 0, 'skip_reason' => $asked ? 'legacy' : '');
}

/**
 * Check the bank account and payment method of an automatic payment.
 *
 * @param  DoliDB $db      Database handler
 * @param  int    $bankId  Bank account id
 * @param  int    $payType Payment method id (0 = the module default)
 * @param  int    $entity  Entity of the notification
 * @return string          '' when valid, otherwise a reason code (no_bank, bank_not_found,
 *                         bank_closed, paytype_not_found)
 */
function easyocrWebhookCheckPayment($db, $bankId, $payType, $entity)
{
	if ((int) $bankId <= 0) {
		return 'no_bank';
	}
	require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
	// Account::fetch() only finds accounts of the current entity (or shared with it through
	// multicompany): one of another entity is "not found" (same SQL in Dolibarr 15 to 24)
	$acc = new Account($db);
	if ($acc->fetch((int) $bankId) <= 0) {
		return 'bank_not_found';
	}
	if (!empty($acc->clos)) {
		return 'bank_closed';
	}
	if ((int) $payType > 0) {
		$sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . ((int) $payType) . " AND active = 1";
		$sql .= " AND entity IN (" . (function_exists('getEntity') ? getEntity('c_paiement') : ((int) $entity)) . ")";
		$res = $db->query($sql);
		if (!$res || !$db->fetch_object($res)) {
			return 'paytype_not_found';
		}
	}
	return '';
}

/**
 * Id of the document inside a notification.
 *
 * @param  array|null $document Document part of the payload
 * @return string
 */
function easyocrWebhookDocumentId($document)
{
	if (!is_array($document)) {
		return '';
	}
	foreach (array('document_id', 'uuid', 'id') as $k) {
		if (isset($document[$k]) && is_scalar($document[$k]) && (string) $document[$k] !== '') {
			return substr((string) $document[$k], 0, 64);
		}
	}
	return '';
}

/**
 * Whether this document was already handled for this entity (idempotency).
 *
 * @param  DoliDB $db         Database handler
 * @param  int    $entity     Entity
 * @param  string $documentId Document id of the service
 * @return array|null         Row (rowid, invoice_id, invoice_ref, processing_status) or null
 */
function easyocrWebhookAlreadyProcessed($db, $entity, $documentId)
{
	if ((string) $documentId === '') {
		return null;
	}
	$sql = "SELECT rowid, invoice_id, invoice_ref, supplier_id, processing_status FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log";
	$sql .= " WHERE entity = " . ((int) $entity);
	$sql .= " AND document_id = '" . $db->escape($documentId) . "'";
	$sql .= " AND processing_status IN ('ok', 'repeat', 'review')";
	$sql .= " ORDER BY rowid ASC";
	$sql .= $db->plimit(1);
	$res = $db->query($sql);
	if (!$res) {
		return null;
	}
	$obj = $db->fetch_object($res);
	$db->free($res);
	return $obj ? (array) $obj : null;
}

/**
 * Final status of a processed document. Pure.
 *
 * 'ok' becomes 'review' when the invoice was left as a draft for review, has review
 * notes, or its requested payment was not registered.
 *
 * @param  array  $result            Return of easyocrCreateInvoiceFromOCR()
 * @param  string $paymentSkipReason Why a requested payment was not attempted ('' = none)
 * @return string ok|repeat|review|error|retry
 */
function easyocrWebhookResolveStatus($result, $paymentSkipReason = '')
{
	$status = (is_array($result) && isset($result['status'])) ? (string) $result['status'] : 'error';
	if ($status === 'ok') {
		if (!empty($result['forced_draft']) || !empty($result['review_codes']) || !empty($result['review_notes'])
			|| !empty($result['payment_error']) || $paymentSkipReason !== '') {
			return 'review';
		}
		return 'ok';
	}
	if (in_array($status, array('repeat', 'retry', 'error'), true)) {
		return $status;
	}
	return 'error';
}

/**
 * Message stored for a processed document, prefixes translated.
 *
 * @param  array     $result            Return of easyocrCreateInvoiceFromOCR()
 * @param  string    $paymentSkipReason Reason code ('' = none)
 * @param  Translate $langs             Translations
 * @return string
 */
function easyocrWebhookBuildMessage($result, $paymentSkipReason, $langs)
{
	$msg = isset($result['message']) ? (string) $result['message'] : '';
	if (isset($result['status']) && $result['status'] === 'ok') {
		$msg = '';
		if (!empty($result['review_notes'])) {
			$prefix = !empty($result['forced_draft']) ? 'EasyOcrWebhookMsgDraftForReview' : 'EasyOcrWebhookMsgToReview';
			$msg = $langs->transnoentities($prefix) . ' ' . implode(' | ', (array) $result['review_notes']);
		} elseif (!empty($result['forced_draft'])) {
			$msg = $langs->transnoentities('EasyOcrWebhookMsgDraftForReview');
		}
		if (!empty($result['payment_error'])) {
			$msg = trim($msg . ' | ' . $langs->transnoentities('EasyOcrWebhookMsgPayment') . ' ' . $result['payment_error'], ' |');
		}
	}
	if ($paymentSkipReason !== '') {
		$msg = trim($msg . ' | ' . $langs->transnoentities('EasyOcrWebhookMsgPaymentSkipped', $langs->transnoentities('EasyOcrWebhookPayReason_' . $paymentSkipReason)), ' |');
	}
	return $msg;
}

/**
 * What goes into llx_easyocr_webhook_log.payload. Pure.
 *
 * The full payload carries supplier data, tax ids and amounts: it is kept only when
 * somebody has to look at it (error, review, failed document). Otherwise only a
 * technical summary without personal data.
 *
 * @param  string|null $status  Processing status
 * @param  array       $payload Decoded payload
 * @return string|null          JSON or null
 */
function easyocrWebhookPayloadForStorage($status, $payload)
{
	if (!is_array($payload)) {
		return null;
	}
	$document = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : (isset($payload['document']) && is_array($payload['document']) ? $payload['document'] : array());
	$docStatus = isset($document['status']) ? (string) $document['status'] : '';
	if (in_array((string) $status, array('error', 'review'), true) || $docStatus === 'failed') {
		return json_encode(easyocrSanitizePayloadForStorage($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	$sd = isset($document['structured_data']) && is_array($document['structured_data']) ? $document['structured_data'] : array();
	$summary = array(
		'summary'            => 1,
		'event'              => isset($payload['event']) ? (string) $payload['event'] : '',
		'document_status'    => $docStatus,
		'page_count'         => isset($document['page_count']) ? (int) $document['page_count'] : null,
		'processing_time_ms' => isset($document['processing_time_ms']) ? (int) $document['processing_time_ms'] : null,
		'items_count'        => isset($sd['items']) && is_array($sd['items']) ? count($sd['items']) : null,
		'has_original'       => !empty($document['original_document']),
	);
	return json_encode($summary);
}

/**
 * HTTP answer for the processing outcome. Pure.
 *
 * Business errors answer 200: the service would retry a 5xx, and a duplicated invoice
 * is worse than a failed log entry. The one 5xx is 'retry' (the invoice lock was busy):
 * nothing was created, so a retry is exactly what is wanted.
 *
 * @param  string|null $procStatus   Processing status (null = nothing processed)
 * @param  string      $procMessage  Processing message
 * @param  bool        $enteredBlock The document should have been processed
 * @param  int         $retryAfter   Seconds for Retry-After on 'retry'
 * @return array{http:int,status:string,message:string,headers:array}
 */
function easyocrWebhookResponse($procStatus, $procMessage, $enteredBlock, $retryAfter = 30)
{
	$r = array('http' => 200, 'status' => 'ok', 'message' => 'Webhook received', 'headers' => array());
	if ($procStatus === 'retry') {
		$r['http'] = 503;
		$r['status'] = 'retry';
		$r['message'] = $procMessage ? $procMessage : 'Busy, retry later';
		$r['headers'] = array('Retry-After: ' . max(1, (int) $retryAfter));
	} elseif ($procStatus === 'error') {
		$r['status'] = 'error';
		$r['message'] = $procMessage ? $procMessage : 'Invoice creation failed';
	} elseif ($procStatus === 'repeat') {
		$r['status'] = 'repeat';
		$r['message'] = $procMessage ? $procMessage : 'Duplicate invoice skipped';
	} elseif ($procStatus === 'review') {
		$r['status'] = 'review';
		$r['message'] = 'Invoice created for review';
	} elseif ($procStatus === 'ok') {
		$r['message'] = 'Invoice created';
	} elseif ($enteredBlock) {
		$r['status'] = 'error';
		$r['message'] = 'No structured_data found in webhook payload';
	}
	return $r;
}

/**
 * Delete webhook log rows older than $days, in batches (MySQL/MariaDB and PostgreSQL).
 *
 * @param  DoliDB   $db         Database handler
 * @param  int      $entity     Entity
 * @param  int      $days       Age limit in days; 0 or less keeps everything
 * @param  int      $batchSize  Rows per DELETE
 * @param  int      $maxBatches DELETEs per call (0 = until nothing is left)
 * @param  int|null $now        Current time (for tests)
 * @return int                  Rows deleted, -1 when skipped, -2 on SQL error
 */
function easyocrWebhookPurgeLogRows($db, $entity, $days, $batchSize = 500, $maxBatches = 10, $now = null)
{
	if ((int) $days <= 0) {
		return -1;
	}
	$now = ($now === null) ? dol_now() : (int) $now;
	$limitDate = $db->idate($now - ((int) $days * 86400));
	$batchSize = max(1, (int) $batchSize);
	$table = MAIN_DB_PREFIX . "easyocr_webhook_log";
	$where = "entity = " . ((int) $entity) . " AND datec < '" . $db->escape($limitDate) . "'";

	$deleted = 0;
	$loops = 0;
	do {
		if ($db->type === 'pgsql') {
			// PostgreSQL has no DELETE ... LIMIT
			$sql = "DELETE FROM " . $table . " WHERE rowid IN (SELECT rowid FROM " . $table . " WHERE " . $where . " LIMIT " . $batchSize . ")";
		} else {
			// MySQL refuses LIMIT inside an IN () subquery, but takes it on DELETE
			$sql = "DELETE FROM " . $table . " WHERE " . $where . " LIMIT " . $batchSize;
		}
		$res = $db->query($sql);
		if (!$res) {
			return -2;
		}
		$n = (int) $db->affected_rows($res);
		$deleted += $n;
		$loops++;
	} while ($n >= $batchSize && ($maxBatches <= 0 || $loops < $maxBatches) && $loops < 100000);

	return $deleted;
}

/**
 * Days the webhook logs are kept (EASYOCR_WEBHOOK_LOG_DAYS, 30 by default, 0 = forever).
 *
 * @return int
 */
function easyocrWebhookLogDays()
{
	global $conf;
	return (isset($conf->global->EASYOCR_WEBHOOK_LOG_DAYS) && $conf->global->EASYOCR_WEBHOOK_LOG_DAYS !== '') ? (int) $conf->global->EASYOCR_WEBHOOK_LOG_DAYS : 30;
}

/**
 * Write the debug file of a request, never larger than $maxBytes.
 *
 * @param  string $dir      Folder (created if missing)
 * @param  array  $data     Data to dump
 * @param  int    $maxBytes Size limit
 * @return int|false        Bytes written or false
 */
function easyocrWebhookWriteDebug($dir, $data, $maxBytes = EASYOCR_WEBHOOK_DEBUG_MAX_BYTES)
{
	if (!@is_dir($dir) && !@mkdir($dir, 0755, true)) {
		return false;
	}
	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
	$json = json_encode($data, $flags);
	if (strlen($json) > $maxBytes && isset($data['parsed_json'])) {
		$data['parsed_json'] = '[omitted: ' . strlen($json) . ' bytes over the ' . $maxBytes . ' bytes limit]';
		$json = json_encode($data, $flags);
	}
	if (strlen($json) > $maxBytes) {
		$json = substr($json, 0, $maxBytes);
	}
	$file = rtrim($dir, '/\\') . '/webhook_' . date('Y-m-d_His') . '_' . uniqid() . '.json';
	return @file_put_contents($file, $json);
}

} // function_exists guard

if (defined('EASYOCR_WEBHOOK_LIB_ONLY')) {
	return;
}

// ─── Take the secrets out of the request before Dolibarr sees it ─────────
// main.inc.php, hooks and dol_syslog may log the URL or $_GET: they get it masked.
$eoReceivedToken = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
$eoReceivedInstanceId = (isset($_GET['instance_id']) && is_string($_GET['instance_id'])) ? $_GET['instance_id'] : '';
unset($_GET['token'], $_GET['instance_id'], $_REQUEST['token'], $_REQUEST['instance_id']);
if (isset($_SERVER['REQUEST_URI'])) {
	$_SERVER['REQUEST_URI'] = easyocrWebhookMaskQuery($_SERVER['REQUEST_URI']);
}
if (isset($_SERVER['QUERY_STRING'])) {
	$_SERVER['QUERY_STRING'] = easyocrWebhookMaskQuery($_SERVER['QUERY_STRING']);
}

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
	easyocrWebhookError(500, array('error' => 'Dolibarr environment not available'));
}

// ─── Global error handler to catch fatal errors ─────────────────────────
// IMPORTANT: Respect @ error suppression — on PHP 8.0+ the @ operator
// no longer sets error_reporting() to 0, so we must check the bitmask.
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false; // Respect @ suppression and current error_reporting level
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$eoAuthenticated = false;
$entityPathFragment = '/easyocr/entity_1';

try {

// ─── Only accept POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	easyocrWebhookError(405, array('error' => 'Method Not Allowed'));
}

// ─── Authenticate (nothing is written anywhere before this) ──────────────
$reqEntity = (isset($_GET['entity']) && (int) $_GET['entity'] > 0) ? (int) $_GET['entity'] : 1;

$expectedInstanceId = !empty($dolibarr_main_instance_unique_id) ? (string) $dolibarr_main_instance_unique_id : '';
if ($expectedInstanceId === '' && isset($conf->file->instance_unique_id)) {
	$expectedInstanceId = (string) $conf->file->instance_unique_id;
}
$expectedToken = easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_TOKEN', $reqEntity);
$legacyUntil = (int) easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', $reqEntity);

$auth = easyocrWebhookAuthenticate($eoReceivedToken, $eoReceivedInstanceId, $expectedToken, $expectedInstanceId, $legacyUntil, dol_now());
unset($expectedToken, $expectedInstanceId);
if (!$auth['ok']) {
	// Same answer for every failure: no lengths, no expected values
	dol_syslog('EasyOCR webhook: refused (' . $auth['reason'] . ')', LOG_WARNING);
	easyocrWebhookError(403, array('error' => 'Forbidden', 'reason' => $auth['reason']));
}
$authMode = $auth['mode'];
if ($auth['set_legacy_until'] > 0) {
	easyocrWebhookSetConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', (string) $auth['set_legacy_until'], $reqEntity);
	$legacyUntil = $auth['set_legacy_until'];
}
if ($authMode === 'legacy') {
	dol_syslog('EasyOCR webhook: legacy instance_id URL accepted until ' . dol_print_date($legacyUntil, 'dayhourlog') . ', no payments', LOG_WARNING);
}

// ─── Entity context (multicompany) ───────────────────────────────────────
// In NOLOGIN mode $conf holds the default entity: switch to the one of the URL and
// reload its constants, so payment settings, secret and debug flags are its own.
if ($reqEntity !== (int) $conf->entity) {
	$conf->entity = $reqEntity;
	if (isset($conf->multicompany) && is_object($conf->multicompany)) {
		$conf->multicompany->current_entity = $reqEntity;
	}
	$conf->setValues($db);
	if (isset($mysoc) && is_object($mysoc) && method_exists($mysoc, 'setMysoc')) {
		$mysoc->setMysoc($conf);
	}
	dol_syslog('EasyOCR webhook: entity ' . $reqEntity, LOG_INFO);
}
if (empty($conf->easyocr->enabled)) {
	easyocrWebhookError(404, array('error' => 'Module not enabled'));
}
$eoAuthenticated = true;
$entityPathFragment = '/easyocr/entity_' . $reqEntity;

// ─── Optional: verify webhook secret header ──────────────────────────────
$webhookSecret = getDolGlobalString('EASYOCR_WEBHOOK_SECRET');
if ($webhookSecret !== '') {
	$receivedSecret = isset($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? (string) $_SERVER['HTTP_X_WEBHOOK_SECRET'] : '';
	if ($receivedSecret === '' || !hash_equals($webhookSecret, $receivedSecret)) {
		easyocrWebhookError(403, array('error' => 'Forbidden', 'reason' => 'auth_failed'));
	}
}
unset($webhookSecret);

$langs->loadLangs(array('easyocr@easyocr'));

// ─── Read and decode the body once ───────────────────────────────────────
$rawBody = file_get_contents('php://input');
$rawBodyLength = strlen((string) $rawBody);
$payload = ($rawBodyLength > 0) ? json_decode($rawBody, true) : null;
$jsonError = ($rawBodyLength > 0 && json_last_error() !== JSON_ERROR_NONE) ? json_last_error_msg() : '';
$rawPreview = ($jsonError !== '') ? substr((string) $rawBody, 0, 500) : '';
unset($rawBody);

$debugDir = DOL_DATA_ROOT . $entityPathFragment . '/webhook_debug';
$logDir = DOL_DATA_ROOT . $entityPathFragment . '/webhook_logs';

// ─── Debug dump (opt-in: EASYOCR_WEBHOOK_DEBUG=1) ────────────────────────
if (getDolGlobalInt('EASYOCR_WEBHOOK_DEBUG') === 1) {
	easyocrWebhookWriteDebug($debugDir, array(
		'timestamp'        => date('Y-m-d H:i:s'),
		'method'           => $_SERVER['REQUEST_METHOD'],
		'remote_ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
		'request_uri'      => isset($_SERVER['REQUEST_URI']) ? easyocrWebhookMaskQuery($_SERVER['REQUEST_URI']) : '',
		'GET'              => easyocrWebhookMaskParams($_GET),
		'auth'             => $authMode,
		'headers'          => array(
			'content_type'   => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
			'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : '',
			'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
			'webhook_secret' => isset($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? '***present***' : 'not-set',
		),
		'raw_body_length'  => $rawBodyLength,
		'json_parse_error' => $jsonError !== '' ? $jsonError : null,
		'raw_body_preview' => $rawPreview,
		'parsed_json'      => is_array($payload) ? easyocrSanitizePayloadForStorage($payload) : null,
	));
}
unset($rawPreview);

// ─── Validate JSON body ──────────────────────────────────────────────────
if ($rawBodyLength === 0) {
	easyocrWebhookError(400, array('error' => 'Empty request body'));
}
if ($jsonError !== '' || !is_array($payload)) {
	easyocrWebhookError(400, array('error' => 'Invalid JSON: ' . ($jsonError !== '' ? $jsonError : 'not an object')));
}

// ─── Log webhook to file ─────────────────────────────────────────────────
if (!@is_dir($logDir)) {
	@mkdir($logDir, 0755, true); // Use native mkdir to avoid dol_mkdir open_basedir issue
}
$logFile = $logDir . '/webhook_' . date('Y-m-d') . '.log';
$logEntry = array(
	'received_at' => date('Y-m-d H:i:s'),
	'remote_ip'   => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
	'auth'        => $authMode,
	'headers'     => array(
		'content_type'   => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
		'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
		'webhook_secret' => !empty($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? '***set***' : 'not-set',
	),
	// Strip base64 blobs — PDFs in base64 can be several MB
	'payload' => easyocrSanitizePayloadForStorage($payload),
);
@file_put_contents($logFile, date('H:i:s') . ' | ' . json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
unset($logEntry);

// ─── Extract key data from payload ───────────────────────────────────────
$event    = isset($payload['event']) && is_string($payload['event']) ? $payload['event'] : '';
$batchId  = isset($payload['batch_id']) ? $payload['batch_id']
		  : (isset($payload['batch']['batch_id']) ? $payload['batch']['batch_id']
		  : (isset($payload['data']['batch_id']) ? $payload['data']['batch_id'] : ''));
$batchId  = is_scalar($batchId) ? (string) $batchId : '';
$document = isset($payload['document']) && is_array($payload['document']) ? $payload['document'] : null;
// Fallback: API sends document info inside 'data', not 'document'
if (empty($document) && isset($payload['data']) && is_array($payload['data'])) {
	$document = $payload['data'];
}
$batch      = isset($payload['batch']) && is_array($payload['batch']) ? $payload['batch'] : null;
$documentId = easyocrWebhookDocumentId($document);

@file_put_contents($logFile, date('H:i:s') . " | DEBUG-FLOW: event='" . $event . "', batchId='" . $batchId . "', document=" . ($document ? 'present(keys:' . implode(',', array_keys($document)) . ')' : 'NULL') . ", batch=" . ($batch ? 'present' : 'NULL') . "\n", FILE_APPEND | LOCK_EX);

// Initialize processing result variables
$webhookProcessingStatus = null;
$webhookProcessingMessage = null;
$webhookInvoiceId = null;
$webhookInvoiceRef = null;
$webhookSupplierId = null;
$webhookPaymentSkip = '';

// Accept both 'batch.document.completed' (current API) and 'document.completed'
// (legacy / non-batch flow) for forward compatibility.
$_condEvent = ($event === 'batch.document.completed' || $event === 'document.completed');
$_condDoc   = !empty($document);
$_condStat  = (isset($document['status']) && $document['status'] === 'completed');
$enteredBlock = ($_condEvent && $_condDoc && $_condStat);

// ─── Idempotency: a document already handled is not processed again ──────
if ($enteredBlock && $documentId !== '') {
	$already = easyocrWebhookAlreadyProcessed($db, $conf->entity, $documentId);
	if ($already) {
		@file_put_contents($logFile, date('H:i:s') . ' | SKIP-ALREADY-PROCESSED: document ' . $documentId . ' (log #' . $already['rowid'] . ")\n", FILE_APPEND | LOCK_EX);
		http_response_code(200);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array(
			'status'   => 'repeat',
			'message'  => 'Document already processed',
			'batch_id' => $batchId,
			'event'    => $event,
			'auth'     => $authMode,
			'entity'   => (int) $conf->entity,
			'processing' => array(
				'status'      => 'repeat',
				'message'     => 'Document already processed',
				'invoice_id'  => !empty($already['invoice_id']) ? (int) $already['invoice_id'] : null,
				'invoice_ref' => !empty($already['invoice_ref']) ? $already['invoice_ref'] : null,
				'supplier_id' => !empty($already['supplier_id']) ? (int) $already['supplier_id'] : null,
			),
		));
		exit;
	}
}

if ($enteredBlock) {
	require_once __DIR__ . '/lib/easyocr.lib.php';

	// Extract structured data — check both document-level and data-level paths
	$structuredData = array();
	if (isset($document['structured_data']) && !empty($document['structured_data'])) {
		$structuredData = $document['structured_data'];
	} elseif (isset($payload['data']['structured_data']) && !empty($payload['data']['structured_data'])) {
		$structuredData = $payload['data']['structured_data'];
	} elseif (isset($payload['data']) && is_array($payload['data'])) {
		// The entire 'data' may BE the structured_data
		$structuredData = $payload['data'];
	}

	if (!empty($structuredData) && is_array($structuredData)) {
		@file_put_contents($logFile, date('H:i:s') . ' | INFO: Processing completed document: ' . (isset($document['filename']) ? $document['filename'] : 'unknown') . "\n", FILE_APPEND | LOCK_EX);

		$webhookTempFile = ''; // must be initialized before try so cleanup always works
		try {
			$supplier = isset($structuredData['supplier']) && is_array($structuredData['supplier']) ? $structuredData['supplier'] : array();
			$totals   = isset($structuredData['totals']) && is_array($structuredData['totals']) ? $structuredData['totals'] : array();
			$payment  = isset($structuredData['payment']) && is_array($structuredData['payment']) ? $structuredData['payment'] : array();

			// ── Payment settings ────────────────────────────────────────────
			// Module config (EASYOCR_WEBHOOK_MARK_PAID / _BANK_ID / _PAYMENT_TYPE), and with
			// the token only, the per-batch override ?pay=1&bank_id=N&payment_type=N. The
			// account must exist, be open and belong to this entity; the method must exist.
			// NOTE: the payment is only created when the invoice is VALIDATED.
			$pay = easyocrWebhookPaymentSettings($authMode, $_GET, array(
				'mark_paid'    => getDolGlobalInt('EASYOCR_WEBHOOK_MARK_PAID'),
				'bank_id'      => getDolGlobalInt('EASYOCR_WEBHOOK_BANK_ID'),
				'payment_type' => getDolGlobalInt('EASYOCR_WEBHOOK_PAYMENT_TYPE'),
			));
			$webhookPaymentSkip = $pay['skip_reason'];
			if ($pay['mark_paid']) {
				$payCheck = easyocrWebhookCheckPayment($db, $pay['bank_id'], $pay['payment_type'], $conf->entity);
				if ($payCheck !== '') {
					$webhookPaymentSkip = $payCheck;
					$pay['mark_paid'] = 0;
				} elseif (getDolGlobalString('EASYOCR_INVOICE_DRAFT')) {
					@file_put_contents($logFile, date('H:i:s') . " | WARNING: mark-as-paid requested but module creates DRAFT invoices (EASYOCR_INVOICE_DRAFT=1) — payment skipped until validated\n", FILE_APPEND | LOCK_EX);
				}
			}
			if ($webhookPaymentSkip !== '') {
				@file_put_contents($logFile, date('H:i:s') . ' | WARNING: payment not registered: ' . $webhookPaymentSkip . "\n", FILE_APPEND | LOCK_EX);
			}

			$params = array(
				'fk_soc'           => 0, // Auto-detect from tax_id
				'ref_supplier'     => isset($structuredData['document_number']) ? $structuredData['document_number'] : '',
				'datef'            => isset($structuredData['issue_date']) ? $structuredData['issue_date'] : date('Y-m-d'),
				'total_ttc'        => isset($totals['total_payable']) ? $totals['total_payable'] : (isset($totals['total']) ? $totals['total'] : '0'),
				'total_ht'         => isset($totals['net_subtotal']) ? $totals['net_subtotal'] : (isset($totals['subtotal']) ? $totals['subtotal'] : '0'),
				'total_tva'        => isset($totals['tax_total']) ? $totals['tax_total'] : '',
				// The service sends the equivalence surcharge as surcharge_total and the
				// withholding (IRPF) as withholding_total; localtax1/2 are kept as a
				// fallback. Without them a total that already discounts the IRPF looked
				// like a mismatch, and the invoice kept no localtax at all.
				'total_localtax1'  => isset($totals['surcharge_total']) && $totals['surcharge_total'] !== null ? $totals['surcharge_total'] : (isset($totals['localtax1']) ? $totals['localtax1'] : '0'),
				'total_localtax2'  => isset($totals['withholding_total']) && $totals['withholding_total'] !== null ? $totals['withholding_total'] : (isset($totals['localtax2']) ? $totals['localtax2'] : '0'),
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
				// Only used to detect that the AI mixed up issuer and receiver
				'customer_tax_id'  => isset($structuredData['customer']['tax_id']) ? $structuredData['customer']['tax_id'] : '',
				'invoice_status'   => '', // Use module config default
				'invoice_type'     => 0,
				'journal_code'     => '',
				'import_key'       => 'easyocr-wh',
				'create_payment'   => $pay['mark_paid'] ? '1' : '',
				'payment_bank_id'  => $pay['mark_paid'] ? $pay['bank_id'] : 0,
				'payment_type_id'  => $pay['mark_paid'] ? $pay['payment_type'] : 0,
				// Nobody reviews this path: a misread tax id, several candidate
				// suppliers or totals that do not add up leave a draft, not a
				// validated (and maybe paid) invoice.
				'draft_on_review'  => 1,
			);
			if ($documentId !== '') {
				$params['document_id'] = $documentId;
			}

			// ── Extract original PDF from base64 if present ─────────────────
			$originalDoc = null;
			if (isset($document['original_document']) && !empty($document['original_document']['base64'])) {
				$originalDoc = $document['original_document'];
			} elseif (isset($payload['data']['original_document']) && !empty($payload['data']['original_document']['base64'])) {
				$originalDoc = $payload['data']['original_document'];
			} elseif (isset($payload['original_document']) && !empty($payload['original_document']['base64'])) {
				$originalDoc = $payload['original_document'];
			}

			if (!empty($originalDoc)) {
				$pdfData = base64_decode($originalDoc['base64'], true);
				if ($pdfData !== false && strlen($pdfData) > 0) {
					$tempDir = DOL_DATA_ROOT . $entityPathFragment . '/temp';
					if (!@is_dir($tempDir)) {
						@mkdir($tempDir, 0755, true);
					}
					$origFilename = !empty($originalDoc['filename']) ? $originalDoc['filename'] : (!empty($document['filename']) ? $document['filename'] : 'document.pdf');
					$webhookTempFile = $tempDir . '/webhook_' . date('Ymd_His') . '_' . uniqid() . '_' . dol_sanitizeFileName($origFilename);
					if (@file_put_contents($webhookTempFile, $pdfData) !== false) {
						$params['file_tmp_path'] = $webhookTempFile;
						$params['file_name'] = $origFilename;
						@file_put_contents($logFile, date('H:i:s') . ' | INFO: Decoded original PDF from base64 (' . strlen($pdfData) . " bytes)\n", FILE_APPEND | LOCK_EX);
					} else {
						@file_put_contents($logFile, date('H:i:s') . " | WARNING: Failed to write decoded PDF to temp file\n", FILE_APPEND | LOCK_EX);
					}
				} else {
					@file_put_contents($logFile, date('H:i:s') . " | WARNING: base64_decode failed for original_document\n", FILE_APPEND | LOCK_EX);
				}
				unset($pdfData);
			}
			// The payload is not needed any more for the invoice: free the base64 copy
			unset($originalDoc, $structuredData);
			if (isset($payload['data']['original_document']['base64'])) {
				$payload['data']['original_document']['base64'] = '[base64 released]';
			}
			if (isset($document['original_document']['base64'])) {
				$document['original_document']['base64'] = '[base64 released]';
			}

			@file_put_contents($logFile, date('H:i:s') . ' | DEBUG-PARAMS: ' . json_encode(array(
				'ref_supplier'    => $params['ref_supplier'],
				'datef'           => $params['datef'],
				'items_count'     => is_array($params['items']) ? count($params['items']) : 0,
				'has_pdf'         => !empty($params['file_tmp_path']),
				'create_payment'  => $params['create_payment'],
				'payment_bank_id' => $params['payment_bank_id'],
				'payment_type_id' => $params['payment_type_id'],
			), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

			$webhookResult = easyocrCreateInvoiceFromOCR($params);
			unset($params);
			@file_put_contents($logFile, date('H:i:s') . ' | DEBUG-FLOW: <<< Result: ' . json_encode($webhookResult, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

			$webhookProcessingStatus = easyocrWebhookResolveStatus($webhookResult, $webhookPaymentSkip);
			$webhookProcessingMessage = easyocrWebhookBuildMessage($webhookResult, $webhookPaymentSkip, $langs);
			$webhookInvoiceId = isset($webhookResult['invoice_id']) ? $webhookResult['invoice_id'] : null;
			$webhookInvoiceRef = isset($webhookResult['invoice_ref']) ? $webhookResult['invoice_ref'] : null;
			$webhookSupplierId = isset($webhookResult['supplier_id']) ? $webhookResult['supplier_id'] : null;

			if ($webhookProcessingStatus === 'repeat') {
				// Duplicate detected — not an error, just a skip
				$webhookInvoiceId = isset($webhookResult['existing_id']) ? $webhookResult['existing_id'] : null;
				$webhookInvoiceRef = isset($webhookResult['existing_ref']) ? $webhookResult['existing_ref'] : null;
				if ($webhookProcessingMessage === '') {
					$webhookProcessingMessage = 'Duplicate invoice';
				}
			}
			@file_put_contents($logFile, date('H:i:s') . ' | ' . strtoupper($webhookProcessingStatus) . ': ' . $webhookProcessingMessage . ($webhookInvoiceId ? ' (invoice ' . $webhookInvoiceId . ')' : '') . "\n", FILE_APPEND | LOCK_EX);
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

$response = easyocrWebhookResponse($webhookProcessingStatus, $webhookProcessingMessage, $enteredBlock, (isset($webhookResult['retry_after']) && (int) $webhookResult['retry_after'] > 0) ? (int) $webhookResult['retry_after'] : 30);

// ─── Busy: ask the service to come back, record nothing as final ─────────
if ($webhookProcessingStatus === 'retry') {
	@file_put_contents($logFile, date('H:i:s') . ' | RETRY: invoice lock busy for document ' . $documentId . ", answered 503\n", FILE_APPEND | LOCK_EX);
	easyocrWebhookError($response['http'], array(
		'status'   => 'retry',
		'message'  => $response['message'],
		'batch_id' => $batchId,
		'event'    => $event,
	), $response['headers']);
}

// ─── Store in database ──────────────────────────────────────────────────
$webhookLogInsertError = null;
$storedStatus = $webhookProcessingStatus;
if ($storedStatus === null && $enteredBlock) {
	$storedStatus = 'error';
}
$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log";
$sql .= " (entity, batch_id, event, document_id, document_filename, document_status,";
$sql .= " batch_status, batch_progress, invoice_id, invoice_ref, supplier_id,";
$sql .= " processing_status, processing_message, payload, datec)";
$sql .= " VALUES (";
$sql .= " " . ((int) $conf->entity) . ",";
$sql .= " '" . $db->escape(substr($batchId, 0, 64)) . "',";
$sql .= " '" . $db->escape(substr($event, 0, 64)) . "',";
$sql .= " '" . $db->escape($documentId) . "',";
$sql .= " '" . $db->escape($document && isset($document['filename']) && is_scalar($document['filename']) ? substr((string) $document['filename'], 0, 255) : '') . "',";
$sql .= " '" . $db->escape($document && isset($document['status']) && is_scalar($document['status']) ? substr((string) $document['status'], 0, 32) : '') . "',";
$sql .= " '" . $db->escape($batch && isset($batch['status']) && is_scalar($batch['status']) ? substr((string) $batch['status'], 0, 32) : '') . "',";
$sql .= " " . ($batch && isset($batch['progress']) ? (int) $batch['progress'] : 0) . ",";
$sql .= " " . (!empty($webhookInvoiceId) ? (int) $webhookInvoiceId : 'NULL') . ",";
$sql .= " " . (!empty($webhookInvoiceRef) ? "'" . $db->escape($webhookInvoiceRef) . "'" : 'NULL') . ",";
$sql .= " " . (!empty($webhookSupplierId) ? (int) $webhookSupplierId : 'NULL') . ",";
$sql .= " " . (!empty($storedStatus) ? "'" . $db->escape($storedStatus) . "'" : 'NULL') . ",";
$storedMessage = !empty($webhookProcessingMessage) ? $webhookProcessingMessage : ($storedStatus === 'error' && $enteredBlock && $webhookProcessingStatus === null ? $response['message'] : '');
$sql .= " " . ($storedMessage !== '' ? "'" . $db->escape($storedMessage) . "'" : 'NULL') . ",";
$payloadForDb = easyocrWebhookPayloadForStorage($storedStatus, $payload);
$sql .= " " . ($payloadForDb !== null ? "'" . $db->escape($payloadForDb) . "'" : 'NULL') . ",";
$sql .= " '" . $db->idate(dol_now()) . "'";
$sql .= ")";
unset($payloadForDb);

if (!$db->query($sql)) {
	$webhookLogInsertError = $db->lasterror();
	@file_put_contents($logFile, date('H:i:s') . ' | ERROR: webhook_log insert failed: ' . $webhookLogInsertError . "\n", FILE_APPEND | LOCK_EX);
}
unset($payload, $document);

// ─── Housekeeping: old debug/log files (once a day) and old log rows ──────
// EASYOCR_WEBHOOK_LOG_DAYS: days to keep them (30 by default, 0 keeps everything)
require_once __DIR__ . '/lib/easyocr.lib.php';
$keepDays = easyocrWebhookLogDays();
easyocrPurgeWebhookFiles($debugDir, $keepDays);
easyocrPurgeWebhookFiles($logDir, $keepDays);
easyocrWebhookPurgeLogRows($db, $conf->entity, $keepDays, 500, 4);

$responseBody = array(
	'status'   => $response['status'],
	'message'  => $response['message'],
	'batch_id' => $batchId,
	'event'    => $event,
	'auth'     => $authMode,
	'entity'   => (int) $conf->entity,
	'processing' => array(
		'status'      => $webhookProcessingStatus,
		'message'     => $webhookProcessingMessage,
		'invoice_id'  => $webhookInvoiceId,
		'invoice_ref' => $webhookInvoiceRef,
		'supplier_id' => $webhookSupplierId,
	),
);
if ($webhookPaymentSkip !== '') {
	$responseBody['payment_skipped'] = $webhookPaymentSkip;
}
if ($authMode === 'legacy') {
	$responseBody['legacy_until'] = dol_print_date($legacyUntil, 'dayhourrfc');
}
if (!empty($webhookLogInsertError)) {
	$responseBody['webhook_log_error'] = $webhookLogInsertError;
}

http_response_code($response['http']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($responseBody);

} catch (Throwable $e) {
	// Before authentication: a bare 500, nothing on disk and nothing about the code
	if (empty($eoAuthenticated)) {
		http_response_code(500);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('error' => 'Internal server error'));
		exit;
	}
	$errorInfo = array(
		'error'   => 'Internal server error in webhook_batch.php',
		'message' => $e->getMessage(),
		'file'    => basename($e->getFile()),
		'line'    => $e->getLine(),
		'type'    => get_class($e),
	);
	$crashDir = DOL_DATA_ROOT . $entityPathFragment . '/webhook_debug';
	if (@is_dir($crashDir) || @mkdir($crashDir, 0755, true)) {
		@file_put_contents($crashDir . '/crash_' . date('Y-m-d_His') . '.json', json_encode($errorInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	}

	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($errorInfo);
}
