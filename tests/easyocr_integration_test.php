<?php
/**
 * EasyOcr — end-to-end integration test against a real Dolibarr database.
 *
 * Unlike easyocr_lib_test.php (pure functions, no environment), this one boots
 * Dolibarr, creates a supplier and a supplier invoice from the payload the AI
 * service returned for EASYOCR-TEST-01, and then reads the resulting rows back
 * out of llx_facture_fourn_det to check what the module actually persisted.
 *
 * Everything runs inside a transaction that is ALWAYS rolled back, so the
 * database is left exactly as it was found.
 *
 * Run:  php tests/easyocr_integration_test.php
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}

// ── Precondition: the database must be reachable ─────────────────────────
// Dolibarr's master.inc.php prints an HTML error page and exits 0 when it
// cannot connect, which would make this suite look like it passed without
// having checked anything. Fail loudly instead.
$confFile = __DIR__ . '/../../../conf/conf.php';
if (is_readable($confFile)) {
	include $confFile;
	$dbHost = !empty($dolibarr_main_db_host) ? $dolibarr_main_db_host : '127.0.0.1';
	$dbPort = !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306;
	$probe = @fsockopen($dbHost === 'localhost' ? '127.0.0.1' : $dbHost, $dbPort, $errno, $errstr, 3);
	if ($probe === false) {
		fwrite(STDERR, "\033[31mDatabase unreachable at " . $dbHost . ':' . $dbPort . " — " . $errstr . "\033[0m\n");
		fwrite(STDERR, "Start MySQL/MariaDB (Laragon) and run this again.\n");
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
require_once __DIR__ . '/../lib/easyocr.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

// ── Tiny assertion harness (same style as easyocr_lib_test.php) ──────────
$GLOBALS['eo_pass'] = 0;
$GLOBALS['eo_fail'] = 0;

function eo_group($name)
{
	echo "\n\033[1m" . $name . "\033[0m\n";
}

function eo_assert($name, $actual, $expected, $delta = null)
{
	$ok = ($delta === null)
		? ($actual === $expected)
		: (is_numeric($actual) && abs((float) $actual - (float) $expected) <= $delta);

	if ($ok) {
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

// ── Environment sanity ───────────────────────────────────────────────────
eo_group('environment');
eo_assert('database handle is available', is_object($db), true);
eo_assert('$mysoc has a country', !empty($mysoc->country_code), true);
eo_info('entity=' . $conf->entity . ' db=' . $conf->db->name . ' mysoc=' . $mysoc->name . ' (' . $mysoc->country_code . ')');

$sqlAdmin = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1";
$resAdmin = $db->query($sqlAdmin);
if (!$resAdmin || $db->num_rows($resAdmin) < 1) {
	fwrite(STDERR, "No active admin user found; cannot run integration test.\n");
	exit(1);
}
$user = new User($db);
$user->fetch($db->fetch_object($resAdmin)->rowid);
eo_assert('admin user loaded', $user->id > 0, true);
eo_info('running as ' . $user->login . ' (id ' . $user->id . ')');

// Everything from here on is rolled back
$db->begin();

$cleanupOk = true;
register_shutdown_function(function () use ($db) {
	// Belt and braces: if anything fatals, still leave the database untouched
	if (!empty($db->transaction_opened)) {
		$db->rollback();
		echo "\n\033[33mTransaction rolled back on shutdown.\033[0m\n";
	}
});

// ── Fixture: a throwaway supplier ────────────────────────────────────────
eo_group('fixture');

$supplier = new Societe($db);
$supplier->name       = 'EASYOCR INTEGRATION TEST SUPPLIER';
$supplier->fournisseur = 1;
$supplier->client     = 0;
$supplier->country_id = $mysoc->country_id;
$supplier->idprof1    = 'B99999999';   // deliberately NOT our own tax id
$supplier->tva_intra  = '';
$supplier->status     = 1;
$supplierId = $supplier->create($user);
eo_assert('test supplier created', $supplierId > 0, true);
if ($supplierId <= 0) {
	eo_info('error: ' . $supplier->error . ' ' . implode(' | ', $supplier->errors));
	$db->rollback();
	exit(1);
}

// ── The payload the AI service returned for EASYOCR-TEST-01 ──────────────
// Unit prices arrive rounded to 2 decimals (0.347 -> 0.35, 3.4337 -> 3.43);
// net_amount is what the document actually prints.
function eo_tax($rate)
{
	return array(array('tax_type' => 'iva', 'tax_rate' => $rate));
}

$items = array(
	array('description' => 'Producto A', 'code' => 'REF-A', 'item_type' => 'product',
		'quantity' => 10, 'unit_price' => 5, 'discount_percent' => 10, 'net_amount' => 45, 'taxes' => eo_tax(21)),
	array('description' => 'Producto B', 'code' => 'REF-B', 'item_type' => 'product',
		'quantity' => 20, 'unit_price' => 12.5, 'discount_amount' => 25, 'net_amount' => 225, 'taxes' => eo_tax(21)),
	array('description' => 'Producto C', 'code' => 'REF-C', 'item_type' => 'product',
		'quantity' => 8, 'unit_price' => 15, 'net_amount' => 102, 'taxes' => eo_tax(21)),
	array('description' => 'Tornillo M6 (3 decimales)', 'code' => 'TOR-M6', 'item_type' => 'product',
		'quantity' => 1000, 'unit_price' => 0.35, 'net_amount' => 347, 'taxes' => eo_tax(21)),
	array('description' => 'Arandela 15 (3 decimales)', 'code' => 'ARN-15', 'item_type' => 'product',
		'quantity' => 1500, 'unit_price' => 3.43, 'net_amount' => 5150.5, 'taxes' => eo_tax(21)),
	array('description' => 'Descuento comercial', 'code' => '', 'item_type' => 'discount',
		'quantity' => 1, 'unit_price' => -50, 'net_amount' => -50, 'taxes' => eo_tax(21)),
	array('description' => 'Portes', 'code' => 'PORTES', 'item_type' => 'service',
		'quantity' => 1, 'unit_price' => 35, 'net_amount' => 35, 'taxes' => eo_tax(21)),
	array('description' => 'Servicio de montaje', 'code' => 'MONTAJE', 'item_type' => 'service',
		'quantity' => 1, 'unit_price' => 200, 'net_amount' => 200, 'taxes' => eo_tax(21)),
);

$params = array(
	'fk_soc'           => $supplierId,
	'ref_supplier'     => 'EASYOCR-TEST-01-' . substr(md5(microtime(true)), 0, 6),
	'datef'            => '2026-07-14',
	'date_echeance'    => '2026-08-13',
	'total_ht'         => 6054.50,
	'total_tva'        => 1271.45,
	'total_ttc'        => 7325.95,
	'default_tax_rate' => 21,
	'invoice_status'   => 'draft',
	'items'            => $items,
	'import_key'       => 'easyocr-itest',
);

// ── Create the invoice ───────────────────────────────────────────────────
eo_group('easyocrCreateInvoiceFromOCR — TEST-01');

$maxDetBefore = (int) $db->fetch_object($db->query("SELECT COALESCE(MAX(rowid),0) m FROM " . MAIN_DB_PREFIX . "facture_fourn_det"))->m;

$result = easyocrCreateInvoiceFromOCR($params, $user);
eo_info('transaction_opened=' . var_export($db->transaction_opened, true) . ' lasterror=' . $db->lasterror());
$newRows = (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "facture_fourn_det WHERE rowid > " . $maxDetBefore))->n;
eo_info('detail rows inserted since the call: ' . $newRows);
eo_assert('creation reports success', isset($result['status']) ? $result['status'] : 'missing', 'ok');
if (!isset($result['status']) || $result['status'] !== 'ok') {
	eo_info('message: ' . (isset($result['message']) ? $result['message'] : '(none)'));
	$db->rollback();
	echo "\n\033[31mAborting: the invoice was not created.\033[0m\n";
	exit(1);
}

$invoiceId = (int) $result['invoice_id'];
eo_assert('an invoice id came back', $invoiceId > 0, true);
eo_info('invoice ' . (isset($result['ref']) ? $result['ref'] : '?') . ' id=' . $invoiceId);

if (!empty($result['line_errors'])) {
	foreach ($result['line_errors'] as $le) {
		eo_info('line error: ' . (is_string($le) ? $le : json_encode($le)));
	}
}
if (!empty($result['totals_warnings'])) {
	foreach ($result['totals_warnings'] as $w) {
		eo_info('totals warning: ' . $w['field'] . ' expected ' . $w['expected'] . ' computed ' . $w['computed'] . ' (diff ' . $w['diff'] . ')');
	}
}

// ── Read the lines back out of the database ──────────────────────────────
eo_group('persisted invoice lines');

// NB: supplier invoice lines store VAT in `tva`, not `total_tva` (that column
// only exists on customer invoice lines). A typo here returns an empty result
// set that looks exactly like "no lines were created", so the query is checked.
$sqlLines  = "SELECT rowid, description, ref, qty, pu_ht, remise_percent, total_ht, tva, tva_tx, fk_product";
$sqlLines .= " FROM " . MAIN_DB_PREFIX . "facture_fourn_det";
$sqlLines .= " WHERE fk_facture_fourn = " . $invoiceId;
$sqlLines .= " ORDER BY rowid ASC";
$resLines = $db->query($sqlLines);
if (!$resLines) {
	eo_assert('the lines query is valid SQL', $db->lasterror(), '');
}
$lines = array();
while ($resLines && ($o = $db->fetch_object($resLines))) {
	$lines[] = $o;
}
eo_assert('all eight lines were persisted', count($lines), 8);

if (count($lines) === 8) {
	// Expected: [gross unit price, discount %, line net]
	$expected = array(
		array('label' => 'explicit 10% discount',        'pu' => 5.0,     'remise' => 10.0, 'net' => 45.0,     'ref' => 'REF-A'),
		array('label' => 'discount_amount 25 EUR',       'pu' => 12.5,    'remise' => 10.0, 'net' => 225.0,    'ref' => 'REF-B'),
		array('label' => 'implicit gap 120 -> 102',      'pu' => 15.0,    'remise' => 15.0, 'net' => 102.0,    'ref' => 'REF-C'),
		array('label' => '3-decimal price 0.347',        'pu' => 0.347,   'remise' => 0.0,  'net' => 347.0,    'ref' => 'TOR-M6'),
		array('label' => '4-decimal price 3.4337',       'pu' => 3.43367, 'remise' => 0.0,  'net' => 5150.5,   'ref' => 'ARN-15'),
		array('label' => 'negative discount line',       'pu' => -50.0,   'remise' => 0.0,  'net' => -50.0,    'ref' => ''),
		array('label' => 'service line (portes)',        'pu' => 35.0,    'remise' => 0.0,  'net' => 35.0,     'ref' => 'PORTES'),
		array('label' => 'service line (montaje)',       'pu' => 200.0,   'remise' => 0.0,  'net' => 200.0,    'ref' => 'MONTAJE'),
	);

	$sumNet = 0.0;
	foreach ($expected as $i => $exp) {
		$l = $lines[$i];
		$n = 'line ' . ($i + 1) . ' — ' . $exp['label'];

		// pu_ht is stored with MAIN_MAX_DECIMALS_UNIT decimals; allow that much slack
		eo_assert($n . ': gross unit price', (float) $l->pu_ht, $exp['pu'], 0.00002);
		eo_assert($n . ': discount %', (float) $l->remise_percent, $exp['remise'], 0.01);
		eo_assert($n . ': line total matches the document', (float) $l->total_ht, $exp['net'], 0.02);
		eo_assert($n . ': supplier ref persisted', (string) $l->ref, $exp['ref']);
		eo_assert($n . ': VAT rate', (float) $l->tva_tx, 21.0, 0.01);

		$sumNet += (float) $l->total_ht;
	}

	eo_assert('the eight lines add up to the printed subtotal', round($sumNet, 2), 6054.50, 0.02);
	eo_info('sum of persisted line totals = ' . number_format($sumNet, 4, '.', ''));
}

// ── Invoice header ───────────────────────────────────────────────────────
eo_group('invoice header');

$sqlHead  = "SELECT ref, ref_supplier, total_ht, total_tva, total_ttc, fk_statut, import_key, datef";
$sqlHead .= " FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . $invoiceId;
$resHead = $db->query($sqlHead);
$head = $resHead ? $db->fetch_object($resHead) : null;
eo_assert('header row exists', is_object($head), true);
if (is_object($head)) {
	eo_assert('supplier ref stored', $head->ref_supplier, $params['ref_supplier']);
	eo_assert('header HT is the document total', (float) $head->total_ht, 6054.50, 0.02);
	eo_assert('header VAT is the document total', (float) $head->total_tva, 1271.45, 0.02);
	eo_assert('header TTC is the document total', (float) $head->total_ttc, 7325.95, 0.02);
	eo_assert('invoice date', substr($head->datef, 0, 10), '2026-07-14');
	eo_assert('import key tags the origin', $head->import_key, 'easyocr-itest');
	eo_info('status=' . $head->fk_statut . ' (0 = draft, 1 = validated)');
}

// ── Receiver-as-supplier guard, against the real $mysoc ──────────────────
eo_group('receiver-as-supplier guard');

$ownTaxId = '';
foreach (array('idprof1', 'tva_intra', 'idprof2') as $prop) {
	if (!empty($mysoc->$prop)) {
		$ownTaxId = $mysoc->$prop;
		break;
	}
}

if ($ownTaxId === '') {
	eo_info('skipped: this Dolibarr has no tax id configured for $mysoc');
} else {
	eo_info('own tax id used for the test: ' . $ownTaxId);
	$guardParams = $params;
	unset($guardParams['fk_soc']);           // guard only applies when no supplier was picked
	$guardParams['supplier_name']   = 'ME, MYSELF AND I';
	$guardParams['supplier_tax_id'] = $ownTaxId;
	$guardParams['ref_supplier']    = 'EASYOCR-GUARD-' . substr(md5(microtime(true)), 0, 6);

	$before = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "societe");
	$countBefore = (int) $db->fetch_object($before)->n;

	$guardResult = easyocrCreateInvoiceFromOCR($guardParams, $user);
	eo_assert('creation is refused', isset($guardResult['status']) ? $guardResult['status'] : '', 'error');
	eo_assert('with the expected error code', isset($guardResult['error_code']) ? $guardResult['error_code'] : '', 'supplier_is_receiver');

	$after = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "societe");
	eo_assert('no third party was created', (int) $db->fetch_object($after)->n, $countBefore);

	// Same id on both sides
	$dupParams = $params;
	unset($dupParams['fk_soc']);
	$dupParams['supplier_name']   = 'ACME';
	$dupParams['supplier_tax_id'] = 'B77777777';
	$dupParams['customer_tax_id'] = 'B77777777';
	$dupParams['ref_supplier']    = 'EASYOCR-DUP-' . substr(md5(microtime(true)), 0, 6);
	$dupResult = easyocrCreateInvoiceFromOCR($dupParams, $user);
	eo_assert('supplier == customer is refused', isset($dupResult['error_code']) ? $dupResult['error_code'] : '', 'supplier_equals_customer');
}

// ── Duplicate fingerprint, against the real table ────────────────────────
eo_group('duplicate fingerprint (real table)');

$sqlTable = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "easyocr_processed_files'";
$resTable = $db->query($sqlTable);
$tableExists = $resTable && $db->num_rows($resTable) > 0;
eo_assert('llx_easyocr_processed_files exists (module reactivated)', $tableExists, true);

if ($tableExists) {
	$hash = easyocrComputeFileHash('integration-test-' . microtime(true));

	eo_assert('unknown fingerprint is not found', easyocrLookupProcessedFile($hash), null);

	$rowId = easyocrRegisterProcessedFile($hash, 'test-01.pdf', 123456, $user->id);
	eo_assert('fingerprint registered', $rowId > 0, true);

	$found = easyocrLookupProcessedFile($hash);
	eo_assert('fingerprint is found back', is_array($found), true);
	if (is_array($found)) {
		eo_assert('filename round-trips', $found['filename'], 'test-01.pdf');
		eo_assert('size round-trips', $found['file_size'], 123456);
	}

	eo_assert('registering twice does not duplicate', easyocrRegisterProcessedFile($hash, 'test-01.pdf', 123456, $user->id), $rowId);

	// Link to the invoice we created
	eo_assert('links to the created invoice', easyocrLinkProcessedFileToInvoice($hash, $invoiceId), true);
	$linked = easyocrLookupProcessedFile($hash);
	eo_assert('the link is readable back', $linked['invoice_id'], $invoiceId);
	eo_assert('and brings the invoice ref along', !empty($linked['invoice_ref']), true);

	// Time window: push the row into the past and check both sides of the bound
	$past = $db->idate(dol_now() - (10 * 86400));
	$db->query("UPDATE " . MAIN_DB_PREFIX . "easyocr_processed_files SET date_creation = '" . $past . "' WHERE rowid = " . (int) $rowId);

	$conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS = 30;
	eo_assert('a 10-day-old document is inside a 30-day window', is_array(easyocrLookupProcessedFile($hash)), true);

	$conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS = 5;
	eo_assert('and outside a 5-day window', easyocrLookupProcessedFile($hash), null);

	eo_assert('but registering still finds it (no unique-index collision)', easyocrRegisterProcessedFile($hash, 're-sent.pdf', 999, $user->id), $rowId);
	eo_assert('re-processing refreshes the date, so it is current again', is_array(easyocrLookupProcessedFile($hash)), true);

	unset($conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS);
}

// ── Roll back, always ────────────────────────────────────────────────────
$db->rollback();

eo_group('cleanup');
$resCheck = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . $invoiceId);
eo_assert('the test invoice is gone after rollback', (int) $db->fetch_object($resCheck)->n, 0);
$resCheck2 = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $supplierId);
eo_assert('the test supplier is gone after rollback', (int) $db->fetch_object($resCheck2)->n, 0);

// ── Summary ──────────────────────────────────────────────────────────────
$total = $GLOBALS['eo_pass'] + $GLOBALS['eo_fail'];
echo "\n" . str_repeat('─', 52) . "\n";
if ($GLOBALS['eo_fail'] === 0) {
	echo "\033[32mAll " . $total . " integration assertions passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $GLOBALS['eo_fail'] . " of " . $total . " integration assertions failed.\033[0m\n";
exit(1);
