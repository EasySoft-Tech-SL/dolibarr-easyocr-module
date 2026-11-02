<?php
/**
 * EasyOcr — webhook security, idempotency, retention and service connection (2.9.0).
 *
 * Three layers:
 *  1. Pure functions of webhook_batch.php and EasyOcrAI (authentication, masking,
 *     payment decision, status, stored payload, HTTP answer, URL check, TLS options).
 *  2. Against the local database, inside a transaction that is ALWAYS rolled back
 *     (token per entity, idempotency lookup, bank/payment checks, retention purge),
 *     plus a throwaway local mock of the service for the connection test.
 *  3. Real HTTP requests to https://erp-v16.local/.../webhook_batch.php when the site
 *     answers (constants it needs are set before and restored after, rows it writes
 *     are deleted).
 *
 * Run:  php tests/easyocr_webhook_test.php
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}

// ── Precondition: the database must be reachable ─────────────────────────
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../lib/easyocr.lib.php';
require_once __DIR__ . '/../lib/easyocr_ai.class.php';
define('EASYOCR_WEBHOOK_LIB_ONLY', 1);
require_once __DIR__ . '/../webhook_batch.php';

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

$now = 1800000000;
$tok = str_repeat('a1', 20);
$iid = 'instance-unique-id-of-test';

// ═════════════════════════════════════════════════════════════════════════
// 1. Pure functions
// ═════════════════════════════════════════════════════════════════════════
eo_group('authentication');
$a = easyocrWebhookAuthenticate($tok, '', $tok, $iid, 0, $now);
eo_assert('right token: accepted in token mode', array($a['ok'], $a['mode']), array(true, 'token'));
$a = easyocrWebhookAuthenticate($tok . 'x', '', $tok, $iid, 0, $now);
eo_assert('wrong token: refused', $a['ok'], false);
$a = easyocrWebhookAuthenticate($tok, '', '', $iid, 0, $now);
eo_assert('token received but none configured: refused', $a['ok'], false);
$a = easyocrWebhookAuthenticate('', '', '', '', 0, $now);
eo_assert('nothing configured, nothing received: refused', $a['ok'], false);
$a = easyocrWebhookAuthenticate('', '', $tok, $iid, 0, $now);
eo_assert('no credentials in the request: refused', $a['ok'], false);
$a = easyocrWebhookAuthenticate('', $iid, $tok, $iid, 0, $now, true);
eo_assert('legacy instance_id, first use: accepted in legacy mode', array($a['ok'], $a['mode']), array(true, 'legacy'));
eo_assert('legacy first use opens a 30-day window', $a['set_legacy_until'], $now + 30 * 86400);
$a = easyocrWebhookAuthenticate('', $iid, $tok, $iid, 0, $now, false);
eo_assert('legacy on a fresh 2.9.0 install (no window, not eligible): refused, no window opened', array($a['ok'], $a['set_legacy_until']), array(false, 0));
$a = easyocrWebhookAuthenticate('', $iid, $tok, $iid, 0, $now);
eo_assert('legacy eligibility defaults to no', $a['ok'], false);
eo_assert('eligible: updated from 2.8 (revision 2) with an API key', easyocrWebhookLegacyEligible('2', 'k-123'), true);
eo_assert('eligible: updated from before 2.7.3 (no revision) with an API key', easyocrWebhookLegacyEligible('', 'k-123'), true);
eo_assert('not eligible: never configured (no API key)', easyocrWebhookLegacyEligible('2', '  '), false);
eo_assert('not eligible: schema already at 2.9.0 (repair decided)', easyocrWebhookLegacyEligible('3', 'k-123'), false);
$a = easyocrWebhookAuthenticate('', $iid, $tok, $iid, $now + 10, $now);
eo_assert('legacy inside the window: accepted, window untouched', array($a['ok'], $a['set_legacy_until']), array(true, 0));
$a = easyocrWebhookAuthenticate('', $iid, $tok, $iid, $now - 1, $now);
eo_assert('legacy after the window: refused as expired', array($a['ok'], $a['reason']), array(false, 'legacy_expired'));
$a = easyocrWebhookAuthenticate('', $iid, $tok, '', 0, $now);
eo_assert('legacy with no instance id configured: refused', $a['ok'], false);
$a = easyocrWebhookAuthenticate('', 'other', $tok, $iid, 0, $now);
eo_assert('legacy with a wrong instance id: refused', $a['ok'], false);
$a = easyocrWebhookAuthenticate(array('x'), '', $tok, $iid, 0, $now);
eo_assert('token as an array (?token[]=): refused, no warning', $a['ok'], false);

eo_group('secrets never logged');
eo_assert('token masked in a request URI', easyocrWebhookMaskQuery('/x/webhook_batch.php?token=' . $tok . '&entity=2'), '/x/webhook_batch.php?token=***&entity=2');
eo_assert('instance_id masked in a query string', easyocrWebhookMaskQuery('entity=1&instance_id=' . $iid), 'entity=1&instance_id=***');
eo_assert('other parameters untouched', easyocrWebhookMaskQuery('pay=1&bank_id=3'), 'pay=1&bank_id=3');
eo_assert('masked in a parameter array', easyocrWebhookMaskParams(array('token' => $tok, 'instance_id' => $iid, 'entity' => '2')), array('token' => '***', 'instance_id' => '***', 'entity' => '2'));

eo_group('payment decision');
$cfgOff = array('mark_paid' => 0, 'bank_id' => 0, 'payment_type' => 0);
$cfgOn = array('mark_paid' => 1, 'bank_id' => 7, 'payment_type' => 4);
$p = easyocrWebhookPaymentSettings('token', array('pay' => '1', 'bank_id' => '3', 'payment_type' => '6'), $cfgOff);
eo_assert('token: the URL can ask for the payment', array($p['mark_paid'], $p['bank_id'], $p['payment_type']), array(1, 3, 6));
$p = easyocrWebhookPaymentSettings('token', array(), $cfgOn);
eo_assert('token: module config applies without URL', array($p['mark_paid'], $p['bank_id'], $p['payment_type']), array(1, 7, 4));
$p = easyocrWebhookPaymentSettings('token', array('pay' => '0'), $cfgOn);
eo_assert('token: ?pay=0 turns the config payment off', $p['mark_paid'], 0);
$p = easyocrWebhookPaymentSettings('legacy', array('pay' => '1', 'bank_id' => '3'), $cfgOff);
eo_assert('legacy: the URL never pays, and says why', array($p['mark_paid'], $p['bank_id'], $p['skip_reason']), array(0, 0, 'legacy'));
$p = easyocrWebhookPaymentSettings('legacy', array(), $cfgOn);
eo_assert('legacy: not even the module config pays', array($p['mark_paid'], $p['skip_reason']), array(0, 'legacy'));

eo_group('status, message and stored payload');
eo_assert('ok stays ok', easyocrWebhookResolveStatus(array('status' => 'ok')), 'ok');
eo_assert('forced draft is review', easyocrWebhookResolveStatus(array('status' => 'ok', 'forced_draft' => true)), 'review');
eo_assert('review codes are review', easyocrWebhookResolveStatus(array('status' => 'ok', 'review_codes' => array('totals_missing'))), 'review');
eo_assert('payment not registered is review', easyocrWebhookResolveStatus(array('status' => 'ok'), 'bank_closed'), 'review');
eo_assert('retry passes through', easyocrWebhookResolveStatus(array('status' => 'retry', 'error_code' => 'lock_busy')), 'retry');
eo_assert('unknown status is an error', easyocrWebhookResolveStatus(array('status' => 'weird')), 'error');

$langs->loadLangs(array('easyocr@easyocr'));
$m = easyocrWebhookBuildMessage(array('status' => 'ok', 'forced_draft' => 1, 'review_notes' => array('N1')), '', $langs);
eo_assert('draft-for-review prefix comes from the .lang file', strpos($m, $langs->transnoentities('EasyOcrWebhookMsgDraftForReview')) === 0 && strpos($m, 'Draft for review:') === false, true);
$m = easyocrWebhookBuildMessage(array('status' => 'ok', 'payment_error' => 'X'), '', $langs);
eo_assert('payment prefix comes from the .lang file', strpos($m, $langs->transnoentities('EasyOcrWebhookMsgPayment')) !== false && strpos($m, 'Payment:') === false, true);

$payload = array('event' => 'batch.document.completed', 'data' => array('document_id' => 'd1', 'status' => 'completed', 'page_count' => 2,
	'structured_data' => array('supplier' => array('name' => 'ACME PRIVATE NAME', 'tax_id' => 'B00000000'), 'items' => array(1, 2))));
$stOk = easyocrWebhookPayloadForStorage('ok', $payload);
eo_assert('ok: only a technical summary is stored', strpos($stOk, 'ACME PRIVATE NAME') === false && strpos($stOk, 'B00000000') === false && strpos($stOk, '"items_count":2') !== false, true);
eo_assert('repeat: no personal data either', strpos((string) easyocrWebhookPayloadForStorage('repeat', $payload), 'B00000000'), false);
eo_assert('review: the full payload is kept', strpos(easyocrWebhookPayloadForStorage('review', $payload), 'ACME PRIVATE NAME') !== false, true);
eo_assert('error: the full payload is kept', strpos(easyocrWebhookPayloadForStorage('error', $payload), 'B00000000') !== false, true);

eo_group('HTTP answer');
$r = easyocrWebhookResponse('retry', 'busy', true, 30);
eo_assert('retry answers 503 with Retry-After: 30', array($r['http'], $r['headers']), array(503, array('Retry-After: 30')));
$r = easyocrWebhookResponse('error', 'x', true);
eo_assert('business error answers 200 (no retry, no duplicate)', array($r['http'], $r['status']), array(200, 'error'));
$r = easyocrWebhookResponse('review', '', true);
eo_assert('review answers 200 review', array($r['http'], $r['status']), array(200, 'review'));
$r = easyocrWebhookResponse(null, null, true);
eo_assert('completed document without data is an error', $r['status'], 'error');

eo_group('debug file size limit');
$dbgDir = sys_get_temp_dir() . '/eo_webhook_dbg_' . uniqid();
$bytes = easyocrWebhookWriteDebug($dbgDir, array('parsed_json' => str_repeat('x', 400000), 'GET' => array()), 4096);
eo_assert('debug file never larger than the limit', $bytes !== false && $bytes <= 4096, true);
$bytes = easyocrWebhookWriteDebug($dbgDir, array('raw_body_preview' => str_repeat('y', 400000)), 4096);
eo_assert('even when the big part is not the parsed payload', $bytes !== false && $bytes <= 4096, true);
foreach ((array) glob($dbgDir . '/*') as $f) {
	@unlink($f);
}
@rmdir($dbgDir);

eo_group('service URL and TLS');
eo_assert('https is allowed', EasyOcrAI::isAllowedServiceUrl('https://app.easyocr.es'), true);
eo_assert('plain http to a remote host is refused', EasyOcrAI::isAllowedServiceUrl('http://app.easyocr.es'), false);
eo_assert('plain http to 127.0.0.1 is allowed', EasyOcrAI::isAllowedServiceUrl('http://127.0.0.1:8000'), true);
eo_assert('plain http to localhost is allowed', EasyOcrAI::isAllowedServiceUrl('http://localhost:8000/'), true);
eo_assert('http to a host that only starts with localhost is refused', EasyOcrAI::isAllowedServiceUrl('http://localhost.evil.example'), false);
eo_assert('credentials in the URL are refused', EasyOcrAI::isAllowedServiceUrl('https://u:p@app.easyocr.es'), false);
eo_assert('not a URL is refused', EasyOcrAI::isAllowedServiceUrl('javascript:alert(1)'), false);
$prevInsecure = isset($conf->global->EASYOCR_AI_INSECURE_TLS) ? $conf->global->EASYOCR_AI_INSECURE_TLS : null;
unset($conf->global->EASYOCR_AI_INSECURE_TLS);
$tls = EasyOcrAI::curlTlsOptions();
eo_assert('TLS verification on by default', array($tls[CURLOPT_SSL_VERIFYPEER], $tls[CURLOPT_SSL_VERIFYHOST]), array(true, 2));
$conf->global->EASYOCR_AI_INSECURE_TLS = '1';
$tls = EasyOcrAI::curlTlsOptions();
eo_assert('only EASYOCR_AI_INSECURE_TLS=1 turns it off', array($tls[CURLOPT_SSL_VERIFYPEER], $tls[CURLOPT_SSL_VERIFYHOST]), array(false, 0));
if ($prevInsecure === null) {
	unset($conf->global->EASYOCR_AI_INSECURE_TLS);
} else {
	$conf->global->EASYOCR_AI_INSECURE_TLS = $prevInsecure;
}
$aiSrc = file_get_contents(__DIR__ . '/../lib/easyocr_ai.class.php');
eo_assert('no hard-coded VERIFYPEER => false left in the AI client', preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*false,\s*\/\//', $aiSrc), 0);

eo_group('connection check, classified');
eo_assert('200 with quota: ok and pages', EasyOcrAI::classifyAccountCheck(200, '', array('quota' => array('pages_available_now' => 10))), array('code' => 'ok', 'pages' => 10));
eo_assert('401: invalid key', EasyOcrAI::classifyAccountCheck(401, 'UNAUTHORIZED', null)['code'], 'invalid_key');
eo_assert('no HTTP status: no connection', EasyOcrAI::classifyAccountCheck(0, 'CONNECTION_ERROR', null)['code'], 'no_connection');
eo_assert('502: service down', EasyOcrAI::classifyAccountCheck(502, 'SERVER_ERROR', null)['code'], 'service_down');

// Real calls through the client library, against a throwaway local mock
$mockDir = sys_get_temp_dir() . '/eo_mock_' . uniqid();
@mkdir($mockDir);
file_put_contents($mockDir . '/router.php', '<?php
header("Content-Type: application/json");
$k = isset($_SERVER["HTTP_X_API_KEY"]) ? $_SERVER["HTTP_X_API_KEY"] : "";
if (strpos($_SERVER["REQUEST_URI"], "/api/v1/me") !== 0) { http_response_code(404); echo "{}"; return; }
if ($k === "good") { echo json_encode(array("data" => array("quota" => array("pages_available_now" => 42)))); return; }
if ($k === "boom") { http_response_code(500); echo json_encode(array("error" => array("message" => "down", "code" => "SERVER_ERROR"))); return; }
http_response_code(401); echo json_encode(array("error" => array("message" => "invalid", "code" => "UNAUTHORIZED")));
');
$port = 18000 + mt_rand(0, 999);
$proc = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $mockDir . '/router.php'), array(1 => array('file', $mockDir . '/out.txt', 'a'), 2 => array('file', $mockDir . '/err.txt', 'a')), $pipes);
$up = false;
for ($t = 0; $t < 40 && !$up; $t++) {
	usleep(100000);
	$s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
	if ($s) {
		$up = true;
		fclose($s);
	}
}
if ($up) {
	$base = 'http://127.0.0.1:' . $port;
	eo_assert('mock: valid key gives connected with 42 pages', EasyOcrAI::checkAccount($base, 'good', 5), array('code' => 'ok', 'pages' => 42));
	eo_assert('mock: wrong key gives invalid key', EasyOcrAI::checkAccount($base, 'nope', 5)['code'], 'invalid_key');
	eo_assert('mock: 500 gives service down', EasyOcrAI::checkAccount($base, 'boom', 5)['code'], 'service_down');
	eo_assert('empty key never calls: invalid key', EasyOcrAI::checkAccount($base, '', 5)['code'], 'invalid_key');
} else {
	eo_info('mock server did not start: real-call checks skipped');
}
if (is_resource($proc)) {
	proc_terminate($proc);
	proc_close($proc);
}
eo_assert('closed port gives no connection', EasyOcrAI::checkAccount('http://127.0.0.1:1', 'good', 3)['code'], 'no_connection');
foreach ((array) glob($mockDir . '/*') as $f) {
	@unlink($f);
}
@rmdir($mockDir);

// ═════════════════════════════════════════════════════════════════════════
// 2. Database (rolled back)
// ═════════════════════════════════════════════════════════════════════════
eo_group('schema: indexes of llx_easyocr_webhook_log');
// Same file and same tolerance as module activation / easyocrEnsureSchema()
run_sql(__DIR__ . '/../sql/llx_easyocr_webhook_log.key.sql', 1, '', 1);
$idx = array();
if ($db->type !== 'pgsql') {
	$resIdx = $db->query("SHOW INDEX FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log");
	while ($resIdx && ($o = $db->fetch_object($resIdx))) {
		$idx[$o->Key_name][] = $o->Column_name;
	}
	eo_assert('index (entity, datec)', isset($idx['idx_easyocr_webhook_log_datec']) ? $idx['idx_easyocr_webhook_log_datec'] : null, array('entity', 'datec'));
	eo_assert('index (entity, batch_id)', isset($idx['idx_easyocr_webhook_log_batch']) ? $idx['idx_easyocr_webhook_log_batch'] : null, array('entity', 'batch_id'));
	eo_assert('index (entity, document_id)', isset($idx['idx_easyocr_webhook_log_document']) ? $idx['idx_easyocr_webhook_log_document'] : null, array('entity', 'document_id'));
}

eo_group('schema repair opens the legacy window only for an install in use');
// Outside the transaction: easyocrEnsureSchema() never runs inside one.
$prevRev = getDolGlobalString('EASYOCR_SCHEMA_REVISION');
$prevWin = easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
$prevKey = getDolGlobalString('EASYOCR_AI_APIKEY');
try {
	dolibarr_set_const($db, 'EASYOCR_SCHEMA_REVISION', '2', 'chaine', 0, '', 1);
	dolibarr_del_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
	dolibarr_set_const($db, 'EASYOCR_AI_APIKEY', 'k-test-key', 'chaine', 0, '', 1);
	easyocrEnsureSchema();
	$win = (int) getDolGlobalString('EASYOCR_WEBHOOK_LEGACY_UNTIL');
	eo_assert('updated from 2.8 with an API key: window of ~30 days', abs($win - (dol_now() + 30 * 86400)) < 600, true);
	eo_assert('... and the revision mark is 3', getDolGlobalString('EASYOCR_SCHEMA_REVISION'), (string) EASYOCR_SCHEMA_REVISION);

	dolibarr_set_const($db, 'EASYOCR_SCHEMA_REVISION', '2', 'chaine', 0, '', 1);
	dolibarr_del_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
	dolibarr_del_const($db, 'EASYOCR_AI_APIKEY', 1);
	easyocrEnsureSchema();
	eo_assert('fresh install (no API key yet): no window', easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1), '');

	dolibarr_set_const($db, 'EASYOCR_SCHEMA_REVISION', '2', 'chaine', 0, '', 1);
	dolibarr_set_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', '12345', 'chaine', 0, '', 1);
	dolibarr_set_const($db, 'EASYOCR_AI_APIKEY', 'k-test-key', 'chaine', 0, '', 1);
	easyocrEnsureSchema();
	eo_assert('a window already set is not moved', easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1), '12345');
} finally {
	dolibarr_set_const($db, 'EASYOCR_SCHEMA_REVISION', $prevRev !== '' ? $prevRev : (string) EASYOCR_SCHEMA_REVISION, 'chaine', 0, '', 1);
	if ($prevWin !== '') {
		dolibarr_set_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', $prevWin, 'chaine', 0, '', 1);
	} else {
		dolibarr_del_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
	}
	if ($prevKey !== '') {
		dolibarr_set_const($db, 'EASYOCR_AI_APIKEY', $prevKey, 'chaine', 0, '', 1);
	} else {
		dolibarr_del_const($db, 'EASYOCR_AI_APIKEY', 1);
	}
}

$db->begin();
register_shutdown_function(function () use ($db) {
	if (!empty($db->transaction_opened)) {
		$db->rollback();
		echo "\n\033[33mTransaction rolled back on shutdown.\033[0m\n";
	}
});

eo_group('token per entity');
$db->query("DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = 'EASYOCR_WEBHOOK_TOKEN' AND entity IN (1, 77)");
eo_assert('no token yet, not created when not asked', easyocrWebhookGetToken($db, 1, false), '');
$t1 = easyocrWebhookGetToken($db, 1, true);
eo_assert('created token is 40 hex chars', (bool) preg_match('/^[0-9a-f]{40}$/', $t1), true);
eo_assert('asked again: the same token', easyocrWebhookGetToken($db, 1, true), $t1);
$t77 = easyocrWebhookGetToken($db, 77, true);
eo_assert('another entity gets its own token', $t77 !== '' && $t77 !== $t1, true);
$t1b = easyocrWebhookRegenerateToken($db, 1);
eo_assert('regenerated token is new and stored', $t1b !== $t1 && easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_TOKEN', 1) === $t1b, true);
$url = easyocrWebhookBuildUrl($db, true);
eo_assert('webhook URL carries the token, not the instance id', strpos($url, '?token=' . $t1b) !== false && strpos($url, 'instance_id') === false && strpos($url, (string) $dolibarr_main_instance_unique_id) === false, true);
eo_assert('webhook URL is https', strpos($url, 'https://') === 0, true);

eo_group('idempotency');
$docA = 'eo-test-doc-' . uniqid();
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, document_id, processing_status, datec) VALUES (1, '" . $docA . "', 'error', '" . $db->idate(dol_now()) . "')");
eo_assert('a document that only failed may be processed again', easyocrWebhookAlreadyProcessed($db, 1, $docA), null);
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, document_id, processing_status, invoice_id, datec) VALUES (1, '" . $docA . "', 'review', 123, '" . $db->idate(dol_now()) . "')");
$hit = easyocrWebhookAlreadyProcessed($db, 1, $docA);
eo_assert('a document in review is not processed again', $hit !== null && (int) $hit['invoice_id'] === 123, true);
eo_assert('another entity does not see it', easyocrWebhookAlreadyProcessed($db, 2, $docA), null);
eo_assert('document id read from the payload', easyocrWebhookDocumentId(array('document_id' => 'abc')), 'abc');

eo_group('payment account and method checks');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "bank_account (ref, label, entity, fk_pays, courant, clos, currency_code) VALUES ('EOTSTO', 'EO test open', 1, " . (int) $mysoc->country_id . ", 1, 0, 'EUR')");
$openId = $db->last_insert_id(MAIN_DB_PREFIX . 'bank_account');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "bank_account (ref, label, entity, fk_pays, courant, clos, currency_code) VALUES ('EOTSTC', 'EO test closed', 1, " . (int) $mysoc->country_id . ", 1, 1, 'EUR')");
$closedId = $db->last_insert_id(MAIN_DB_PREFIX . 'bank_account');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "bank_account (ref, label, entity, fk_pays, courant, clos, currency_code) VALUES ('EOTSTE', 'EO test other', 99, " . (int) $mysoc->country_id . ", 1, 0, 'EUR')");
$otherId = $db->last_insert_id(MAIN_DB_PREFIX . 'bank_account');
$resPt = $db->query("SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 ORDER BY id LIMIT 1");
$ptId = (int) $db->fetch_object($resPt)->id;
eo_assert('open account of the entity and real method: valid', easyocrWebhookCheckPayment($db, $openId, $ptId, 1), '');
eo_assert('no account: refused', easyocrWebhookCheckPayment($db, 0, $ptId, 1), 'no_bank');
eo_assert('missing account: refused', easyocrWebhookCheckPayment($db, 99999999, $ptId, 1), 'bank_not_found');
eo_assert('closed account: refused', easyocrWebhookCheckPayment($db, $closedId, $ptId, 1), 'bank_closed');
eo_assert('account of another entity: refused as not found', easyocrWebhookCheckPayment($db, $otherId, $ptId, 1), 'bank_not_found');
eo_assert('missing payment method: refused', easyocrWebhookCheckPayment($db, $openId, 99999, 1), 'paytype_not_found');

eo_group('retention of log rows');
$tag = 'eo-test-ret-' . uniqid();
$old = $db->idate(dol_now() - 100 * 86400);
$new = $db->idate(dol_now() - 2 * 86400);
for ($k = 0; $k < 5; $k++) {
	$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, batch_id, datec) VALUES (1, '" . $tag . "', '" . $old . "')");
}
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, batch_id, datec) VALUES (1, '" . $tag . "', '" . $new . "')");
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, batch_id, datec) VALUES (2, '" . $tag . "', '" . $old . "')");
eo_assert('0 days keeps everything', easyocrWebhookPurgeLogRows($db, 1, 0), -1);
$del = easyocrWebhookPurgeLogRows($db, 1, 30, 2, 0);
$cnt = function ($entity) use ($db, $tag) {
	$r = $db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE batch_id = '" . $tag . "' AND entity = " . (int) $entity);
	return (int) $db->fetch_object($r)->n;
};
eo_assert('old rows deleted in batches of 2 (5 rows)', $del >= 5, true);
eo_assert('the recent row of the entity is kept', $cnt(1), 1);
eo_assert('rows of another entity are not touched', $cnt(2), 1);

$db->rollback();

// ═════════════════════════════════════════════════════════════════════════
// 3. Real HTTP against the local site
// ═════════════════════════════════════════════════════════════════════════
$endpoint = 'https://erp-v16.local/easydoli/easyocr/webhook_batch.php';
if (parse_url($endpoint, PHP_URL_HOST) !== 'erp-v16.local') {
	fwrite(STDERR, "Refusing to send test requests anywhere but erp-v16.local\n");
	exit(1);
}

/**
 * @return array{0:int,1:string,2:string} status, headers, body
 */
function eo_http($url, $body = null, $method = 'POST')
{
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_SSL_VERIFYPEER => false, // local self-signed certificate of Laragon, test only
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
	));
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	}
	$raw = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	if (PHP_VERSION_ID < 80000) {
		curl_close($ch);
	}
	if ($raw === false) {
		return array(0, '', '');
	}
	return array($code, substr($raw, 0, $hsize), substr($raw, $hsize));
}

eo_group('HTTP: real webhook on erp-v16.local');
list($probeCode) = eo_http($endpoint, '{}');
if ($probeCode === 0) {
	eo_info('site not answering: HTTP checks skipped');
} else {
	$prevToken = easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_TOKEN', 1);
	$prevLegacy = easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
	$httpTok = easyocrWebhookNewToken();
	dolibarr_set_const($db, 'EASYOCR_WEBHOOK_TOKEN', $httpTok, 'chaine', 0, '', 1);
	dolibarr_del_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
	$docB = 'eo-test-http-' . uniqid();
	// Only what this run appends to the logs is inspected (older lines predate 2.9.0)
	$logToday = DOL_DATA_ROOT . '/easyocr/entity_1/webhook_logs/webhook_' . date('Y-m-d') . '.log';
	$sysLog = DOL_DATA_ROOT . '/dolibarr.log';
	clearstatcache();
	$logStart = is_file($logToday) ? filesize($logToday) : 0;
	$sysStart = is_file($sysLog) ? filesize($sysLog) : 0;

	try {
		$e999 = DOL_DATA_ROOT . '/easyocr/entity_999';
		list($c, $h, $b) = eo_http($endpoint . '?entity=999', '{"event":"batch.completed"}');
		eo_assert('no credentials: 403', $c, 403);
		eo_assert('unauthenticated request leaves nothing on disk (no entity_999 folder)', is_dir($e999), false);

		list($c, $h, $b) = eo_http($endpoint . '?token=' . str_repeat('0', 40), '{"event":"batch.completed"}');
		eo_assert('wrong token: 403', $c, 403);
		eo_assert('the 403 does not reveal the expected value nor lengths', strpos($b, $httpTok) === false && strpos($b, 'expected') === false && strpos($b, 'received') === false, true);

		list($c) = eo_http($endpoint . '?token=' . $httpTok, null, 'GET');
		eo_assert('GET with the token: 405', $c, 405);

		list($c, $h, $b) = eo_http($endpoint . '?token=' . $httpTok, json_encode(array('event' => 'batch.completed', 'batch_id' => $docB)));
		$j = json_decode($b, true);
		eo_assert('right token: 200 ok in token mode', array($c, isset($j['auth']) ? $j['auth'] : null), array(200, 'token'));
		eo_assert('the answer does not echo the token', strpos($b, $httpTok), false);

		// Idempotency over HTTP: the document is already in review → repeat, nothing new stored
		$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, batch_id, document_id, processing_status, invoice_id, datec) VALUES (1, '" . $docB . "', '" . $docB . "', 'review', 321, '" . $db->idate(dol_now()) . "')");
		$before = (int) $db->fetch_object($db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE batch_id = '" . $docB . "'"))->n;
		list($c, $h, $b) = eo_http($endpoint . '?token=' . $httpTok, json_encode(array('event' => 'batch.document.completed', 'batch_id' => $docB,
			'data' => array('document_id' => $docB, 'status' => 'completed', 'structured_data' => array('document_number' => 'X')))));
		$j = json_decode($b, true);
		$after = (int) $db->fetch_object($db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE batch_id = '" . $docB . "'"))->n;
		eo_assert('already processed document: repeat with the known invoice', array($c, $j['status'], $j['processing']['invoice_id']), array(200, 'repeat', 321));
		eo_assert('already processed document: nothing processed or stored again', $after, $before);

		// Fresh 2.9.0 install (schema at 3, no window): the instance_id URL never worked here
		list($c, $h, $b) = eo_http($endpoint . '?instance_id=' . urlencode($dolibarr_main_instance_unique_id), '{"event":"batch.completed"}');
		eo_assert('legacy on an install already at 2.9.0 with no window: 403', $c, 403);
		eo_assert('... and it does not open a window', easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1), '');

		// Updated from 2.8 (revision 2, API key set): first use opens the window, never pays; after it, 403
		dolibarr_set_const($db, 'EASYOCR_SCHEMA_REVISION', '2', 'chaine', 0, '', 1);
		list($c, $h, $b) = eo_http($endpoint . '?instance_id=' . urlencode($dolibarr_main_instance_unique_id) . '&pay=1&bank_id=1', json_encode(array('event' => 'batch.completed', 'batch_id' => $docB)));
		$j = json_decode($b, true);
		$legacySet = (int) easyocrWebhookReadConst($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
		eo_assert('legacy instance_id: accepted in legacy mode', array($c, isset($j['auth']) ? $j['auth'] : null), array(200, 'legacy'));
		eo_assert('legacy first use fixes the end of the window (~30 days)', abs($legacySet - (dol_now() + 30 * 86400)) < 600, true);
		eo_assert('legacy answer does not echo the instance id', strpos($b, (string) $dolibarr_main_instance_unique_id), false);
		dolibarr_set_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', (string) (dol_now() - 60), 'chaine', 0, '', 1);
		list($c, $h, $b) = eo_http($endpoint . '?instance_id=' . urlencode($dolibarr_main_instance_unique_id), '{"event":"batch.completed"}');
		$j = json_decode($b, true);
		eo_assert('legacy after the window: 403 legacy_expired', array($c, isset($j['reason']) ? $j['reason'] : null), array(403, 'legacy_expired'));

		// Nothing secret in today's file log
		clearstatcache();
		$logText = is_file($logToday) ? (string) file_get_contents($logToday, false, null, $logStart) : '';
		eo_assert('this run wrote to the file log', strlen($logText) > 0, true);
		eo_assert('file log has neither the token nor the instance id', strpos($logText, $httpTok) === false && strpos($logText, (string) $dolibarr_main_instance_unique_id) === false, true);
		if (is_file($sysLog) && filesize($sysLog) > $sysStart) {
			$tail = (string) file_get_contents($sysLog, false, null, $sysStart);
			eo_assert('dolibarr.log has neither the token nor the instance id', strpos($tail, $httpTok) === false && strpos($tail, (string) $dolibarr_main_instance_unique_id) === false, true);
		}
	} finally {
		// Leave the site as it was
		dolibarr_set_const($db, 'EASYOCR_SCHEMA_REVISION', (string) EASYOCR_SCHEMA_REVISION, 'chaine', 0, '', 1);
		$db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE batch_id = '" . $db->escape($docB) . "' OR document_id = '" . $db->escape($docB) . "'");
		if ($prevToken !== '') {
			dolibarr_set_const($db, 'EASYOCR_WEBHOOK_TOKEN', $prevToken, 'chaine', 0, '', 1);
		} else {
			dolibarr_del_const($db, 'EASYOCR_WEBHOOK_TOKEN', 1);
		}
		if ($prevLegacy !== '') {
			dolibarr_set_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', $prevLegacy, 'chaine', 0, '', 1);
		} else {
			dolibarr_del_const($db, 'EASYOCR_WEBHOOK_LEGACY_UNTIL', 1);
		}
	}
	$left = (int) $db->fetch_object($db->query("SELECT COUNT(*) AS n FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE batch_id = '" . $db->escape($docB) . "'"))->n;
	eo_assert('cleanup: no test rows left', $left, 0);
}

// ── Summary ──────────────────────────────────────────────────────────────
$total = $GLOBALS['eo_pass'] + $GLOBALS['eo_fail'];
echo "\n" . str_repeat('─', 52) . "\n";
if ($GLOBALS['eo_fail'] === 0) {
	echo "\033[32mAll " . $total . " webhook assertions passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $GLOBALS['eo_fail'] . " of " . $total . " webhook assertions failed.\033[0m\n";
exit(1);
