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
$supplier->get_codefournisseur();   // some setups require a supplier code
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

// ── Foreign-customer guard: the invoice is addressed to someone else ─────
eo_group('foreign-customer guard');

if ($ownTaxId === '') {
	eo_info('skipped: this Dolibarr has no tax id configured for $mysoc');
} else {
	$countInvoices = function () use ($db) {
		return (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "facture_fourn"))->n;
	};
	$code = function ($r) {
		return isset($r['error_code']) ? $r['error_code'] : '';
	};

	// Applies even with fk_soc picked by the user
	$fcParams = $params;
	$fcParams['customer_tax_id'] = 'B55555555';
	$fcParams['ref_supplier']    = 'EASYOCR-FC-' . substr(md5(microtime(true)), 0, 6);
	$invBefore = $countInvoices();
	$fcResult = easyocrCreateInvoiceFromOCR($fcParams, $user);
	eo_assert('foreign customer is refused', isset($fcResult['status']) ? $fcResult['status'] : '', 'error');
	eo_assert('with error code customer_not_own_company', $code($fcResult), 'customer_not_own_company');
	eo_assert('the message names the foreign tax id', isset($fcResult['message']) && strpos($fcResult['message'], 'B55555555') !== false, true);
	eo_assert('no supplier invoice was created', $countInvoices(), $invBefore);

	// Our own tax id, with country prefix and separators → goes through
	$bare = easyocrNormalizeTaxId($ownTaxId);
	if (preg_match('/^[A-Z]{2}([A-Z0-9]{5,})$/', $bare, $mm) && !preg_match('/^[0-9]/', $bare)) {
		$bare = $mm[1];
	}
	$ownVariant = 'ES ' . substr($bare, 0, 1) . '-' . substr($bare, 1, 4) . '.' . substr($bare, 5);
	eo_info('own tax id variant: ' . $ownVariant);
	$okParams = $params;
	$okParams['customer_tax_id'] = $ownVariant;
	$okParams['ref_supplier']    = 'EASYOCR-OWN-' . substr(md5(microtime(true)), 0, 6);
	$okResult = easyocrCreateInvoiceFromOCR($okParams, $user);
	eo_assert('own tax id (ES prefix + separators) is accepted', isset($okResult['status']) ? $okResult['status'] : $code($okResult), 'ok');

	// Customer tax id not read → goes through as before
	$emptyParams = $params;
	$emptyParams['customer_tax_id'] = '';
	$emptyParams['ref_supplier']    = 'EASYOCR-EMPTY-' . substr(md5(microtime(true)), 0, 6);
	$emptyResult = easyocrCreateInvoiceFromOCR($emptyParams, $user);
	eo_assert('empty customer tax id is accepted', isset($emptyResult['status']) ? $emptyResult['status'] : $code($emptyResult), 'ok');

	// Bypass constant on → goes through
	$prevBypass = isset($conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER) ? $conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER : null;
	$conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER = 1;
	$bpParams = $fcParams;
	$bpParams['ref_supplier'] = 'EASYOCR-BYPASS-' . substr(md5(microtime(true)), 0, 6);
	$bpResult = easyocrCreateInvoiceFromOCR($bpParams, $user);
	eo_assert('foreign customer with EASYOCR_ALLOW_FOREIGN_CUSTOMER=1 is accepted', isset($bpResult['status']) ? $bpResult['status'] : $code($bpResult), 'ok');
	if ($prevBypass === null) {
		unset($conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER);
	} else {
		$conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER = $prevBypass;
	}
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

// ── Supplier proposal: same extraction, different document ───────────────
eo_group('supplier proposal from OCR');

$resPT = $db->query("SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "supplier_proposal'");
$hasProposalTable = $resPT && $db->num_rows($resPT) > 0;
eo_assert('llx_supplier_proposal exists', $hasProposalTable, true);

if ($hasProposalTable) {
	if (empty($conf->supplier_proposal->enabled)) {
		// The module is off in this instance: it is switched on for the test only, so the
		// pipeline is still exercised. Nothing is written outside the transaction.
		$conf->supplier_proposal->enabled = 1;
		eo_info('supplier_proposal was disabled in this instance; enabled for the test only');
	}
	// The user of the test may not carry the rights of that module: hasRight() returns 0
	// when the module branch is missing, so it is created here (transaction-only change).
	if (empty($user->rights)) {
		$user->rights = new stdClass();
	}
	if (empty($user->rights->supplier_proposal)) {
		$user->rights->supplier_proposal = new stdClass();
	}
	$user->rights->supplier_proposal->creer = 1;

	$proposalRef = 'EASYOCR-PROP-' . substr(md5(microtime(true)), 0, 6);
	$proposalParams = $params;
	$proposalParams['ref_supplier']  = $proposalRef;
	$proposalParams['document_type'] = 'supplier_proposal';
	$proposalParams['valid_until']   = '2026-08-31';

	$propResult = easyocrCreateInvoiceFromOCR($proposalParams, $user);
	eo_assert('the proposal is created', isset($propResult['status']) ? $propResult['status'] : 'missing', 'ok');
	if (!isset($propResult['status']) || $propResult['status'] !== 'ok') {
		eo_info('message: ' . (isset($propResult['message']) ? $propResult['message'] : '(none)'));
	}
	eo_assert('and it is reported as a proposal', isset($propResult['document_type']) ? $propResult['document_type'] : '', 'supplier_proposal');

	$proposalId = isset($propResult['id']) ? (int) $propResult['id'] : 0;

	if ($proposalId > 0) {
		$resP = $db->query("SELECT fk_soc, ref_ext, ref, fk_statut, note_private FROM " . MAIN_DB_PREFIX . "supplier_proposal WHERE rowid = " . $proposalId);
		$propRow = $resP ? $db->fetch_object($resP) : null;
		eo_assert('the row is readable', is_object($propRow), true);
		if (is_object($propRow)) {
			eo_assert('it hangs from the supplier of the document', (int) $propRow->fk_soc, (int) $supplierId);
			eo_assert('the supplier number is kept in ref_ext', $propRow->ref_ext, $proposalRef);
			eo_assert('it stays in draft', (int) $propRow->fk_statut, 0);
			eo_assert('the note keeps the supplier number', strpos((string) $propRow->note_private, $proposalRef) !== false, true);
			eo_assert('the note keeps the validity date', strpos((string) $propRow->note_private, '2026-08-31') !== false, true);
		}

		$resPL = $db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "supplier_proposaldet WHERE fk_supplier_proposal = " . $proposalId);
		eo_assert('all eight lines were persisted', $resPL ? (int) $db->fetch_object($resPL)->n : 0, 8);

		$resPF = $db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier = '" . $db->escape($proposalRef) . "'");
		eo_assert('no supplier invoice was created instead', $resPF ? (int) $db->fetch_object($resPF)->n : -1, 0);

		$resPP = $db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "paiementfourn WHERE num_paiement = '" . $db->escape($proposalRef) . "'");
		eo_assert('a proposal is never paid', $resPP ? (int) $db->fetch_object($resPP)->n : -1, 0);

		// The same document from the same supplier is not registered twice
		$again = easyocrCreateInvoiceFromOCR($proposalParams, $user);
		eo_assert('the same document is refused as a duplicate', isset($again['status']) ? $again['status'] : '', 'repeat');
		eo_assert('and it points at the existing proposal', isset($again['existing_id']) ? (int) $again['existing_id'] : 0, $proposalId);
	}
}

// ── Unattended supplier resolution (webhook path, 2.8.2) ─────────────────
// The webhook used to compare the tax id letter by letter: "ESB…" never found the
// supplier stored as "B…", a misread id created a new one, and a document whose
// totals did not add up was validated anyway. Synthetic, valid CIFs only.
eo_group('unattended supplier resolution (webhook)');

/** A valid Spanish CIF for the given entity letter and 7 digits. */
function eo_cif($letter, $digits)
{
	$sum = 0;
	for ($i = 0; $i < 7; $i++) {
		$d = (int) $digits[$i];
		if ($i % 2 === 0) {
			$d *= 2;
			$d = intdiv($d, 10) + ($d % 10);
		}
		$sum += $d;
	}
	return $letter . $digits . ((10 - ($sum % 10)) % 10);
}

$eoSeed = (string) mt_rand(1000, 8999);
$cifAlfa  = eo_cif('B', $eoSeed . '101');
$cifBeta  = eo_cif('B', $eoSeed . '202');
$cifGamma = eo_cif('B', $eoSeed . '303');
$cifDelta = eo_cif('B', $eoSeed . '404');
$cifOther = eo_cif('B', $eoSeed . '505');
$cifCli   = eo_cif('A', $eoSeed . '606');
eo_info('CIFs: ' . implode(' ', array($cifAlfa, $cifBeta, $cifGamma, $cifDelta, $cifOther, $cifCli)));
eo_assert('the test CIFs are valid', easyocrIsValidSpanishTaxId($cifAlfa) && easyocrIsValidSpanishTaxId($cifCli), true);

$mkSoc = function ($name, $idprof1, $tvaIntra, $isSupplier = 1) use ($db, $user, $mysoc) {
	$s = new Societe($db);
	$s->name = $name;
	$s->fournisseur = $isSupplier;
	$s->client = $isSupplier ? 0 : 1;
	$s->country_id = $mysoc->country_id;
	$s->idprof1 = $idprof1;
	$s->tva_intra = $tvaIntra;
	$s->status = 1;
	if ($isSupplier) {
		$s->get_codefournisseur();
	} else {
		$s->get_codeclient();
	}
	$id = $s->create($user);
	if ($id <= 0) {
		eo_info('fixture ' . $name . ' failed: ' . $s->error . ' ' . implode(' | ', (array) $s->errors));
	}
	return (int) $id;
};
$countSoc = function () use ($db) {
	return (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "societe"))->n;
};
$socRow = function ($id) use ($db) {
	return $db->fetch_object($db->query("SELECT rowid, nom, siren, tva_intra, fournisseur FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $id));
};
$invRow = function ($id) use ($db) {
	return $db->fetch_object($db->query("SELECT rowid, fk_soc, fk_statut, note_private FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . (int) $id));
};
// What the webhook sends: no fk_soc, consistent totals, no lines, review on
$whParams = function ($taxId, $name, $tag, $extra = array()) {
	return array_merge(array(
		'fk_soc'           => 0,
		'ref_supplier'     => 'EOQA-' . $tag . '-' . substr(md5(microtime(true) . $tag), 0, 6),
		'datef'            => '2026-10-20',
		'total_ht'         => '100.00',
		'total_tva'        => '21.00',
		'total_ttc'        => '121.00',
		'supplier_name'    => $name,
		'supplier_tax_id'  => $taxId,
		'supplier_country' => '',
		'invoice_status'   => '',
		'import_key'       => 'easyocr-itest',
		'draft_on_review'  => 1,
	), $extra);
};

// Without review the module would validate: anything that comes back as a draft
// was kept there by the review, not by the configuration.
$prevDraftConf = isset($conf->global->EASYOCR_INVOICE_DRAFT) ? $conf->global->EASYOCR_INVOICE_DRAFT : null;
$conf->global->EASYOCR_INVOICE_DRAFT = 0;

$idAlfa = $mkSoc('EOQA ALFA, S.L.', $cifAlfa, '');
$idBeta = $mkSoc('EOQA BETA SA', '', 'ES' . $cifBeta);
eo_assert('fixtures created', $idAlfa > 0 && $idBeta > 0, true);

// 1) ES prefix on the document, bare id in the ERP (the case that duplicated).
//    The trade name differs on purpose: only the tax id can find it.
$n0 = $countSoc();
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . $cifAlfa, 'EOQA NOMBRE COMERCIAL UNO', 'PFX', array('invoice_status' => 'draft')), $user);
eo_assert('ES-prefixed id: invoice created', isset($r['status']) ? $r['status'] : '', 'ok');
eo_assert('ES-prefixed id: lands on the existing supplier', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idAlfa);
eo_assert('ES-prefixed id: no third party created', $countSoc(), $n0);
eo_assert('ES-prefixed id: nothing to review', isset($r['review_codes']) ? $r['review_codes'] : null, array());

// 2) The other way round, with separators: bare id read, prefixed id stored
$r = easyocrCreateInvoiceFromOCR($whParams(substr($cifBeta, 0, 1) . '-' . substr($cifBeta, 1), 'EOQA NOMBRE COMERCIAL DOS', 'BARE', array('invoice_status' => 'draft')), $user);
eo_assert('bare id with dash: lands on the supplier stored with ES prefix', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idBeta);
eo_assert('bare id with dash: no third party created', $countSoc(), $n0);

// 3) Misread id (5 read as S): found by name, kept as a draft for review
$misread = substr($cifAlfa, 0, 1) . 'S' . substr($cifAlfa, 2);
// Mark-as-paid requested, as the webhook does when configured: a draft is never paid
$bankRow = $db->fetch_object($db->query("SELECT MIN(rowid) id FROM " . MAIN_DB_PREFIX . "bank_account"));
$bankId = $bankRow ? (int) $bankRow->id : 0;
$r = easyocrCreateInvoiceFromOCR($whParams($misread, 'EOQA ALFA SL', 'TYPO', array('create_payment' => '1', 'payment_bank_id' => $bankId, 'payment_type_id' => 2)), $user);
eo_assert('misread id: invoice created', isset($r['status']) ? $r['status'] : '', 'ok');
eo_assert('misread id: found by name, not duplicated', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idAlfa);
eo_assert('misread id: no third party created', $countSoc(), $n0);
eo_assert('misread id: reported as tax_id_invalid', isset($r['review_codes']) && in_array('tax_id_invalid', $r['review_codes'], true), true);
eo_assert('misread id: kept as draft by the review', !empty($r['forced_draft']) && !empty($r['is_draft']), true);
$row = $invRow($r['id']);
eo_assert('misread id: draft in the database', $row ? (int) $row->fk_statut : -1, 0);
eo_assert('misread id: the private note names the misread id', $row && strpos((string) $row->note_private, $misread) !== false, true);
if ($bankId > 0) {
	$resPay = $db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn WHERE fk_facturefourn = " . (int) $r['id']);
	eo_assert('misread id: the forced draft was not paid', $resPay ? (int) $db->fetch_object($resPay)->n : -1, 0);
} else {
	eo_info('skipped payment check: no bank account in this Dolibarr');
}

// 4) ES prefix with a digit missing (also seen): invalid as well
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . substr($cifAlfa, 0, 8), 'EOQA ALFA', 'SHORT'), $user);
eo_assert('short id: found by name', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idAlfa);
eo_assert('short id: no third party created', $countSoc(), $n0);

// 5) Two records with the same tax id: the one whose name was read, as a draft
$idGammaOld = $mkSoc('EOQA ESTACION DE SERVICIO', $cifGamma, '');
$idGamma    = $mkSoc('EOQA GAMMA', '', 'ES' . $cifGamma);
if ($idGammaOld > 0 && $idGamma > 0) {
	$n1 = $countSoc();
	// The name read singles out one of them: no ambiguity left to review
	$r = easyocrCreateInvoiceFromOCR($whParams($cifGamma, 'EOQA Gamma', 'AMB', array('invoice_status' => 'draft')), $user);
	eo_assert('shared tax id: takes the record whose name was read', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idGamma);
	eo_assert('shared tax id, name decides: not reported', isset($r['review_codes']) ? $r['review_codes'] : null, array());
	// The name read matches neither: the oldest, reported and kept as a draft
	$r = easyocrCreateInvoiceFromOCR($whParams($cifGamma, 'EOQA NINGUNO DE LOS DOS', 'AMB2'), $user);
	eo_assert('shared tax id, name does not decide: the oldest', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idGammaOld);
	eo_assert('shared tax id, name does not decide: reported as ambiguous', isset($r['review_codes']) && in_array('supplier_ambiguous_tax_id', $r['review_codes'], true), true);
	eo_assert('shared tax id, name does not decide: kept as draft', !empty($r['forced_draft']), true);
	eo_assert('shared tax id: no third party created', $countSoc(), $n1);
} else {
	eo_info('skipped: this Dolibarr refuses two third parties with the same tax id');
}

// 6) Same trade name, different valid tax id: another company, created
$idDelta = $mkSoc('EOQA DELTA SL', $cifDelta, '');
$n2 = $countSoc();
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . $cifOther, 'EOQA DELTA', 'OTHER', array('invoice_status' => 'draft')), $user);
eo_assert('same name, other tax id: not assigned to the namesake', isset($r['supplier_id']) && (int) $r['supplier_id'] !== $idDelta, true);
eo_assert('same name, other tax id: a supplier was created', !empty($r['supplier_created']) && $countSoc() === $n2 + 1, true);
$new = $socRow($r['supplier_id']);
eo_assert('new supplier: CIF stored bare in idprof1', $new ? $new->siren : '', $cifOther);
eo_assert('new supplier: VAT number stored with ES prefix', $new ? $new->tva_intra : '', 'ES' . $cifOther);
$r2 = easyocrCreateInvoiceFromOCR($whParams($cifOther, 'EOQA DELTA', 'AGAIN', array('invoice_status' => 'draft')), $user);
eo_assert('next invoice of the new supplier (bare id) finds it', isset($r2['supplier_id']) ? (int) $r2['supplier_id'] : 0, (int) $r['supplier_id']);

// 7) Misread id and unknown name: created, but kept as a draft for review
$n3 = $countSoc();
// A CIF whose control digit became a letter that does not encode it
$badId9 = substr(eo_cif('B', $eoSeed . '808'), 0, 8) . 'X';
$r = easyocrCreateInvoiceFromOCR($whParams($badId9, 'EOQA EPSILON NUEVO', 'NEW'), $user);
eo_assert('invalid id, unknown name: supplier created', !empty($r['supplier_created']) && $countSoc() === $n3 + 1, true);
eo_assert('invalid id, unknown name: reported', isset($r['review_codes']) && in_array('tax_id_invalid_created', $r['review_codes'], true), true);
eo_assert('invalid id, unknown name: kept as draft', !empty($r['forced_draft']), true);
$new = $socRow($r['supplier_id']);
eo_assert('invalid id: kept as read, never as VAT number', $new ? array($new->siren, (string) $new->tva_intra) : null, array($badId9, ''));

// 8) A customer with the tax id becomes a supplier instead of being duplicated
$idCli = $mkSoc('EOQA CLIENTE ZETA', $cifCli, '', 0);
$n4 = $countSoc();
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . $cifCli, 'EOQA ZETA', 'CLI', array('invoice_status' => 'draft')), $user);
eo_assert('customer with the tax id: used', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idCli);
eo_assert('customer with the tax id: flagged as supplier', (int) $socRow($idCli)->fournisseur, 1);
eo_assert('customer with the tax id: nothing created', $countSoc(), $n4);

// 9) Totals that do not add up (base 338.03 + VAT 37.18 != 371.83)
$r = easyocrCreateInvoiceFromOCR($whParams('', '', 'HDR', array(
	'fk_soc' => $supplierId, 'total_ht' => '338.03', 'total_tva' => '37.18', 'total_ttc' => '371.83',
)), $user);
eo_assert('header mismatch: invoice created', isset($r['status']) ? $r['status'] : '', 'ok');
eo_assert('header mismatch: reported', isset($r['review_codes']) && in_array('header_mismatch', $r['review_codes'], true), true);
eo_assert('header mismatch: kept as draft', !empty($r['forced_draft']) && (int) $invRow($r['id'])->fk_statut === 0, true);

// 10) Lines that do not add up to the base
$r = easyocrCreateInvoiceFromOCR($whParams('', '', 'LIN', array(
	'fk_soc' => $supplierId, 'total_ht' => '338.03', 'total_tva' => '70.98', 'total_ttc' => '409.01',
	'items'  => array(array('description' => 'Linea', 'quantity' => 1, 'unit_price' => 4633, 'net_amount' => 4633, 'taxes' => eo_tax(21))),
)), $user);
eo_assert('line mismatch: reported', isset($r['review_codes']) && in_array('lines_mismatch', $r['review_codes'], true), true);
eo_assert('line mismatch: kept as draft', !empty($r['forced_draft']), true);

// 11) The screen does not ask for it: same mismatch, nothing forced
$r = easyocrCreateInvoiceFromOCR($whParams('', '', 'UI', array(
	'fk_soc' => $supplierId, 'total_ht' => '338.03', 'total_tva' => '37.18', 'total_ttc' => '371.83',
	'draft_on_review' => 0, 'invoice_status' => 'draft',
)), $user);
eo_assert('without draft_on_review: nothing is forced', isset($r['forced_draft']) ? $r['forced_draft'] : null, false);
eo_assert('without draft_on_review: the note still reports it', isset($r['review_codes']) && in_array('header_mismatch', $r['review_codes'], true), true);

// 12) Consistent document, nothing to review: follows the configuration (validated)
//     Checked without validating: the flag says no review, so nothing was forced.
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . $cifAlfa, 'EOQA ALFA', 'CLEAN', array('invoice_status' => 'draft')), $user);
eo_assert('consistent document: nothing forced', isset($r['forced_draft']) ? $r['forced_draft'] : null, false);
eo_assert('consistent document: nothing to review', isset($r['review_codes']) ? $r['review_codes'] : null, array());

// 13) Duplicate left by an older version with a misread id: the right id must not
//     take it for "another company" and create a third record
$idOmega = $mkSoc('EOQA OMEGA SL', substr($cifDelta, 0, 1) . 'S' . substr($cifDelta, 2), '');
$cifOmega = eo_cif('B', $eoSeed . '717');
$n5 = $countSoc();
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . $cifOmega, 'EOQA OMEGA, S.L.', 'OLDDUP'), $user);
eo_assert('old misread duplicate: reused, not a third record', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idOmega);
eo_assert('old misread duplicate: nothing created', $countSoc(), $n5);
eo_assert('old misread duplicate: reported as found by name', isset($r['review_codes']) && in_array('supplier_by_name', $r['review_codes'], true), true);
eo_assert('old misread duplicate: kept as draft', !empty($r['forced_draft']), true);

// 14) Namesake without tax id and a valid id read: assigned, but never silently
$idSigma = $mkSoc('EOQA SIGMA SA', '', '');
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . eo_cif('B', $eoSeed . '828'), 'EOQA SIGMA SL', 'NAMESAKE'), $user);
eo_assert('namesake without id: assigned by name', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idSigma);
eo_assert('namesake without id: reported', isset($r['review_codes']) && in_array('supplier_by_name', $r['review_codes'], true), true);
eo_assert('namesake without id: kept as draft', !empty($r['forced_draft']), true);

// 15) No tax id read at all: found only by name, reported
$r = easyocrCreateInvoiceFromOCR($whParams('', 'EOQA SIGMA', 'NOID'), $user);
eo_assert('no tax id: found by name', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idSigma);
eo_assert('no tax id: reported as name only', isset($r['review_codes']) && in_array('supplier_by_name_only', $r['review_codes'], true), true);
eo_assert('no tax id: kept as draft', !empty($r['forced_draft']), true);

// 16) DNI stored with its leading zero, printed without it
$dniNum = '0' . $eoSeed . '123';
$dni = $dniNum . substr('TRWAGMYFPDXBNJZSQVHLCKE', ((int) $dniNum) % 23, 1);
$idDni = $mkSoc('EOQA AUTONOMO PRUEBA', $dni, '');
$n6 = $countSoc();
$r = easyocrCreateInvoiceFromOCR($whParams(ltrim($dni, '0'), 'EOQA OTRO NOMBRE', 'DNI', array('invoice_status' => 'draft')), $user);
eo_assert('DNI without leading zero: found by tax id', isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0, $idDni);
eo_assert('DNI without leading zero: nothing created', $countSoc(), $n6);

// 18) A customer with the tax id whose document turns out to be a duplicate stays a
//     customer (2.8.3): the upgrade happens only when the document is created
$cifEta = eo_cif('A', $eoSeed . '939');
$idEta = $mkSoc('EOQA CLIENTE ETA', $cifEta, '', 0);
$dupRef = 'EOQA-DUPCLI-' . substr(md5(microtime(true)), 0, 6);
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
$prev = new FactureFournisseur($db);
$prev->socid = $idEta;
$prev->ref_supplier = $dupRef;
$prev->date = dol_now();
$prevId = $prev->create($user);
eo_assert('fixture: earlier invoice with the same number', $prevId > 0, true);
$r = easyocrCreateInvoiceFromOCR($whParams('ES' . $cifEta, 'EOQA CLIENTE ETA', 'DUPCLI', array('ref_supplier' => $dupRef, 'invoice_status' => 'draft')), $user);
eo_assert('duplicate of a customer: reported as repeat', isset($r['status']) ? $r['status'] : '', 'repeat');
eo_assert('duplicate of a customer: still not a supplier', (int) $socRow($idEta)->fournisseur, 0);

// 17) Automatic payment the bank refuses (2.8.3): a cash account only takes cash. The
//     payment used to stay with no bank line and the invoice "paid"; now it is undone.
$cashRow = $db->fetch_object($db->query("SELECT MIN(rowid) id FROM " . MAIN_DB_PREFIX . "bank_account WHERE courant = 2 AND clos = 0 AND entity IN (" . getEntity('bank_account') . ")"));
$cashId = $cashRow ? (int) $cashRow->id : 0;
$payType = function ($code) use ($db) {
	$o = $db->fetch_object($db->query("SELECT MIN(id) id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = '" . $db->escape($code) . "' AND active = 1 AND entity IN (" . getEntity('c_paiement') . ")"));
	return $o ? (int) $o->id : 0;
};
$payRows = function ($invId) use ($db) {
	return (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn WHERE fk_facturefourn = " . (int) $invId))->n;
};
if ($cashId > 0 && $payType('VIR') > 0 && $payType('LIQ') > 0) {
	$r = easyocrCreateInvoiceFromOCR($whParams('', '', 'PAYBAD', array(
		'fk_soc' => $supplierId, 'invoice_status' => 'validated', 'draft_on_review' => 0,
		'create_payment' => '1', 'payment_bank_id' => $cashId, 'payment_type_id' => $payType('VIR'),
	)), $user);
	$row = $db->fetch_object($db->query("SELECT fk_statut, paye, note_private FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . (int) $r['id']));
	eo_assert('bank refuses: the invoice is created and validated', $row ? (int) $row->fk_statut : -1, 1);
	eo_assert('bank refuses: the error is reported', !empty($r['payment_error']), true);
	eo_assert('bank refuses: the payment is undone', $payRows($r['id']), 0);
	eo_assert('bank refuses: the invoice stays pending payment', $row ? (int) $row->paye : -1, 0);
	eo_assert('bank refuses: the private note explains it', $row && $r['payment_error'] !== '' && strpos((string) $row->note_private, $r['payment_error']) !== false, true);

	// Positive control: the same cash account with a cash payment is paid, with its line
	$r = easyocrCreateInvoiceFromOCR($whParams('', '', 'PAYOK', array(
		'fk_soc' => $supplierId, 'invoice_status' => 'validated', 'draft_on_review' => 0,
		'create_payment' => '1', 'payment_bank_id' => $cashId, 'payment_type_id' => $payType('LIQ'),
	)), $user);
	$pay = $db->fetch_object($db->query("SELECT p.fk_bank FROM " . MAIN_DB_PREFIX . "paiementfourn p JOIN " . MAIN_DB_PREFIX . "paiementfourn_facturefourn pf ON pf.fk_paiementfourn = p.rowid WHERE pf.fk_facturefourn = " . (int) $r['id']));
	eo_assert('cash with cash: paid', $payRows($r['id']), 1);
	eo_assert('cash with cash: with its bank line', $pay && (int) $pay->fk_bank > 0, true);
	eo_assert('cash with cash: no error', isset($r['payment_error']) ? $r['payment_error'] : null, '');
} else {
	eo_info('skipped payment checks: no open cash account or no VIR/LIQ payment types');
}

if ($prevDraftConf === null) {
	unset($conf->global->EASYOCR_INVOICE_DRAFT);
} else {
	$conf->global->EASYOCR_INVOICE_DRAFT = $prevDraftConf;
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
