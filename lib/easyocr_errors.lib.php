<?php
/* Copyright (C) 2024-2026 EasySoft Tech S.L.
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
 * \file    lib/easyocr_errors.lib.php
 * \ingroup easyocr
 * \brief   Guards and user-facing errors of the AJAX layer: service errors told in
 *          plain words, CSRF token check, core permissions per action and the
 *          check on the bank account used for an automatic payment.
 *
 * Works from Dolibarr 16 to 24 and PHP 7.4 to 8.5. Nothing here prints: every
 * function returns a value, so the AJAX handler decides what goes to the browser
 * and the technical detail goes to dol_syslog() only.
 */

/** Error kinds returned by easyocrServiceErrorKind() */
define('EASYOCR_SVCERR_CONNECTION', 'connection');
define('EASYOCR_SVCERR_AUTH', 'auth');
define('EASYOCR_SVCERR_CREDITS', 'credits');
define('EASYOCR_SVCERR_DOWN', 'down');
define('EASYOCR_SVCERR_OTHER', 'other');

/**
 * Classify a failed call to the EasyOCR service.
 *
 * The service (app.easyocr.es) answers 401/403 for key problems, 402 WALLET_EMPTY
 * when the prepaid balance is out, 429 QUOTA_EXCEEDED / KEY_QUOTA_EXCEEDED when the
 * monthly pages are spent and 403 FEATURE_NOT_AVAILABLE when the plan does not
 * include what was asked. The raw message may be a cURL error, the
 * "HTTP 402: {...}" text built by EasyOcrAI or an SDK exception message, so the
 * HTTP code and the error code are also looked for inside it.
 *
 * @param  int    $httpCode   HTTP status (0 when there was no response)
 * @param  int    $curlErrno  cURL error number (0 when cURL did not fail)
 * @param  string $rawMessage Raw technical message (never shown to the user)
 * @param  string $errorCode  Error code of the service, when known (SDK exception)
 * @return string             One of the EASYOCR_SVCERR_* constants
 */
function easyocrServiceErrorKind($httpCode, $curlErrno, $rawMessage, $errorCode = '')
{
	$httpCode = (int) $httpCode;
	$curlErrno = (int) $curlErrno;
	$raw = (string) $rawMessage;
	$code = strtoupper(trim((string) $errorCode));

	// "HTTP 402: {...}" (EasyOcrAI::doRequest) carries the status inside the text
	if ($httpCode <= 0 && preg_match('/\bHTTP\s+(\d{3})\b/', $raw, $m)) {
		$httpCode = (int) $m[1];
	}
	if ($code === '' && preg_match('/\b(WALLET_EMPTY|KEY_QUOTA_EXCEEDED|QUOTA_EXCEEDED|FEATURE_NOT_AVAILABLE|INVALID_API_KEY|MISSING_API_KEY|API_KEY_INACTIVE|API_KEY_EXPIRED|ACCOUNT_DISABLED|IP_NOT_ALLOWED|DOMAIN_NOT_ALLOWED|CONNECTION_ERROR|NETWORK_ERROR)\b/', $raw, $m)) {
		$code = $m[1];
	}

	if (in_array($code, array('WALLET_EMPTY', 'QUOTA_EXCEEDED', 'KEY_QUOTA_EXCEEDED', 'FEATURE_NOT_AVAILABLE'), true) || $httpCode === 402) {
		return EASYOCR_SVCERR_CREDITS;
	}
	if (in_array($code, array('INVALID_API_KEY', 'MISSING_API_KEY', 'API_KEY_INACTIVE', 'API_KEY_EXPIRED', 'ACCOUNT_DISABLED', 'IP_NOT_ALLOWED', 'DOMAIN_NOT_ALLOWED'), true)
		|| $httpCode === 401 || $httpCode === 403) {
		return EASYOCR_SVCERR_AUTH;
	}
	// Transport failures: DNS (6), refused (7), timeout (28), TLS (35), proxy (5),
	// empty reply (52), reset while reading (56)
	if (in_array($curlErrno, array(5, 6, 7, 28, 35, 52, 56), true)
		|| in_array($code, array('CONNECTION_ERROR', 'NETWORK_ERROR'), true)
		|| ($httpCode === 0 && preg_match('/cURL error|Could not resolve|timed out|Failed to connect|Connection refused|No se pudo conectar|Error de red/i', $raw))) {
		return EASYOCR_SVCERR_CONNECTION;
	}
	if ($httpCode >= 500 && $httpCode <= 599) {
		return EASYOCR_SVCERR_DOWN;
	}
	return EASYOCR_SVCERR_OTHER;
}

/**
 * Plain-words message for a failed call to the EasyOCR service, with what to do.
 *
 * The technical detail (cURL text, HTTP body, SDK message) is written to the
 * Dolibarr log and never returned: it can carry hostnames, paths or fragments of
 * the service answer that mean nothing to the user.
 *
 * @param  int    $httpCode   HTTP status (0 when there was no response)
 * @param  int    $curlErrno  cURL error number (0 when cURL did not fail)
 * @param  string $rawMessage Raw technical message (only logged)
 * @param  string $errorCode  Error code of the service, when known
 * @return string             Translated message, without HTML
 */
function easyocrHumanServiceError($httpCode, $curlErrno, $rawMessage, $errorCode = '')
{
	global $langs;

	$kind = easyocrServiceErrorKind($httpCode, $curlErrno, $rawMessage, $errorCode);
	if (function_exists('dol_syslog')) {
		dol_syslog('EasyOcr service error kind=' . $kind . ' http=' . ((int) $httpCode) . ' curl=' . ((int) $curlErrno) . ' code=' . $errorCode . ' raw=' . substr((string) $rawMessage, 0, 1000), LOG_WARNING);
	}

	$keys = array(
		EASYOCR_SVCERR_CONNECTION => 'EasyOcrSvcErrConnection',
		EASYOCR_SVCERR_AUTH       => 'EasyOcrSvcErrAuth',
		EASYOCR_SVCERR_CREDITS    => 'EasyOcrSvcErrCredits',
		EASYOCR_SVCERR_DOWN       => 'EasyOcrSvcErrDown',
		EASYOCR_SVCERR_OTHER      => 'EasyOcrSvcErrOther',
	);
	if (is_object($langs)) {
		$langs->load('easyocr@easyocr');
		return $langs->transnoentities($keys[$kind]);
	}
	return $keys[$kind];
}

/**
 * JSON payload for a failed call to the service: the message in plain words plus
 * the link to the module setup, which the browser shows next to it.
 *
 * @param  int    $httpCode   HTTP status (0 when there was no response)
 * @param  int    $curlErrno  cURL error number
 * @param  string $rawMessage Raw technical message (only logged)
 * @param  string $errorCode  Error code of the service, when known
 * @return array              status, message, error_kind, help_url, help_label
 */
function easyocrServiceErrorPayload($httpCode, $curlErrno, $rawMessage, $errorCode = '')
{
	global $langs;

	$payload = array(
		'status'     => 'error',
		'message'    => easyocrHumanServiceError($httpCode, $curlErrno, $rawMessage, $errorCode),
		'error_kind' => easyocrServiceErrorKind($httpCode, $curlErrno, $rawMessage, $errorCode),
	);
	if (function_exists('dol_buildpath')) {
		$payload['help_url'] = dol_buildpath('/easyocr/admin/setup.php', 1);
		$payload['help_label'] = is_object($langs) ? $langs->transnoentities('EasyOcrSvcErrHelpLink') : 'EasyOcrSvcErrHelpLink';
	}
	return $payload;
}

/**
 * Same payload, built from an exception thrown by the EasyOCR SDK or by Guzzle.
 *
 * @param  \Throwable $e Exception caught around a call to the service
 * @return array         See easyocrServiceErrorPayload()
 */
function easyocrServiceErrorPayloadFromException($e)
{
	$httpCode = 0;
	$errorCode = '';
	if (is_object($e) && method_exists($e, 'getHttpStatus')) {
		$httpCode = (int) $e->getHttpStatus();
	}
	if (is_object($e) && method_exists($e, 'getErrorCode')) {
		$errorCode = (string) $e->getErrorCode();
	}
	$raw = is_object($e) ? get_class($e) . ': ' . $e->getMessage() : '';
	return easyocrServiceErrorPayload($httpCode, 0, $raw, $errorCode);
}

/**
 * Is the CSRF token sent by the page one of this session's tokens?
 *
 * main.inc.php keeps two: 'token' (the one of the page that was served) and
 * 'newtoken' (printed by newToken() and in the anti-csrf-newtoken meta). The AJAX
 * handler defines NOTOKENRENEWAL, so neither rotates while the page is open, and a
 * page may have embedded either of them depending on the version and on
 * MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL. Both are secrets of this session,
 * so either one proves the request comes from a page we served.
 *
 * @param  string $posted  Token received with the request
 * @param  array  $session Session array ($_SESSION)
 * @return bool            True when it matches one of the session tokens
 */
function easyocrAjaxTokenIsValid($posted, $session)
{
	$posted = is_string($posted) ? $posted : '';
	if ($posted === '' || $posted === 'notrequired' || !is_array($session)) {
		return false;
	}
	foreach (array('token', 'newtoken') as $key) {
		if (!empty($session[$key]) && is_string($session[$key]) && hash_equals($session[$key], $posted)) {
			return true;
		}
	}
	return false;
}

/**
 * Core permission check with up to two levels, from Dolibarr 16 to 24.
 *
 * easyocrCheckRight() covers one level; supplier invoices need two
 * (fournisseur > facture > creer). User::hasRight() takes both from v15 on; the
 * property fallback is kept for anything older that still loads this file.
 *
 * @param  User   $user   User to check
 * @param  string $module Module (e.g. 'fournisseur')
 * @param  string $level1 First level (e.g. 'facture')
 * @param  string $level2 Second level (e.g. 'creer'), empty for one-level rights
 * @return bool
 */
function easyocrAjaxUserHasRight($user, $module, $level1, $level2 = '')
{
	if (!is_object($user)) {
		return false;
	}
	if (method_exists($user, 'hasRight')) {
		return (bool) $user->hasRight($module, $level1, $level2);
	}
	if ($level2 === '') {
		return !empty($user->rights->{$module}->{$level1});
	}
	return !empty($user->rights->{$module}->{$level1}->{$level2});
}

/**
 * Core permission behind each thing the AJAX layer can do on top of easyocr write.
 *
 * The expressions are the ones the core uses in fourn/facture/card.php and
 * fourn/facture/paiement.php (16 to 24): the supplier invoice right exists under
 * two names (fournisseur->facture->creer and supplier_invoice->creer), validation
 * needs the advanced right only when MAIN_USE_ADVANCED_PERMS is on, and the core
 * lets whoever creates supplier invoices register their payments.
 *
 * @param  User   $user User to check
 * @param  string $what supplier_invoice_create | supplier_invoice_validate |
 *                      supplier_payment | societe_create | expensereport_create |
 *                      various_payment_create
 * @return bool
 */
function easyocrAjaxCan($user, $what)
{
	global $conf;

	$advanced = function_exists('getDolGlobalString') ? getDolGlobalString('MAIN_USE_ADVANCED_PERMS') : (!empty($conf->global->MAIN_USE_ADVANCED_PERMS) ? '1' : '');
	$canCreateInvoice = easyocrAjaxUserHasRight($user, 'fournisseur', 'facture', 'creer') || easyocrAjaxUserHasRight($user, 'supplier_invoice', 'creer');

	switch ($what) {
		case 'supplier_invoice_create':
		case 'supplier_payment':
			return $canCreateInvoice;
		case 'supplier_invoice_validate':
			if (empty($advanced)) {
				return $canCreateInvoice;
			}
			return $canCreateInvoice && easyocrAjaxUserHasRight($user, 'fournisseur', 'supplier_invoice_advance', 'validate');
		case 'societe_create':
			return easyocrAjaxUserHasRight($user, 'societe', 'creer');
		case 'expensereport_create':
			return easyocrAjaxUserHasRight($user, 'expensereport', 'creer');
		case 'various_payment_create':
			return easyocrAjaxUserHasRight($user, 'banque', 'modifier');
	}
	return false;
}

/**
 * Can this bank account receive the automatic payment?
 *
 * It must exist, be open and belong to the current entity: the id comes from the
 * browser, so a closed account or one of another company must be refused before
 * anything is created.
 *
 * @param  DoliDB $db     Database handler
 * @param  int    $bankId Bank account id sent by the browser
 * @param  int    $entity Current entity
 * @return bool
 */
function easyocrAjaxBankAccountIsUsable($db, $bankId, $entity)
{
	$bankId = (int) $bankId;
	if ($bankId <= 0 || !is_object($db)) {
		return false;
	}
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account";
	$sql .= " WHERE rowid = " . $bankId;
	$sql .= " AND entity = " . ((int) $entity);
	$sql .= " AND clos = 0";
	$res = $db->query($sql);
	return ($res && $db->num_rows($res) > 0);
}

/**
 * Would easyocrCreateInvoiceFromOCR() create a supplier or turn a customer into one?
 *
 * The lib does both on its own when no supplier is given, and Societe::create() does
 * not look at permissions. Without "create third parties" the document is only
 * allowed when the lookup the lib will run lands on an existing supplier; the lookup
 * itself is left to the lib so its review notes (ambiguity, closed record) are kept.
 *
 * @param  User   $user    Current user
 * @param  int    $fkSoc   Supplier id sent by the page (0 = let the lib resolve it)
 * @param  string $taxId   Supplier tax id read from the document
 * @param  string $name    Supplier name read from the document
 * @param  string $country Supplier country read from the document
 * @return bool            True when the document may go ahead
 */
function easyocrAjaxSupplierAllowed($user, $fkSoc, $taxId, $name, $country)
{
	if ((int) $fkSoc > 0 || easyocrAjaxCan($user, 'societe_create')) {
		return true;
	}
	if (trim((string) $taxId) === '' && trim((string) $name) === '') {
		return true; // nothing to resolve: the lib refuses the document by itself
	}
	$resolved = easyocrResolveSupplierForDocument((string) $taxId, (string) $name, (string) $country);
	return ($resolved['fk_soc'] > 0 && empty($resolved['needs_upgrade']));
}

/**
 * Notices the user must see after creating a document (the payment that did not go
 * through, why it stayed as a draft, what to review). They are also in the private
 * note, but nobody opens it right after creating the invoice.
 *
 * @param  array  $result        Result of the creation
 * @param  string $permDraftNote Extra notice when the draft is due to permissions
 * @return array                 Translated, plain-text notices (may be empty)
 */
function easyocrAjaxUserNotices($result, $permDraftNote = '')
{
	global $langs;

	$notices = array();
	if (!empty($result['payment_error'])) {
		$notices[] = (string) $result['payment_error'];
	}
	if ($permDraftNote !== '') {
		$notices[] = $permDraftNote;
	}
	if (!empty($result['forced_draft'])) {
		$notices[] = $langs->transnoentities('EasyOcrForcedDraftNotice');
	}
	if (!empty($result['review_notes']) && is_array($result['review_notes'])) {
		foreach ($result['review_notes'] as $note) {
			if (is_string($note) && $note !== '') {
				$notices[] = $note;
			}
		}
	}
	return array_values(array_unique($notices));
}
