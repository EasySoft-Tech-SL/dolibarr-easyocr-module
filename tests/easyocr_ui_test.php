<?php
/**
 * EasyOcr — pages, menu and trigger of 2.9.0 against a real Dolibarr.
 *
 * Three parts:
 *  1. Descriptor: triggers on, minimum Dolibarr 16, rights untouched, new menu entries.
 *  2. Trigger (GDPR clean-up, merge, search keys): inside a transaction that is always
 *     rolled back. The trigger is switched on in memory, as activating the module does.
 *  3. Pages over HTTP (invoices.php, index.php, duplicates.php) with a throwaway user and
 *     throwaway data that are committed (the web server must see them) and deleted at the
 *     end, also when the script dies half way.
 *
 * Run:  php tests/easyocr_ui_test.php [base-url]
 *       base-url defaults to https://erp-v16.local
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}

$baseUrl = rtrim(isset($argv[1]) ? $argv[1] : 'https://erp-v16.local', '/');

// ── Precondition: the database must be reachable ─────────────────────────
$confFile = __DIR__ . '/../../../conf/conf.php';
if (is_readable($confFile)) {
	include $confFile;
	$dbHost = !empty($dolibarr_main_db_host) ? $dolibarr_main_db_host : '127.0.0.1';
	$dbPort = !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306;
	$probe = @fsockopen($dbHost === 'localhost' ? '127.0.0.1' : $dbHost, $dbPort, $errno, $errstr, 3);
	if ($probe === false) {
		fwrite(STDERR, "Database unreachable at " . $dbHost . ':' . $dbPort . " - " . $errstr . "\n");
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

require_once __DIR__ . '/../../../master.inc.php';
require_once __DIR__ . '/../lib/easyocr.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once __DIR__ . '/../core/modules/modEasyocr.class.php';

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

function eo_one($sql)
{
	global $db;
	$res = $db->query($sql);
	if (!$res) {
		return null;
	}
	$obj = $db->fetch_object($res);

	return $obj ? $obj : null;
}

$resAdmin = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
$user = new User($db);
$user->fetch($db->fetch_object($resAdmin)->rowid);
$user->getrights();

// The trigger runs only once the module is activated with module_parts['triggers'] = 1.
// The test switches it on in memory: the database constant is not touched.
if (!isset($conf->modules_parts['triggers']) || !is_array($conf->modules_parts['triggers'])) {
	$conf->modules_parts['triggers'] = array();
}
$conf->modules_parts['triggers']['easyocr'] = '/easyocr/core/triggers/';

$rnd = (string) mt_rand(1000000, 9999999);

/**
 * Throwaway third party.
 */
function eo_soc($name, $supplier, $customer, $siren = '', $tva = '')
{
	global $db, $user, $mysoc;
	$s = new Societe($db);
	$s->name = $name;
	$s->fournisseur = $supplier;
	$s->client = $customer;
	$s->country_id = $mysoc->country_id;
	$s->idprof1 = $siren;
	$s->tva_intra = $tva;
	$s->status = 1;
	if ($supplier) {
		$s->get_codefournisseur();
	}
	if ($customer) {
		$s->get_codeclient($s, 0);
	}
	$id = $s->create($user);
	if ($id <= 0) {
		eo_info('could not create third party: ' . $s->error . ' ' . implode(' | ', (array) $s->errors));
	}

	return (int) $id;
}

/**
 * Throwaway supplier invoice with an import_key and a status.
 */
function eo_inv($socid, $ref, $importKey, $status = 0)
{
	global $db, $user;
	$f = new FactureFournisseur($db);
	$f->socid = $socid;
	$f->ref_supplier = $ref;
	$f->date = dol_now();
	$id = (int) $f->create($user);
	if ($id > 0) {
		$db->query("UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET import_key = " . ($importKey === null ? "NULL" : "'" . $db->escape($importKey) . "'") . ", fk_statut = " . ((int) $status) . " WHERE rowid = " . $id);
	}

	return $id;
}

// =========================================================================
// 1. Descriptor
// =========================================================================
eo_group('descriptor');
$mod = new modEasyocr($db);
eo_assert('module_parts triggers is on', $mod->module_parts['triggers'], 1);
eo_assert('minimum Dolibarr is 16.0.0', $mod->need_dolibarr_version, array(16, 0, 0));
eo_assert('minimum PHP stays 7.4', $mod->phpmin, array(7, 4));
$rightCodes = array();
foreach ($mod->rights as $r) {
	$rightCodes[] = $r[0] . ':' . $r[4];
}
eo_assert('rights keep their ids and order', $rightCodes, array('40202001:read', '40202002:write', '40202003:delete'));
eo_assert('top menu stays the first entry', $mod->menu[0]['type'] . ':' . $mod->menu[0]['url'], 'top:/easyocr/index.php');
$menuByUrl = array();
foreach ($mod->menu as $i => $m) {
	$menuByUrl[$m['url']] = $m + array('_pos' => $i);
}
eo_assert('menu: received invoices log entry', isset($menuByUrl['/easyocr/webhook_logs.php']) ? $menuByUrl['/easyocr/webhook_logs.php']['perms'] : null, '$user->rights->easyocr->read');
eo_assert('menu: received invoices log title', isset($menuByUrl['/easyocr/webhook_logs.php']) ? $menuByUrl['/easyocr/webhook_logs.php']['titre'] : null, 'EasyOcrMenuReceivedLog');
eo_assert('menu: duplicates entry needs third-party rights too', isset($menuByUrl['/easyocr/duplicates.php']) ? $menuByUrl['/easyocr/duplicates.php']['perms'] : null, '$user->rights->easyocr->read && $user->rights->societe->lire');
eo_assert('menu: new entries are left entries under easyocr', isset($menuByUrl['/easyocr/duplicates.php']) ? $menuByUrl['/easyocr/duplicates.php']['type'] . ':' . $menuByUrl['/easyocr/duplicates.php']['fk_menu'] : null, 'left:fk_mainmenu=easyocr');
eo_assert('trigger file follows the naming Dolibarr scans', is_readable(__DIR__ . '/../core/triggers/interface_99_modEasyocr_EasyocrTriggers.class.php'), true);

// =========================================================================
// 2. Trigger, rolled back
// =========================================================================
eo_group('trigger (rolled back)');
$db->begin();

$tplHasTable = easyocrTpKeyTableExists();
$sA = eo_soc('EOUI TRIGGER A ' . $rnd, 1, 0, 'B1' . $rnd);
$sB = eo_soc('EOUI TRIGGER B ' . $rnd, 1, 0);
if (!eo_assert('fixture: two suppliers', $sA > 0 && $sB > 0, true)) {
	// A deadlock with another suite running at the same time makes MySQL roll back the
	// whole transaction; anything written after it would be committed. Stop here.
	$db->rollback();
	foreach (array($sA, $sB) as $id) {
		$s = new Societe($db);
		if ($id > 0 && $s->fetch($id) > 0) {
			$s->delete($id, $user);
		}
	}
	goto summary;
}
if ($tplHasTable) {
	$n = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "easyocr_tpkey WHERE fk_soc = " . $sA);
	eo_assert('COMPANY_CREATE writes the search keys', $n && (int) $n->n > 0, true);
} else {
	eo_info('llx_easyocr_tpkey not present: search-key checks skipped');
}

$invId = eo_inv($sA, 'EOUI-TRG-' . $rnd, 'easyocr-wh', 0);
eo_assert('fixture: supplier invoice', $invId > 0, true);

$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, batch_id, event, document_filename, document_status, invoice_id, invoice_ref, supplier_id, processing_status, processing_message, payload, datec)"
	. " VALUES (" . (int) $conf->entity . ", 'EOUI" . $rnd . "', 'document.completed', 'factura-persona.pdf', 'completed', " . $invId . ", 'REF-X', " . $sA . ", 'ok', 'Juan Perez', '{\"nombre\":\"Juan Perez\"}', '" . $db->idate(dol_now()) . "')");
$logId = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_webhook_log');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_processed_files (entity, file_hash, filename, file_size, fk_facture_fourn, date_creation)"
	. " VALUES (" . (int) $conf->entity . ", 'eoui" . $rnd . "', 'factura-persona.pdf', 10, " . $invId . ", '" . $db->idate(dol_now()) . "')");
$inboxDir = easyocrInboxDir();
dol_mkdir($inboxDir);
$inboxName = 'eoui_' . $rnd . '.pdf';
file_put_contents($inboxDir . '/' . $inboxName, '%PDF-1.4 test');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_inbox (entity, filename, origin, status, fk_invoice_created, datec)"
	. " VALUES (" . (int) $conf->entity . ", '" . $inboxName . "', 'manual', 2, " . $invId . ", '" . $db->idate(dol_now()) . "')");
$inboxDone = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_inbox');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_inbox (entity, filename, origin, status, fk_facture_fourn, datec)"
	. " VALUES (" . (int) $conf->entity . ", 'eoui_pending_" . $rnd . ".pdf', 'manual', 0, " . $invId . ", '" . $db->idate(dol_now()) . "')");
$inboxPending = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_inbox');

$inv = new FactureFournisseur($db);
$inv->fetch($invId);
$resDel = $inv->delete($user);
eo_assert('BILL_SUPPLIER_DELETE: the core delete still succeeds', $resDel > 0, true);
$log = eo_one("SELECT payload, invoice_id, invoice_ref, document_filename, processing_message, processing_status FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE rowid = " . $logId);
eo_assert('webhook log row is kept for statistics', $log !== null, true);
eo_assert('webhook log: payload removed', $log ? $log->payload : 'x', null);
eo_assert('webhook log: invoice references removed', $log ? array($log->invoice_id, $log->invoice_ref) : 'x', array(null, null));
eo_assert('webhook log: file name and message removed', $log ? array($log->document_filename, $log->processing_message) : 'x', array(null, null));
eo_assert('webhook log: status kept', $log ? $log->processing_status : null, 'ok');
$pf = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "easyocr_processed_files WHERE file_hash = 'eoui" . $rnd . "'");
eo_assert('anti-duplicate fingerprint removed', (int) $pf->n, 0);
$ib = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "easyocr_inbox WHERE rowid = " . $inboxDone);
eo_assert('inbox row processed into the invoice removed', (int) $ib->n, 0);
eo_assert('inbox file processed into the invoice deleted', is_file($inboxDir . '/' . $inboxName), false);
$ib2 = eo_one("SELECT fk_facture_fourn FROM " . MAIN_DB_PREFIX . "easyocr_inbox WHERE rowid = " . $inboxPending);
eo_assert('pending inbox document stays, unattached', $ib2 ? $ib2->fk_facture_fourn : 'gone', null);

// Merge: templates follow the third party kept
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (entity, name, fk_soc) VALUES (" . (int) $conf->entity . ", 'EOUI TPL B', " . $sB . ")");
$tplB = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_template');
$socA = new Societe($db);
$socA->fetch($sA);
$socA->context = array('merge' => 1, 'mergefromid' => $sB);
$socA->call_trigger('COMPANY_MODIFY', $user);
$t = eo_one("SELECT fk_soc FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . $tplB);
eo_assert('merge: template moves to the third party kept', $t ? (int) $t->fk_soc : 0, $sA);

// Delete: templates of the deleted supplier go, with their details
$sC = eo_soc('EOUI TRIGGER C ' . $rnd, 1, 0);
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (entity, name, fk_soc) VALUES (" . (int) $conf->entity . ", 'EOUI TPL C', " . $sC . ")");
$tplC = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_template');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details (entity, fk_template, page_index, pos_x, pos_y, sel_w, sel_h, label) VALUES (" . (int) $conf->entity . ", " . $tplC . ", 0, 1, 1, 1, 1, 'x')");
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_webhook_log (entity, batch_id, supplier_id, datec) VALUES (" . (int) $conf->entity . ", 'EOUI" . $rnd . "', " . $sC . ", '" . $db->idate(dol_now()) . "')");
$logC = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_webhook_log');
$socC = new Societe($db);
$socC->fetch($sC);
eo_assert('COMPANY_DELETE: the core delete still succeeds', $socC->delete($sC, $user) > 0, true);
$t = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . $tplC);
eo_assert('delete: template of the supplier removed', (int) $t->n, 0);
$t = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template = " . $tplC);
eo_assert('delete: its details removed', (int) $t->n, 0);
$t = eo_one("SELECT supplier_id FROM " . MAIN_DB_PREFIX . "easyocr_webhook_log WHERE rowid = " . $logC);
eo_assert('delete: webhook log forgets the supplier', $t ? $t->supplier_id : 'gone', null);
if ($tplHasTable) {
	$n = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "easyocr_tpkey WHERE fk_soc = " . $sC);
	eo_assert('COMPANY_DELETE removes the search keys', (int) $n->n, 0);
}

$db->rollback();
@unlink($inboxDir . '/' . $inboxName);
$t = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "societe WHERE rowid IN (" . $sA . "," . $sB . "," . $sC . ")");
eo_assert('trigger fixtures gone after rollback', (int) $t->n, 0);

// =========================================================================
// 3. Pages over HTTP
// =========================================================================
eo_group('pages over HTTP (' . $baseUrl . ')');

$cleanup = array('inv' => array(), 'soc' => array(), 'user' => 0, 'cookie' => '');
register_shutdown_function(function () use (&$cleanup) {
	global $db, $user;
	foreach ($cleanup['inv'] as $id) {
		$f = new FactureFournisseur($db);
		if ($f->fetch($id) > 0) {
			$db->query("UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET fk_statut = 0 WHERE rowid = " . (int) $id);
			$f->statut = 0;
			$f->status = 0;
			$f->delete($user);
		}
	}
	foreach ($cleanup['soc'] as $id) {
		// Other suites may be deleting third parties at the same time: retry a deadlock
		for ($try = 0; $try < 3; $try++) {
			$s = new Societe($db);
			if ($s->fetch($id) <= 0 || $s->delete($id, $user) > 0) {
				break;
			}
			usleep(500000);
		}
	}
	if ($cleanup['user'] > 0) {
		$u = new User($db);
		if ($u->fetch($cleanup['user']) > 0) {
			$u->delete($user);
		}
	}
	if ($cleanup['cookie'] !== '') {
		@unlink($cleanup['cookie']);
	}
	$left = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "societe WHERE nom LIKE 'EOUI %'");
	$leftU = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "user WHERE login LIKE 'eouitest%'");
	echo "\n\033[2mcleanup: test third parties left=" . ($left ? $left->n : '?') . ", test users left=" . ($leftU ? $leftU->n : '?') . "\033[0m\n";
});

/**
 * HTTP request with the test session.
 *
 * @return array{code:int,body:string,location:string}
 */
function eo_http($url, $post = null)
{
	global $cleanup;
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_SSL_VERIFYPEER => false,	// local Laragon certificate
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_COOKIEJAR => $cleanup['cookie'],
		CURLOPT_COOKIEFILE => $cleanup['cookie'],
		CURLOPT_TIMEOUT => 60,
	));
	if ($post !== null) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}
	$raw = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$raw = (string) $raw;
	$head = substr($raw, 0, $hsize);
	$location = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';

	return array('code' => $code, 'body' => substr($raw, $hsize), 'location' => $location);
}

function eo_token($html)
{
	return preg_match('/name="token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$cleanup['cookie'] = tempnam(sys_get_temp_dir(), 'eoui');
$probe = eo_http($baseUrl . '/index.php');
if ($probe['code'] === 0) {
	eo_assert('web server reachable', false, true);
	goto summary;
}

// Throwaway user with exactly the rights the pages need
$login = 'eouitest' . $rnd;
$pass = 'Eo!' . $rnd . 'xZ';
$u = new User($db);
$u->login = $login;
$u->lastname = 'EOUI TEST';
$u->admin = 0;
$u->entity = $conf->entity;
$uid = (int) $u->create($user);
$cleanup['user'] = $uid;
eo_assert('fixture: test user', $uid > 0, true);
$u->setPassword($user, $pass);
$u->addrights(0, 'easyocr', '', $conf->entity);
$u->addrights(0, 'societe', '', $conf->entity);
$u->addrights(0, 'fournisseur', '', $conf->entity);

// Data: supplier A with 2 drafts and 1 validated invoice; duplicates of A by tax id
$cif = 'B7' . $rnd;
$sA = eo_soc('EOUI DUP SUPPLIER A ' . $rnd, 1, 0, '', 'ES' . $cif);
$sA2 = eo_soc('EOUI DUP SUPPLIER A2 ' . $rnd, 1, 0, $cif, '');
$sA3 = eo_soc('EOUI DUP CUSTOMER A3 ' . $rnd, 0, 1, $cif, '');
$cifC = 'A6' . $rnd;
$sC1 = eo_soc('EOUI DUP CUSTOMER ONLY 1 ' . $rnd, 0, 1, $cifC, '');
$sC2 = eo_soc('EOUI DUP CUSTOMER ONLY 2 ' . $rnd, 0, 1, '', 'ES' . $cifC);
// Same name once the legal form is ignored, no tax id to tell them apart
$sN1 = eo_soc('EOUI NAMEDUP ' . $rnd . ' S.L.', 1, 0);
$sN2 = eo_soc('EOUI NAMEDUP ' . $rnd, 1, 0);
foreach (array($sA, $sA2, $sA3, $sC1, $sC2, $sN1, $sN2) as $id) {
	if ($id > 0) {
		$cleanup['soc'][] = $id;
	}
}
eo_assert('fixture: seven third parties', count($cleanup['soc']), 7);
$d1 = eo_inv($sA, 'EOUI-D1-' . $rnd, 'easyocr-wh', 0);
$d2 = eo_inv($sA, 'EOUI-D2-' . $rnd, 'easyocr-ai', 0);
$v1 = eo_inv($sA, 'EOUI-V1-' . $rnd, 'easyocr', 1);
foreach (array($d1, $d2, $v1) as $id) {
	if ($id > 0) {
		$cleanup['inv'][] = $id;
	}
}
eo_assert('fixture: three EasyOcr invoices', count($cleanup['inv']), 3);
$refOf = function ($id) {
	$o = eo_one("SELECT ref FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . (int) $id);
	return $o ? $o->ref : '';
};

// Log in
$page = eo_http($baseUrl . '/index.php');
$r = eo_http($baseUrl . '/index.php', array('token' => eo_token($page['body']), 'actionlogin' => 'login', 'loginfunction' => 'loginfunction', 'username' => $login, 'password' => $pass));
$home = eo_http($baseUrl . '/index.php');
eo_assert('login as the test user', strpos($home['body'], 'name="password"') === false && $home['code'] === 200, true);

// ── invoices.php ──
$mod = $baseUrl . '/easydoli/easyocr';
$list = eo_http($mod . '/invoices.php?search_status=0&search_supplier=' . $sA . '&limit=100');
eo_assert('invoices: page loads', $list['code'], 200);
eo_assert('invoices: status column rendered with Dolibarr\'s label', substr_count($list['body'], 'eo-inv-status'), 2);
eo_assert('invoices: pending filter shows the drafts', strpos($list['body'], $refOf($d1)) !== false && strpos($list['body'], $refOf($d2)) !== false, true);
eo_assert('invoices: pending filter hides the validated one', strpos($list['body'], $refOf($v1)), false);
eo_assert('invoices: the filter select keeps the choice', (bool) preg_match('/<option value="0" selected>/', $list['body']), true);
$all = eo_http($mod . '/invoices.php?search_supplier=' . $sA . '&limit=100');
eo_assert('invoices: without filter all three appear', substr_count($all['body'], 'eo-inv-status'), 3);
eo_assert('invoices: webhook invoices get their own badge', strpos($all['body'], '>Webhook</span>') !== false, true);
$paged = eo_http($mod . '/invoices.php?search_status=0&search_supplier=' . $sA . '&limit=1');
eo_assert('invoices: one row per page with limit=1', substr_count($paged['body'], 'eo-inv-status'), 1);
eo_assert('invoices: pagination links keep the pending filter', (bool) preg_match('/href="[^"]*page=1[^"]*search_status=0/', str_replace('&amp;', '&', $paged['body'])), true);

// Remove the mark of a webhook invoice (it was left out before 2.9.0)
$conf1 = eo_http($mod . '/invoices.php?action=delete&id=' . $d1 . '&token=' . eo_token($all['body']));
eo_assert('invoices: delete asks with formconfirm', strpos($conf1['body'], 'confirm_delete') !== false, true);
$tok = eo_token($conf1['body']);
$del = eo_http($mod . '/invoices.php?action=confirm_delete&confirm=yes&id=' . $d1 . '&search_status=0&token=' . $tok);
$ik = eo_one("SELECT import_key FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . $d1);
eo_assert('invoices: webhook invoice loses its mark', $ik ? $ik->import_key : 'x', null);
eo_assert('invoices: success redirects keeping the filter', $del['code'] === 302 && strpos($del['location'], 'search_status=0') !== false, true);
$again = eo_http($mod . '/invoices.php?action=confirm_delete&confirm=yes&id=' . $d1 . '&token=' . eo_token(eo_http($mod . '/invoices.php')['body']));
eo_assert('invoices: nothing to remove is not reported as removed (no redirect)', $again['code'], 200);
$csrf = eo_http($mod . '/invoices.php?action=confirm_delete&confirm=yes&id=' . $d2 . '&token=bad');
$ik = eo_one("SELECT import_key FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . $d2);
eo_assert('invoices: a wrong token changes nothing', $ik ? $ik->import_key : null, 'easyocr-ai');

// ── index.php ──
$expected = eo_one("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE import_key IN ('easyocr','easyocr-ai','easyocr-wh') AND fk_statut = 0 AND entity IN (" . getEntity('facture_fourn') . ")");
$idx = eo_http($mod . '/index.php');
eo_assert('index: page loads', $idx['code'], 200);
$shown = preg_match('/id="eo-stat-review">.*?<strong>(\d+)<\/strong>/s', $idx['body'], $m) ? (int) $m[1] : -1;
eo_assert('index: drafts waiting for review counted', $shown, (int) $expected->n);
eo_assert('index: the counter links to the pending filter', (bool) preg_match('/href="[^"]*invoices\.php\?search_status=0"[^>]*id="eo-stat-review"/', $idx['body']), true);
eo_assert('index: duplicates card links to the report', (bool) preg_match('/href="[^"]*duplicates\.php"[^>]*id="eo-card-duplicates"/', $idx['body']), true);

// ── duplicates.php ──
$dup = eo_http($mod . '/duplicates.php');
eo_assert('duplicates: page loads', $dup['code'], 200);
$tables = preg_split('/<table class="noborder centpercent eo-dup-table">/', $dup['body']);
$parse = function ($html) {
	$g = array();
	if (preg_match_all('/data-group="([^"]*)" data-socid="(\d+)"/', $html, $mm, PREG_SET_ORDER)) {
		foreach ($mm as $x) {
			$g[html_entity_decode($x[1])][] = (int) $x[2];
		}
	}
	$out = array();
	foreach ($g as $ids) {
		sort($ids);
		$out[] = implode(',', $ids);
	}
	sort($out);
	return $out;
};
// Expected: the lib's grouping over EVERY active third party, minus customer-only tax groups
$all = array();
$res = $db->query("SELECT rowid, nom, siren, tva_intra, fournisseur, client FROM " . MAIN_DB_PREFIX . "societe WHERE status = 1 AND entity IN (" . getEntity('societe') . ")");
while ($o = $db->fetch_object($res)) {
	$all[] = array('id' => (int) $o->rowid, 'name' => $o->nom, 'siren' => $o->siren, 'tva_intra' => $o->tva_intra, 'fournisseur' => (int) $o->fournisseur, 'client' => (int) $o->client);
}
$exp = easyocrGroupDuplicateThirdparties($all, easyocrOwnCountryCode());
$expTax = array();
foreach ($exp['by_tax_id'] as $members) {
	$ids = array();
	$sup = false;
	foreach ($members as $mm) {
		$ids[] = (int) $mm['id'];
		$sup = $sup || !empty($mm['fournisseur']);
	}
	if ($sup) {
		sort($ids);
		$expTax[] = implode(',', $ids);
	}
}
sort($expTax);
$expName = array();
foreach ($exp['by_name'] as $members) {
	$ids = array();
	foreach ($members as $mm) {
		$ids[] = (int) $mm['id'];
	}
	sort($ids);
	$expName[] = implode(',', $ids);
}
sort($expName);
$gotTax = isset($tables[1]) ? $parse(explode('</table>', $tables[1])[0]) : array();
$gotName = isset($tables[2]) ? $parse(explode('</table>', $tables[2])[0]) : array();
eo_assert('duplicates: tax-id groups equal the lib on the whole table (two-pass read loses nothing)', $gotTax, $expTax);
eo_assert('duplicates: name groups equal the lib on the whole table', $gotName, $expName);
$mine = array($sA, $sA2, $sA3);
sort($mine);
eo_assert('duplicates: supplier + customer sharing the tax id is listed', in_array(implode(',', $mine), $gotTax, true), true);
$cust = array($sC1, $sC2);
sort($cust);
$names = array($sN1, $sN2);
sort($names);
eo_assert('duplicates: same-name suppliers without tax id are listed by name', in_array(implode(',', $names), $gotName, true), true);
eo_assert('duplicates: customers only sharing a tax id are left out', in_array(implode(',', $cust), $gotTax, true), false);
eo_assert('duplicates: the customer is marked as not a supplier', (bool) preg_match('/data-socid="' . $sA3 . '".*?eo-dup-notsupplier.*?<\/tr>/s', $dup['body']), true);
eo_assert('duplicates: tax-id block has no group column', isset($tables[1]) ? substr_count(explode('</tr>', $tables[1])[0], '<td') : -1, 6);
eo_assert('duplicates: name block keeps the group column', isset($tables[2]) ? substr_count(explode('</tr>', $tables[2])[0], '<td') : -1, 7);
$rowA = preg_match('/<tr[^>]*data-socid="' . $sA . '".*?<\/tr>/s', $dup['body'], $mA) ? $mA[0] : '';
eo_assert('duplicates: the one with most invoices is suggested', strpos($rowA, 'eo-dup-suggested') !== false, true);
eo_assert('duplicates: invoices per third party shown', (bool) preg_match('/eo-dup-nb">3</', $rowA), true);
eo_assert('duplicates: merge button opens Dolibarr\'s merge on that third party', (bool) preg_match('#class="butAction [^"]*eo-dup-merge" href="[^"]*/societe/card\.php\?socid=' . $sA . '&(amp;)?action=merge&(amp;)?token=[^"]+"#', $rowA), true);
$rowA2 = preg_match('/<tr[^>]*data-socid="' . $sA2 . '".*?<\/tr>/s', $dup['body'], $mA2) ? $mA2[0] : '';
eo_assert('duplicates: the others are not suggested', strpos($rowA2, 'eo-dup-suggested'), false);
$merge = eo_http($baseUrl . '/societe/card.php?socid=' . $sA . '&action=merge&token=' . eo_token($dup['body']));
eo_assert('duplicates: the merge dialog of Dolibarr opens', strpos($merge['body'], 'soc_origin') !== false, true);

summary:
$total = $GLOBALS['eo_pass'] + $GLOBALS['eo_fail'];
echo "\n" . str_repeat('-', 52) . "\n";
if ($GLOBALS['eo_fail'] === 0) {
	echo "\033[32mAll " . $total . " UI assertions passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $GLOBALS['eo_fail'] . " of " . $total . " UI assertions failed.\033[0m\n";
exit(1);
