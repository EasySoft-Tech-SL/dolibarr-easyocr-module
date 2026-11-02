<?php
/**
 * EasyOcr — guards of the AJAX layer (2.9.0).
 *
 * Part A boots Dolibarr and checks lib/easyocr_errors.lib.php on its own: service
 * errors in plain words, CSRF token, core rights per action, the bank account of the
 * automatic payment and the payment of the template flow when the bank refuses the line. Every
 * database change of part A runs inside a transaction that is always rolled back.
 *
 * Part B talks to ajax/ajax_easyocr.php over HTTP as a real logged-in user with only
 * easyocr read/write (no third-party, no supplier-invoice rights), to prove the
 * handler enforces what the functions decide. It creates that user and removes it at
 * the end, with anything it may have written. It never calls the OCR service.
 * Skipped (not failed) when the local site does not answer: pass --http-required to
 * make that a failure.
 *
 * Run:  php tests/easyocr_ajax_test.php [--base-url=https://erp-v16.local] [--http-required]
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}

$eoArgs = array('base' => 'https://erp-v16.local', 'http_required' => false);
foreach (array_slice($argv, 1) as $arg) {
	if (strpos($arg, '--base-url=') === 0) {
		$eoArgs['base'] = rtrim(substr($arg, 11), '/');
	} elseif ($arg === '--http-required') {
		$eoArgs['http_required'] = true;
	}
}

// ── Precondition: the database must be reachable (see easyocr_integration_test.php)
$confFile = __DIR__ . '/../../../conf/conf.php';
if (is_readable($confFile)) {
	include $confFile;
	$dbHost = !empty($dolibarr_main_db_host) ? $dolibarr_main_db_host : '127.0.0.1';
	$dbPort = !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306;
	$probe = @fsockopen($dbHost === 'localhost' ? '127.0.0.1' : $dbHost, $dbPort, $errno, $errstr, 3);
	if ($probe === false) {
		fwrite(STDERR, "\033[31mDatabase unreachable at " . $dbHost . ':' . $dbPort . " — " . $errstr . "\033[0m\n");
		exit(1);
	}
	fclose($probe);
}

// ── Boot Dolibarr ────────────────────────────────────────────────────────
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOLOGIN')) define('NOLOGIN', '1');
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');

$master = __DIR__ . '/../../../master.inc.php';
if (!file_exists($master)) {
	fwrite(STDERR, "Could not find master.inc.php at $master\n");
	exit(1);
}
require_once $master;
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
require_once __DIR__ . '/../lib/easyocr.lib.php';
require_once __DIR__ . '/../lib/easyocr_errors.lib.php';

if (!is_object($langs)) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
	$langs = new Translate('', $conf);
}
$langs->setDefaultLang('es_ES');
$langs->load('easyocr@easyocr');

// ── Tiny assertion harness (same style as the other suites) ──────────────
$GLOBALS['eo_pass'] = 0;
$GLOBALS['eo_fail'] = 0;

function eo_group($name)
{
	echo "\n\033[1m" . $name . "\033[0m\n";
}

function eo_assert($name, $actual, $expected)
{
	if ($actual === $expected) {
		$GLOBALS['eo_pass']++;
		echo "  \033[32mPASS\033[0m  " . $name . "\n";
		return true;
	}
	$GLOBALS['eo_fail']++;
	echo "  \033[31mFAIL\033[0m  " . $name . "\n";
	echo "        expected: " . var_export($expected, true) . "\n";
	echo "        actual:   " . var_export($actual, true) . "\n";
	return false;
}

function eo_info($msg)
{
	echo "        \033[2m" . $msg . "\033[0m\n";
}

/** Fake user: hasRight() answers from a list of "module.level1.level2" strings */
class EoFakeUser
{
	public $rightsList = array();
	public $id = 0;

	public function __construct($rights)
	{
		$this->rightsList = $rights;
	}

	public function hasRight($module, $level1, $level2 = '')
	{
		return in_array($module . '.' . $level1 . ($level2 !== '' ? '.' . $level2 : ''), $this->rightsList, true);
	}
}

// =====================================================================
// PART A — functions
// =====================================================================

eo_group('A1 · service errors are classified');
eo_assert('DNS failure (curl 6) -> connection', easyocrServiceErrorKind(0, 6, 'Could not resolve host: app.easyocr.es'), EASYOCR_SVCERR_CONNECTION);
eo_assert('timeout (curl 28) -> connection', easyocrServiceErrorKind(0, 28, 'Operation timed out'), EASYOCR_SVCERR_CONNECTION);
eo_assert('EasyOcrAI "cURL error:" text -> connection', easyocrServiceErrorKind(0, 0, 'cURL error: Failed to connect to 10.0.0.1 port 443'), EASYOCR_SVCERR_CONNECTION);
eo_assert('SDK CONNECTION_ERROR code -> connection', easyocrServiceErrorKind(0, 0, 'No se pudo conectar', 'CONNECTION_ERROR'), EASYOCR_SVCERR_CONNECTION);
eo_assert('401 -> auth', easyocrServiceErrorKind(401, 0, ''), EASYOCR_SVCERR_AUTH);
eo_assert('403 INVALID_API_KEY -> auth', easyocrServiceErrorKind(403, 0, '', 'INVALID_API_KEY'), EASYOCR_SVCERR_AUTH);
eo_assert('"HTTP 401: ..." text -> auth', easyocrServiceErrorKind(0, 0, 'HTTP 401: {"error":{"code":"INVALID_API_KEY"}}'), EASYOCR_SVCERR_AUTH);
eo_assert('"HTTP 402: WALLET_EMPTY" text -> credits', easyocrServiceErrorKind(0, 0, 'HTTP 402: {"error":{"code":"WALLET_EMPTY"}}'), EASYOCR_SVCERR_CREDITS);
eo_assert('402 alone -> credits', easyocrServiceErrorKind(402, 0, ''), EASYOCR_SVCERR_CREDITS);
eo_assert('429 QUOTA_EXCEEDED -> credits', easyocrServiceErrorKind(429, 0, '{"error":{"code":"QUOTA_EXCEEDED"}}'), EASYOCR_SVCERR_CREDITS);
eo_assert('429 KEY_QUOTA_EXCEEDED code -> credits', easyocrServiceErrorKind(429, 0, '', 'KEY_QUOTA_EXCEEDED'), EASYOCR_SVCERR_CREDITS);
eo_assert('403 FEATURE_NOT_AVAILABLE -> credits (plan), not auth', easyocrServiceErrorKind(403, 0, '', 'FEATURE_NOT_AVAILABLE'), EASYOCR_SVCERR_CREDITS);
eo_assert('429 rate limit without quota code -> other', easyocrServiceErrorKind(429, 0, 'Too Many Requests'), EASYOCR_SVCERR_OTHER);
eo_assert('503 -> down', easyocrServiceErrorKind(503, 0, ''), EASYOCR_SVCERR_DOWN);
eo_assert('"HTTP 502: Bad gateway" text -> down', easyocrServiceErrorKind(0, 0, 'HTTP 502: Bad gateway'), EASYOCR_SVCERR_DOWN);
eo_assert('418 -> other', easyocrServiceErrorKind(418, 0, 'teapot'), EASYOCR_SVCERR_OTHER);

eo_group('A2 · the message is translated and never carries the technical detail');
$secret = 'cURL error: Failed to connect to SECRET-HOST.internal:8443 /var/www/x.php';
$human = easyocrHumanServiceError(0, 7, $secret);
eo_assert('message is not empty', $human !== '', true);
eo_assert('raw host is not in the message', strpos($human, 'SECRET-HOST') === false, true);
eo_assert('raw path is not in the message', strpos($human, '/var/www') === false, true);
foreach (array('EasyOcrSvcErrConnection', 'EasyOcrSvcErrAuth', 'EasyOcrSvcErrCredits', 'EasyOcrSvcErrDown', 'EasyOcrSvcErrOther', 'EasyOcrSvcErrHelpLink', 'EasyOcrUnexpectedError', 'EasyOcrSecurityTokenInvalid', 'EasyOcrNoPermSupplierCreate', 'EasyOcrNoPermSupplierInvoiceCreate', 'EasyOcrNoPermValidateDraft', 'EasyOcrPaymentBankInvalid', 'EasyOcrCreatedWithNotices') as $k) {
	eo_assert('es_ES translates ' . $k, $langs->transnoentities($k) !== $k, true);
}
$payload = easyocrServiceErrorPayload(402, 0, 'HTTP 402');
eo_assert('payload status=error', $payload['status'], 'error');
eo_assert('payload kind=credits', $payload['error_kind'], EASYOCR_SVCERR_CREDITS);
eo_assert('payload points to the module setup', (bool) preg_match('#/easyocr/admin/setup\.php$#', $payload['help_url']), true);
$ex = new \RuntimeException('Guzzle: cURL error 6 at /secret/path');
$fromEx = easyocrServiceErrorPayloadFromException($ex);
eo_assert('exception message does not reach the payload', strpos($fromEx['message'], '/secret/path') === false, true);
if (class_exists('\EasySoft\EasyOCR\Exceptions\EasyOCRException') || (is_readable(__DIR__ . '/../lib/easyocr_autoload.php') && (include_once __DIR__ . '/../lib/easyocr_autoload.php') && class_exists('\EasySoft\EasyOCR\Exceptions\EasyOCRException'))) {
	$sdkEx = new \EasySoft\EasyOCR\Exceptions\EasyOCRException('Saldo insuficiente', 402, 'WALLET_EMPTY');
	eo_assert('SDK exception 402 WALLET_EMPTY -> credits', easyocrServiceErrorPayloadFromException($sdkEx)['error_kind'], EASYOCR_SVCERR_CREDITS);
}

eo_group('A3 · CSRF token');
$sess = array('token' => 'aaa111', 'newtoken' => 'bbb222');
eo_assert('page token accepted', easyocrAjaxTokenIsValid('aaa111', $sess), true);
eo_assert('next token accepted', easyocrAjaxTokenIsValid('bbb222', $sess), true);
eo_assert('wrong token refused', easyocrAjaxTokenIsValid('zzz', $sess), false);
eo_assert('empty token refused', easyocrAjaxTokenIsValid('', $sess), false);
eo_assert('"notrequired" refused', easyocrAjaxTokenIsValid('notrequired', $sess), false);
eo_assert('no session tokens -> refused', easyocrAjaxTokenIsValid('aaa111', array()), false);
eo_assert('array instead of string refused', easyocrAjaxTokenIsValid(array('aaa111'), $sess), false);

eo_group('A4 · core rights per action');
$savedAdv = isset($conf->global->MAIN_USE_ADVANCED_PERMS) ? $conf->global->MAIN_USE_ADVANCED_PERMS : null;
$conf->global->MAIN_USE_ADVANCED_PERMS = '';
$uNone = new EoFakeUser(array('easyocr.write'));
$uOld = new EoFakeUser(array('fournisseur.facture.creer'));
$uNew = new EoFakeUser(array('supplier_invoice.creer'));
$uSoc = new EoFakeUser(array('societe.creer'));
eo_assert('no supplier right -> cannot create invoice', easyocrAjaxCan($uNone, 'supplier_invoice_create'), false);
eo_assert('fournisseur.facture.creer -> can create', easyocrAjaxCan($uOld, 'supplier_invoice_create'), true);
eo_assert('supplier_invoice.creer (other name) -> can create', easyocrAjaxCan($uNew, 'supplier_invoice_create'), true);
eo_assert('payment follows the create right (as the core)', easyocrAjaxCan($uOld, 'supplier_payment'), true);
eo_assert('validate without advanced perms = create', easyocrAjaxCan($uOld, 'supplier_invoice_validate'), true);
$conf->global->MAIN_USE_ADVANCED_PERMS = '1';
eo_assert('validate with advanced perms needs the advanced right', easyocrAjaxCan($uOld, 'supplier_invoice_validate'), false);
$uAdv = new EoFakeUser(array('fournisseur.facture.creer', 'fournisseur.supplier_invoice_advance.validate'));
eo_assert('advanced right granted -> can validate', easyocrAjaxCan($uAdv, 'supplier_invoice_validate'), true);
$conf->global->MAIN_USE_ADVANCED_PERMS = $savedAdv;
eo_assert('societe.creer -> can create third parties', easyocrAjaxCan($uSoc, 'societe_create'), true);
eo_assert('without societe.creer -> cannot', easyocrAjaxCan($uOld, 'societe_create'), false);
eo_assert('unknown capability -> false', easyocrAjaxCan($uAdv, 'whatever'), false);

// Everything from here on is rolled back
$db->begin();
register_shutdown_function(function () use ($db) {
	if (!empty($db->transaction_opened)) {
		$db->rollback();
	}
});

eo_group('A5 · bank account of the automatic payment');
$sqlOpen = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE clos = 0 AND entity = " . ((int) $conf->entity) . " ORDER BY rowid LIMIT 1";
$resOpen = $db->query($sqlOpen);
$openId = ($resOpen && $db->num_rows($resOpen)) ? (int) $db->fetch_object($resOpen)->rowid : 0;
if ($openId > 0) {
	eo_assert('open account of this entity accepted', easyocrAjaxBankAccountIsUsable($db, $openId, $conf->entity), true);
	eo_assert('same account from another entity refused', easyocrAjaxBankAccountIsUsable($db, $openId, $conf->entity + 1000), false);
	$db->query("UPDATE " . MAIN_DB_PREFIX . "bank_account SET clos = 1 WHERE rowid = " . $openId);
	eo_assert('closed account refused', easyocrAjaxBankAccountIsUsable($db, $openId, $conf->entity), false);
	$db->query("UPDATE " . MAIN_DB_PREFIX . "bank_account SET clos = 0 WHERE rowid = " . $openId);
} else {
	eo_info('no open bank account in this entity: open/closed cases skipped');
}
eo_assert('nonexistent account refused', easyocrAjaxBankAccountIsUsable($db, 999999999, $conf->entity), false);
eo_assert('id 0 refused', easyocrAjaxBankAccountIsUsable($db, 0, $conf->entity), false);

eo_group('A6 · payment whose bank line is refused is undone');
$resAdmin = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
$admin = new User($db);
$admin->fetch($db->fetch_object($resAdmin)->rowid);
$admin->getrights();
$user = $admin;
$mysoc->setMysoc($conf);

$cashId = 0;
$resCash = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE courant = 2 AND clos = 0 AND entity = " . ((int) $conf->entity) . " LIMIT 1");
if ($resCash && $db->num_rows($resCash)) {
	$cashId = (int) $db->fetch_object($resCash)->rowid;
}
$virId = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
if ($cashId > 0 && $virId > 0) {
	$soc = new Societe($db);
	$soc->name = 'EO-AJAX-TEST supplier ' . dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	$soc->fournisseur = 1;
	$soc->client = 0;
	$soc->code_fournisseur = -1;
	$socId = $soc->create($admin);
	$fac = new FactureFournisseur($db);
	$fac->socid = $socId;
	$fac->ref_supplier = 'EO-AJAX-' . mt_rand(100000, 999999);
	$fac->date = dol_now();
	// Same as the viewer's template flow (ajax createSupplierInvoice)
	$fac->multicurrency_code = $conf->currency;
	$facId = $fac->create($admin);
	$fac->addline('Test', 100, 21, 0, 0, 1);
	$fac->validate($admin);
	$fac->fetch($facId);

	// The template flow of the viewer pays through the shared function of the lib
	$msg = easyocrPaySupplierInvoice($fac, $admin, $cashId, $virId, $fac->ref_supplier);
	eo_assert('a cash account refuses a transfer: the reason is returned', $msg !== '', true);
	$fac->fetch($facId);
	$paidNow = isset($fac->paid) ? (int) $fac->paid : (int) $fac->paye;
	eo_assert('invoice is pending payment again', $paidNow, 0);
	$resP = $db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn WHERE fk_facturefourn = " . ((int) $facId));
	eo_assert('no payment left on the invoice', (int) $db->fetch_object($resP)->nb, 0);
	eo_assert('reason written in the private note', strpos((string) $fac->note_private, $msg) !== false, true);
} else {
	eo_info('no open cash account or no VIR payment mode: undo case skipped');
}

// Core code run above (payment, bank line) may have closed the outer transaction with
// its own rollback/commit: start a clean one so nothing below is ever committed.
while (!empty($db->transaction_opened)) {
	$db->rollback();
}
$db->begin();

eo_group('A7 · supplier creation needs "create third parties"');
$noSoc = new EoFakeUser(array('fournisseur.facture.creer'));
$withSoc = new EoFakeUser(array('fournisseur.facture.creer', 'societe.creer'));
$unknownCif = 'B' . mt_rand(10000000, 99999999);
eo_assert('unknown supplier, no right -> refused (would be created)', easyocrAjaxSupplierAllowed($noSoc, 0, $unknownCif, 'EO-AJAX nobody ' . $unknownCif, 'ES'), false);
eo_assert('unknown supplier, with right -> allowed', easyocrAjaxSupplierAllowed($withSoc, 0, $unknownCif, 'EO-AJAX nobody', 'ES'), true);
eo_assert('supplier chosen by id -> allowed without right', easyocrAjaxSupplierAllowed($noSoc, 5, $unknownCif, 'x', 'ES'), true);
$cust = new Societe($db);
$cust->name = 'EO-AJAX customer ' . $unknownCif;
$cust->client = 1;
$cust->fournisseur = 0;
$cust->code_client = -1;
$cust->idprof1 = $unknownCif;
$cust->tva_intra = 'ES' . $unknownCif;
$cust->country_id = (int) dol_getIdFromCode($db, 'ES', 'c_country', 'code', 'rowid');
$cust->create($admin);
eo_assert('customer with that tax id, no right -> refused (would be converted)', easyocrAjaxSupplierAllowed($noSoc, 0, $unknownCif, $cust->name, 'ES'), false);
$db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET fournisseur = 1 WHERE rowid = " . ((int) $cust->id));
eo_assert('existing supplier with that tax id, no right -> allowed', easyocrAjaxSupplierAllowed($noSoc, 0, $unknownCif, $cust->name, 'ES'), true);

eo_group('A8 · notices shown after creating a document');
$n = easyocrAjaxUserNotices(array('payment_error' => 'PAY-ERR', 'forced_draft' => true, 'review_notes' => array('NOTE-1', 'NOTE-1', '')), 'PERM-DRAFT');
eo_assert('payment error first', isset($n[0]) ? $n[0] : null, 'PAY-ERR');
eo_assert('permission draft note included', in_array('PERM-DRAFT', $n, true), true);
eo_assert('forced draft explained', in_array($langs->transnoentities('EasyOcrForcedDraftNotice'), $n, true), true);
eo_assert('review notes included once, empty ones dropped', count(array_keys($n, 'NOTE-1', true)) === 1 && !in_array('', $n, true), true);
eo_assert('nothing to say -> empty list', easyocrAjaxUserNotices(array('status' => 'ok')), array());

while (!empty($db->transaction_opened)) {
	$db->rollback();
}
eo_info('part A rolled back');

// =====================================================================
// PART B — the handler over HTTP
// =====================================================================

/**
 * Small HTTP client with a cookie jar (the local certificate is self-signed).
 */
function eo_http($url, $post = null, $jar = '')
{
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_COOKIEJAR      => $jar,
		CURLOPT_COOKIEFILE     => $jar,
	));
	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}
	$body = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if (PHP_VERSION_ID < 80000) {
		if (PHP_VERSION_ID < 80000) {
			curl_close($ch);
		}
	}
	return array($code, (string) $body);
}

eo_group('B · ajax_easyocr.php over HTTP (' . $eoArgs['base'] . ')');
list($probeCode, $probeBody) = eo_http($eoArgs['base'] . '/index.php');
if ($probeCode !== 200 || strpos($probeBody, 'name="token"') === false) {
	if ($eoArgs['http_required']) {
		eo_assert('local site answers with a login form', false, true);
	} else {
		eo_info('local site not reachable (HTTP ' . $probeCode . '): part B skipped');
	}
} else {
	$ajaxUrl = $eoArgs['base'] . '/easydoli/easyocr/ajax/ajax_easyocr.php';
	$jar = tempnam(sys_get_temp_dir(), 'eoajax');
	$login = 'eo_ajax_qa_' . mt_rand(10000, 99999);
	$pass = 'Qa!' . bin2hex(random_bytes(8));
	$marker = 'EO-AJAX-QA-' . mt_rand(100000, 999999);

	$qa = new User($db);
	$qa->login = $login;
	$qa->lastname = 'EasyOCR AJAX QA';
	$qa->entity = $conf->entity;
	$qaId = $qa->create($admin);
	$cleanup = function () use ($db, $admin, $qaId, $marker, $jar) {
		// Anything the user may have written, then the user itself
		$db->query("DELETE d FROM " . MAIN_DB_PREFIX . "easyocr_template_details d INNER JOIN " . MAIN_DB_PREFIX . "easyocr_template t ON t.rowid = d.fk_template WHERE t.name LIKE '" . $db->escape($marker) . "%'");
		$db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE name LIKE '" . $db->escape($marker) . "%'");
		$resSoc = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE nom LIKE '" . $db->escape($marker) . "%'");
		$socIds = array();
		while ($resSoc && ($o = $db->fetch_object($resSoc))) {
			$socIds[] = (int) $o->rowid;
		}
		foreach ($socIds as $sid) {
			$s = new Societe($db);
			if ($s->fetch($sid) > 0) {
				$s->delete($sid, $admin);
			}
		}
		$db->query("DELETE FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier LIKE '" . $db->escape($marker) . "%' AND fk_statut = 0");
		if ($qaId > 0) {
			$u = new User($db);
			if ($u->fetch($qaId) > 0) {
				$u->delete($admin);
			}
		}
		@unlink($jar);
	};
	register_shutdown_function($cleanup);

	if ($qaId <= 0) {
		eo_assert('QA user created', false, true);
	} else {
		$qa->setPassword($admin, $pass);
		$resR = $db->query("SELECT id FROM " . MAIN_DB_PREFIX . "rights_def WHERE module = 'easyocr' AND perms IN ('read','write') AND entity = " . ((int) $conf->entity));
		while ($resR && ($o = $db->fetch_object($resR))) {
			$qa->addrights((int) $o->id);
		}
		eo_info('QA user ' . $login . ' (id ' . $qaId . ') with easyocr read/write only');

		// Login: the form carries a token of its own
		list(, $form) = eo_http($eoArgs['base'] . '/index.php', null, $jar);
		preg_match('/name="token" value="([^"]+)"/', $form, $m);
		eo_http($eoArgs['base'] . '/index.php', array('token' => isset($m[1]) ? $m[1] : '', 'actionlogin' => 'login', 'loginfunction' => 'loginfunction', 'username' => $login, 'password' => $pass), $jar);

		// A module page that does not call the OCR service: its header has the token
		list($pc, $page) = eo_http($eoArgs['base'] . '/easydoli/easyocr/inbox.php', null, $jar);
		$tok = preg_match('/name="anti-csrf-newtoken" content="([^"]+)"/', $page, $mt) ? $mt[1] : '';
		eo_assert('logged in and got the page token', $tok !== '', true);

		$countTpl = function () use ($db, $marker) {
			$r = $db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE name LIKE '" . $db->escape($marker) . "%'");
			return (int) $db->fetch_object($r)->nb;
		};

		// 1. Read-only action works without a token
		list(, $b) = eo_http($ajaxUrl, array('action' => 'loadFormData'), $jar);
		$j = json_decode($b, true);
		eo_assert('loadFormData (read) answers without token', is_array($j) && array_key_exists('suppliers', $j), true);

		// 2. Writing action without token: refused, nothing written
		list(, $b) = eo_http($ajaxUrl, array('action' => 'saveNewTemplate', 'name' => $marker . '-notoken', 'selections' => '[]'), $jar);
		$j = json_decode($b, true);
		eo_assert('saveNewTemplate without token -> error', is_array($j) ? $j['status'] : $b, 'error');
		eo_assert('... reported as csrf', is_array($j) && isset($j['error_kind']) ? $j['error_kind'] : '', 'csrf');
		eo_assert('... and no template written', $countTpl(), 0);

		// 3. Wrong token: refused too
		list(, $b) = eo_http($ajaxUrl, array('action' => 'saveNewTemplate', 'token' => 'forged', 'name' => $marker . '-forged', 'selections' => '[]'), $jar);
		eo_assert('saveNewTemplate with a forged token -> no template', $countTpl(), 0);

		// 4. Valid token: it goes through
		list(, $b) = eo_http($ajaxUrl, array('action' => 'saveNewTemplate', 'token' => $tok, 'name' => $marker . '-ok', 'selections' => '[]'), $jar);
		$j = json_decode($b, true);
		eo_assert('saveNewTemplate with the page token -> ok', is_array($j) ? $j['status'] : $b, 'ok');
		eo_assert('... and the template exists', $countTpl(), 1);

		// 5. Supplier invoice without the core right: refused before anything is created
		list(, $b) = eo_http($ajaxUrl, array('action' => 'createSupplierInvoice', 'token' => $tok, 'fk_soc' => 1, 'ref_supplier' => $marker . '-inv', 'datef' => '01/10/2026', 'total_ttc' => '121', 'total_ht' => '100'), $jar);
		$j = json_decode($b, true);
		eo_assert('createSupplierInvoice without supplier-invoice right -> error', is_array($j) ? $j['status'] : $b, 'error');
		$r = $db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier LIKE '" . $db->escape($marker) . "%'");
		eo_assert('... and no invoice created', (int) $db->fetch_object($r)->nb, 0);

		// 6. Auto-create a supplier without "create third parties": nothing created
		$cif = 'B' . mt_rand(10000000, 99999999);
		list(, $b) = eo_http($ajaxUrl, array('action' => 'findSupplierByCIF', 'token' => $tok, 'cif' => $cif, 'auto_create' => '1', 'supplier_name' => $marker . '-soc'), $jar);
		$j = json_decode($b, true);
		eo_assert('findSupplierByCIF auto_create without societe creer -> error', is_array($j) ? $j['status'] : $b, 'error');
		$r = $db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "societe WHERE nom LIKE '" . $db->escape($marker) . "%'");
		eo_assert('... and no third party created', (int) $db->fetch_object($r)->nb, 0);

		// 7. Same with the right but no token: still nothing created
		$resS = $db->query("SELECT id FROM " . MAIN_DB_PREFIX . "rights_def WHERE module = 'societe' AND perms = 'creer' AND entity = " . ((int) $conf->entity));
		while ($resS && ($o = $db->fetch_object($resS))) {
			$qa->addrights((int) $o->id);
		}
		// Rights are cached in the session: log in again to see the new one
		@unlink($jar);
		list(, $form) = eo_http($eoArgs['base'] . '/index.php', null, $jar);
		preg_match('/name="token" value="([^"]+)"/', $form, $m);
		eo_http($eoArgs['base'] . '/index.php', array('token' => isset($m[1]) ? $m[1] : '', 'actionlogin' => 'login', 'loginfunction' => 'loginfunction', 'username' => $login, 'password' => $pass), $jar);
		list(, $page) = eo_http($eoArgs['base'] . '/easydoli/easyocr/inbox.php', null, $jar);
		$tok = preg_match('/name="anti-csrf-newtoken" content="([^"]+)"/', $page, $mt) ? $mt[1] : '';
		list(, $b) = eo_http($ajaxUrl, array('action' => 'findSupplierByCIF', 'cif' => $cif, 'auto_create' => '1', 'supplier_name' => $marker . '-soc'), $jar);
		$r = $db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "societe WHERE nom LIKE '" . $db->escape($marker) . "%'");
		eo_assert('findSupplierByCIF auto_create with right but without token -> nothing created', (int) $db->fetch_object($r)->nb, 0);
		list(, $b) = eo_http($ajaxUrl, array('action' => 'findSupplierByCIF', 'token' => $tok, 'cif' => $cif, 'auto_create' => '1', 'supplier_name' => $marker . '-soc'), $jar);
		$j = json_decode($b, true);
		if (!eo_assert('... with right and token -> created', is_array($j) && !empty($j['created']), true)) {
			eo_info('answer: ' . substr($b, 0, 300));
		}

		// 8. Uncaught failures: no file, line or trace reaches the browser
		list(, $b) = eo_http($ajaxUrl, array('action' => 'resolveProductCodes', 'codes' => '["x"]'), $jar);
		eo_assert('answers carry no server path', (bool) preg_match('#[A-Z]:[\\\\/]|/var/www|\.php:\d+#', $b), false);
	}
}

// ── Summary ──────────────────────────────────────────────────────────────
echo "\n" . $GLOBALS['eo_pass'] . " passed, " . $GLOBALS['eo_fail'] . " failed\n";
exit($GLOBALS['eo_fail'] > 0 ? 1 : 0);
