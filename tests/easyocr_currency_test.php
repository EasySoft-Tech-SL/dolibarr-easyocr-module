<?php
/**
 * EasyOcr 2.10.0 — documents in another currency and template detection by text.
 *
 * Boots Dolibarr against the real database. The pure parts (currency code, fixed rates,
 * ECB answer, rate resolution, supplier detection in a text) run first; then invoices
 * are created from OCR data inside a transaction that is ALWAYS rolled back.
 *
 * No network: the ECB rates are seeded in $GLOBALS['easyocr_fx_cache'], which is where
 * easyocrFxEcbRate() keeps what it fetched. With --live it also asks the real ECB API
 * for one known rate.
 *
 * Run:  php tests/easyocr_currency_test.php [--live]
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}
$live = in_array('--live', $argv, true);

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
require_once __DIR__ . '/../lib/easyocr.lib.php';
require_once __DIR__ . '/../lib/easyocr_errors.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';

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

if (is_object($langs)) {
	$langs->load('easyocr@easyocr');
}
// Settings this suite changes, put back at the end
$eoSaved = array();
foreach (array('EASYOCR_FX_ENABLED', 'EASYOCR_FX_FIXED_RATES', 'EASYOCR_INVOICE_DRAFT', 'EASYOCR_DUPLICATE_CHECK') as $k) {
	$eoSaved[$k] = isset($conf->global->$k) ? $conf->global->$k : null;
}
$eoSavedCurrency = $conf->currency;
$eoSavedModules = isset($conf->modules) ? $conf->modules : null;
$eoSavedMc = isset($conf->multicurrency) ? $conf->multicurrency : null;
unset($conf->global->EASYOCR_FX_ENABLED);
$conf->global->EASYOCR_FX_FIXED_RATES = '';

// ═════════════════════════════════════════════════════════════════════════
eo_group('currency code read from the document');
eo_assert('ISO code', easyocrNormalizeCurrencyCode('USD'), 'USD');
eo_assert('lower case', easyocrNormalizeCurrencyCode(' usd '), 'USD');
eo_assert('euro sign', easyocrNormalizeCurrencyCode('€'), 'EUR');
eo_assert('code followed by its symbol', easyocrNormalizeCurrencyCode('EUR (€)'), 'EUR');
eo_assert('US$', easyocrNormalizeCurrencyCode('US$'), 'USD');
eo_assert('"$" alone is not guessed (peso, CAD, AUD...)', easyocrNormalizeCurrencyCode('$'), '');
eo_assert('a word is not a code', easyocrNormalizeCurrencyCode('Dollars'), '');
eo_assert('empty', easyocrNormalizeCurrencyCode(''), '');

eo_group('fixed rates of the setup');
$fixed = easyocrFxParseFixedRates("USD=0,87\nGBP = 1.17; jpy:0,0058\nxx=1\nCHF=-1\nUSD=0,870\nnot a rate");
eo_assert('comma decimal', isset($fixed['USD']) ? $fixed['USD'] : null, 0.87);
eo_assert('"0,870" is 0.87, not 870', isset($fixed['USD']) ? $fixed['USD'] : null, 0.87);
eo_assert('dot decimal and spaces', isset($fixed['GBP']) ? $fixed['GBP'] : null, 1.17);
eo_assert('";" and ":" separators, lower case code', isset($fixed['JPY']) ? $fixed['JPY'] : null, 0.0058);
eo_assert('invalid lines dropped', array_keys($fixed), array('USD', 'GBP', 'JPY'));

eo_group('ECB answer');
$csv = "KEY,FREQ,CURRENCY,CURRENCY_DENOM,EXR_TYPE,EXR_SUFFIX,TIME_PERIOD,OBS_VALUE,OBS_STATUS,TITLE_COMPL\n"
	. "EXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-11-18,1.0968,A,\"ECB reference exchange rate, US dollar/Euro, 2.15 pm (C.E.T.)\"\n"
	. "EXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-11-19,1.0992,A,\"ECB reference exchange rate, US dollar/Euro, 2.15 pm (C.E.T.)\"\n"
	. "EXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-11-20,1.1001,A,\"ECB reference exchange rate, US dollar/Euro, 2.15 pm (C.E.T.)\"\n";
eo_assert('rate of the day', easyocrFxParseEcbCsv($csv, '2026-11-19'), array('rate' => 1.0992, 'date' => '2026-11-19'));
eo_assert('Sunday takes Friday', easyocrFxParseEcbCsv($csv, '2026-11-22'), array('rate' => 1.1001, 'date' => '2026-11-20'));
eo_assert('never a later day', easyocrFxParseEcbCsv($csv, '2026-11-18'), array('rate' => 1.0968, 'date' => '2026-11-18'));
eo_assert('before the data: none', easyocrFxParseEcbCsv($csv, '2026-11-01'), null);
eo_assert('not a CSV of the ECB', easyocrFxParseEcbCsv('<html>error</html>', '2026-11-19'), null);

eo_group('rate to book a document');
$GLOBALS['easyocr_fx_cache'] = array(
	'USD|2026-11-19' => array('rate' => 1.0992, 'date' => '2026-11-19'),
	'USD|2026-11-15' => array('rate' => 1.1003, 'date' => '2026-11-13'),
	'GBP|2026-11-19' => array('rate' => 0.835, 'date' => '2026-11-19'),
	'XYZ|2026-11-19' => array('error' => 'unknown'),
	'ABC|2026-11-19' => array('error' => 'network'),
);
$conf->currency = 'EUR';
$r = easyocrFxResolve('USD', '2026-11-19');
eo_assert('USD: converted', $r['convert'], true);
eo_assert('USD: tx is the ECB quote (USD per EUR)', $r['tx'], 1.0992);
eo_assert('USD: factor = 1 / tx', $r['factor'], 1 / 1.0992, 1e-12);
eo_assert('USD: source ECB with its day', array($r['source'], $r['rate_date']), array('ecb', '2026-11-19'));
$r = easyocrFxResolve('USD', '2026-11-15');
eo_assert('Sunday invoice: rate of the Friday before', array($r['tx'], $r['rate_date']), array(1.1003, '2026-11-13'));
eo_assert('our own currency: nothing to convert', easyocrFxResolve('EUR', '2026-11-19')['convert'], false);
eo_assert('no currency read: nothing to convert', easyocrFxResolve('', '2026-11-19')['convert'], false);
$conf->global->EASYOCR_FX_FIXED_RATES = 'USD=0,87';
$r = easyocrFxResolve('USD', '2026-11-19');
eo_assert('fixed rate wins over the ECB', array($r['source'], $r['tx']), array('fixed', round(1 / 0.87, 8)));
eo_assert('fixed rate: no day', $r['rate_date'], '');
eo_assert('fixed rate only for its currency', easyocrFxResolve('GBP', '2026-11-19')['source'], 'ecb');
$conf->global->EASYOCR_FX_FIXED_RATES = '';
$conf->global->EASYOCR_FX_ENABLED = '0';
$r = easyocrFxResolve('USD', '2026-11-19');
eo_assert('switched off: not converted, and said so', array($r['convert'], !empty($r['disabled'])), array(false, true));
$conf->global->EASYOCR_FX_ENABLED = '1';
eo_assert('switched on explicitly', easyocrFxResolve('USD', '2026-11-19')['convert'], true);
unset($conf->global->EASYOCR_FX_ENABLED);
eo_assert('never set: on (fresh install)', easyocrFxEnabled(), true);
eo_assert('no ECB rate for it: unknown', easyocrFxResolve('XYZ', '2026-11-19')['error'], 'unknown');
eo_assert('ECB unreachable: network (retry)', easyocrFxResolve('ABC', '2026-11-19')['error'], 'network');
// Our currency is not the euro: cross rate through the euro
$conf->currency = 'GBP';
$r = easyocrFxResolve('USD', '2026-11-19');
eo_assert('cross rate: USD per GBP', $r['tx'], round(1.0992 / 0.835, 8));
$r = easyocrFxResolve('EUR', '2026-11-19');
eo_assert('cross rate: EUR document in a GBP company', array($r['tx'], $r['rate_date']), array(round(1 / 0.835, 8), '2026-11-19'));
$conf->currency = 'EUR';
// A future date (misread) asks for the last rate published, never for a day to come
$GLOBALS['easyocr_fx_cache']['USD|' . date('Y-m-d')] = array('rate' => 1.05, 'date' => date('Y-m-d'));
eo_assert('future date: today\'s rate', easyocrFxResolve('USD', '2099-01-01')['tx'], 1.05);

eo_group('note for the private note');
$r = easyocrFxResolve('USD', '2026-11-19');
$note = easyocrFxNote($r, array('total_ht' => 33.68, 'total_tva' => 0, 'total_ttc' => 33.68), $langs);
eo_info($note);
eo_assert('note: printed total', strpos($note, '33') !== false && strpos($note, '68') !== false, true);
eo_assert('note: rate 1 USD = 0.909753 EUR', (bool) preg_match('/1 USD = 0[.,]909753 EUR/', $note), true);
eo_assert('note: says where the rate comes from', strpos($note, '19') !== false && (stripos($note, 'BCE') !== false || stripos($note, 'ECB') !== false || stripos($note, 'EZB') !== false), true);

eo_group('supplier detection in the text of a PDF');
$github = "INVOICE GitHub, Inc. Support Contact 88 Colin P. Kelly Jr. St. San Francisco, CA 94107 BILL TO EasySoft Tech SL "
	. "Calle Ejemplo 1 Oviedo, Asturias 33004 Spain VAT ID: B16885766 Invoice # INV167539011 Currency USD "
	. "QUANTITY DESCRIPTION RATE AMOUNT 33.68 GitHub Actions Usage Oct 01, 2026 - Oct 31, 2026 \$1.00 \$33.68";
$sups = array(
	array('fk_soc' => 1, 'names' => array('GitHub, Inc.'), 'tax_ids' => array()),
	array('fk_soc' => 2, 'names' => array('Aseuropa, S.L.'), 'tax_ids' => array('B33788506')),
	array('fk_soc' => 3, 'names' => array('EasySoft Tech S.L.'), 'tax_ids' => array()),
	array('fk_soc' => 4, 'names' => array('HP'), 'tax_ids' => array()),
	array('fk_soc' => 5, 'names' => array('Acme Ltd'), 'tax_ids' => array('12345678')),
);
eo_assert('GitHub, no tax id: found by its name', easyocrDetectSupplierInText($github, $sups, 'EasySoft Tech SL'), array('via' => 'name', 'fk_socs' => array(1)));
eo_assert('our own company in BILL TO never matches', in_array(3, easyocrDetectSupplierInText($github, $sups, 'EASYSOFT TECH, S.L.')['fk_socs'], true), false);
eo_assert('without the own name it would (the guard is what stops it)', in_array(3, easyocrDetectSupplierInText($github, $sups, '')['fk_socs'], true), true);
eo_assert('tax id beats the name', easyocrDetectSupplierInText($github . ' Proveedor: ESB33788506', $sups, 'EasySoft Tech SL'), array('via' => 'tax_id', 'fk_socs' => array(2)));
eo_assert('tax id printed with separators', easyocrDetectSupplierInText('Aseuropa NIF: B-33.788.506', $sups, '')['fk_socs'], array(2));
eo_assert('a name under four letters is not looked for', easyocrDetectSupplierInText('Impresora HP LaserJet', $sups, '')['fk_socs'], array());
eo_assert('a name inside another word does not match', easyocrDetectSupplierInText('Acmeworld Corp', $sups, '')['fk_socs'], array());
eo_assert('a number with no letter is not a tax id to look for', easyocrDetectSupplierInText('Pedido 12345678', $sups, '')['fk_socs'], array());
eo_assert('two names: both returned, none chosen', easyocrDetectSupplierInText('GitHub Inc and Acme Ltd', $sups, '')['fk_socs'], array(1, 5));
eo_assert('nothing in the text', easyocrDetectSupplierInText('', $sups, ''), array('via' => '', 'fk_socs' => array()));

// ═════════════════════════════════════════════════════════════════════════
eo_group('environment');
$resAdmin = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
if (!$resAdmin || $db->num_rows($resAdmin) < 1) {
	fwrite(STDERR, "No active admin user found.\n");
	exit(1);
}
$user = new User($db);
$user->fetch($db->fetch_object($resAdmin)->rowid);
eo_assert('admin user loaded', $user->id > 0, true);
eo_assert('our currency is EUR', $conf->currency, 'EUR');

$db->begin();
register_shutdown_function(function () use ($db) {
	if (!empty($db->transaction_opened)) {
		$db->rollback();
		echo "\n\033[33mTransaction rolled back on shutdown.\033[0m\n";
	}
});

$seed = substr(md5(microtime(true)), 0, 6);
$mkSoc = function ($name, $idprof1) use ($db, $user, $mysoc) {
	$s = new Societe($db);
	$s->name = $name;
	$s->fournisseur = 1;
	$s->client = 0;
	$s->country_id = $mysoc->country_id;
	$s->idprof1 = $idprof1;
	$s->status = 1;
	$s->get_codefournisseur();
	$id = $s->create($user);
	if ($id <= 0) {
		eo_info('fixture ' . $name . ' failed: ' . $s->error);
	}
	return (int) $id;
};
$supplierId = $mkSoc('EOQA FX GITHUB ' . $seed . ', Inc.', 'EOQA FX GITHUB ' . $seed . ', Inc.');
eo_assert('fixture supplier', $supplierId > 0, true);

$conf->global->EASYOCR_DUPLICATE_CHECK = 1;
$fxParams = function ($tag, $extra = array()) use ($supplierId) {
	return array_merge(array(
		'fk_soc'         => $supplierId,
		'ref_supplier'   => 'EOQA-FX-' . $tag . '-' . substr(md5(microtime(true) . $tag), 0, 6),
		'datef'          => '2026-11-19',
		'currency'       => 'USD',
		'total_ht'       => '33.68',
		'total_tva'      => '0.00',
		'total_ttc'      => '33.68',
		'items'          => array(array('description' => 'GitHub Actions Usage', 'item_type' => 'service',
			'quantity' => 33.68, 'unit_price' => 1.00, 'net_amount' => 33.68,
			'taxes' => array(array('tax_type' => 'vat', 'tax_rate' => 0)))),
		'invoice_status' => 'draft',
		'import_key'     => 'easyocr-itest',
	), $extra);
};
$inv = function ($id) use ($db) {
	return $db->fetch_object($db->query("SELECT rowid, fk_statut, paye, total_ht, tva, total_ttc, multicurrency_code, multicurrency_tx, fk_multicurrency, multicurrency_total_ht, multicurrency_total_ttc, note_private FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . (int) $id));
};
$line = function ($id) use ($db) {
	return $db->fetch_object($db->query("SELECT pu_ht, total_ht, multicurrency_subprice, multicurrency_total_ht FROM " . MAIN_DB_PREFIX . "facture_fourn_det WHERE fk_facture_fourn = " . (int) $id . " ORDER BY rowid LIMIT 1"));
};
$countInv = function () use ($db) {
	return (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "facture_fourn"))->n;
};
$countSoc = function () use ($db) {
	return (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "societe"))->n;
};

// Multicurrency off, whatever this database has: the converted path
$conf->modules = is_array($conf->modules) ? $conf->modules : array();
unset($conf->modules['multicurrency']);
$conf->multicurrency = new stdClass();
$conf->multicurrency->enabled = 0;

eo_group('USD invoice, ECB rate, multicurrency off: booked in EUR');
$r = easyocrCreateInvoiceFromOCR($fxParams('ECB'), $user);
eo_assert('created', isset($r['status']) ? $r['status'] : ($r['message'] ?? ''), 'ok');
$row = $inv($r['id']);
$ln = $line($r['id']);
eo_assert('kept in EUR', $row ? $row->multicurrency_code : null, 'EUR');
eo_assert('total 33.68 USD / 1.0992 = 30.64 EUR', $row ? (float) $row->total_ttc : null, 30.64, 0.001);
eo_assert('base 30.64 EUR', $row ? (float) $row->total_ht : null, 30.64, 0.001);
eo_assert('line: unit price converted (1 USD = 0.909753 EUR)', $ln ? (float) $ln->pu_ht : null, 1 / 1.0992, 0.00001);
eo_assert('line total 30.64 EUR', $ln ? (float) $ln->total_ht : null, 30.64, 0.01);
eo_assert('note: printed amounts and rate', $row && strpos($row->note_private, 'USD') !== false && (bool) preg_match('/1 USD = 0[.,]909753 EUR/', $row->note_private), true);
eo_assert('note: says multicurrency is off (not "USD not declared")', $row && strpos($row->note_private, $langs->transnoentities('EasyOcrFxMulticurrencyOffNote', 'EUR')) !== false, true);
eo_assert('multicurrency off: no notice about the supplier record (no field to fill)', strpos((string) $row->note_private, $langs->transnoentities('EasyOcrFxSupplierNoCurrency', 'USD')), false);
eo_assert('result: conversion described', isset($r['fx']['source']) ? array($r['fx']['currency'], $r['fx']['source'], $r['fx']['native']) : null, array('USD', 'ecb', false));
eo_assert('user notices carry the conversion', count(easyocrAjaxUserNotices($r)) >= 1 && strpos(implode(' ', easyocrAjaxUserNotices($r)), 'USD') !== false, true);
$ecbId = (int) $r['id'];

eo_group('the duplicate check compares converted amounts');
// The amount + date check is the one for documents with no supplier number
$noRef = array('ref_supplier' => '', 'datef' => '2026-11-12', 'import_key' => 'easyocr-wh');
$r = easyocrCreateInvoiceFromOCR($fxParams('NOREF', $noRef), $user);
eo_assert('first one without supplier number: created', isset($r['status']) ? $r['status'] : ($r['message'] ?? ''), 'ok');
$firstNoRef = isset($r['id']) ? (int) $r['id'] : 0;
$n0 = $countInv();
$r = easyocrCreateInvoiceFromOCR($fxParams('NOREF2', $noRef), $user);
eo_assert('same supplier, date and amount in USD: repeat', isset($r['status']) ? $r['status'] : '', 'repeat');
eo_assert('repeat: points at the first one', isset($r['existing_id']) ? (int) $r['existing_id'] : 0, $firstNoRef);
eo_assert('repeat: nothing created', $countInv(), $n0);

eo_group('USD invoice, fixed rate, multicurrency on and USD declared: kept in USD');
$conf->global->EASYOCR_FX_FIXED_RATES = 'USD=0,87';
$conf->modules['multicurrency'] = 'multicurrency';
$conf->multicurrency->enabled = 1;
if (MultiCurrency::getIdFromCode($db, 'USD') <= 0) {
	$db->query("INSERT INTO " . MAIN_DB_PREFIX . "multicurrency (date_create, code, name, entity, fk_user) VALUES ('" . $db->idate(dol_now()) . "', 'USD', 'US Dollar', " . ((int) $conf->entity) . ", " . ((int) $user->id) . ")");
}
eo_assert('USD declared in multicurrency', MultiCurrency::getIdFromCode($db, 'USD') > 0, true);
$r = easyocrCreateInvoiceFromOCR($fxParams('FIX', array('datef' => '2026-11-18', 'total_ttc' => '33.68')), $user);
eo_assert('created', isset($r['status']) ? $r['status'] : ($r['message'] ?? ''), 'ok');
$row = $inv($r['id']);
$ln = $line($r['id']);
eo_assert('kept in USD', $row ? $row->multicurrency_code : null, 'USD');
eo_assert('linked to the declared currency', $row ? (int) $row->fk_multicurrency : 0, (int) MultiCurrency::getIdFromCode($db, 'USD'));
eo_assert('rate: 1 / 0.87', $row ? (float) $row->multicurrency_tx : null, 1.14942529, 0.000000005);
eo_assert('total in EUR: 33.68 x 0.87 = 29.30', $row ? (float) $row->total_ttc : null, 29.30, 0.001);
eo_assert('total in USD as printed: 33.68', $row ? (float) $row->multicurrency_total_ttc : null, 33.68, 0.001);
eo_assert('line: printed price in USD', $ln ? (float) $ln->multicurrency_subprice : null, 1.00, 0.00001);
eo_assert('line: total in USD', $ln ? (float) $ln->multicurrency_total_ht : null, 33.68, 0.01);
eo_assert('line: total in EUR', $ln ? (float) $ln->total_ht : null, 29.30, 0.01);
eo_assert('result: native', isset($r['fx']['native']) ? $r['fx']['native'] : null, true);
// Many units at a price with decimals: the line in USD must add up to what is printed,
// not to the EUR price (rounded to MAIN_MAX_DECIMALS_UNIT) converted back. With the
// default 5 decimals both give the same; with 2 (set in some installations) they do not.
$eoSavedMu = isset($conf->global->MAIN_MAX_DECIMALS_UNIT) ? $conf->global->MAIN_MAX_DECIMALS_UNIT : null;
$conf->global->MAIN_MAX_DECIMALS_UNIT = 2;
$r = easyocrCreateInvoiceFromOCR($fxParams('BIG', array('datef' => '2026-11-11', 'total_ht' => '777000.00', 'total_ttc' => '777000.00',
	'items' => array(array('description' => 'Bulk', 'item_type' => 'product', 'quantity' => 100000, 'unit_price' => 7.77, 'net_amount' => 777000,
		'taxes' => array(array('tax_type' => 'vat', 'tax_rate' => 0)))))), $user);
$ln = $line($r['id']);
eo_info('big line: pu_ht=' . ($ln ? $ln->pu_ht : '?') . ' mc_subprice=' . ($ln ? $ln->multicurrency_subprice : '?') . ' mc_total=' . ($ln ? $ln->multicurrency_total_ht : '?'));
eo_assert('big line: USD unit price exactly as printed', $ln ? (string) price2num($ln->multicurrency_subprice, 'MU') : null, '7.77');
eo_assert('big line: USD total exactly as printed', $ln ? (string) price2num($ln->multicurrency_total_ht, 'MT') : null, '777000');
if ($eoSavedMu === null) {
	unset($conf->global->MAIN_MAX_DECIMALS_UNIT);
} else {
	$conf->global->MAIN_MAX_DECIMALS_UNIT = $eoSavedMu;
}

eo_group('document currency against the supplier record (multicurrency on)');
$setSupCur = function ($code) use ($db, $supplierId) {
	$db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET multicurrency_code = " . ($code === '' ? 'NULL' : "'" . $db->escape($code) . "'") . " WHERE rowid = " . (int) $supplierId);
};
$noCurNote = $langs->transnoentities('EasyOcrFxSupplierNoCurrency', 'USD');
$hasReview = function ($r) {
	return in_array('fx_supplier_currency', isset($r['review_codes']) ? $r['review_codes'] : array(), true);
};
$setSupCur('');
$r = easyocrCreateInvoiceFromOCR($fxParams('SUPNONE', array('datef' => '2026-11-10')), $user);
eo_assert('record without currency: told to set it', in_array($noCurNote, isset($r['fx_notes']) ? $r['fx_notes'] : array(), true), true);
eo_assert('record without currency: nothing to review', $hasReview($r), false);
$setSupCur('USD');
$r = easyocrCreateInvoiceFromOCR($fxParams('SUPUSD', array('datef' => '2026-11-09')), $user);
eo_assert('record says USD, document USD: no notice', array(in_array($noCurNote, $r['fx_notes'], true), $hasReview($r)), array(false, false));
$setSupCur('GBP');
// Without review the module would validate: a draft here was kept by the review
$conf->global->EASYOCR_INVOICE_DRAFT = 0;
$r = easyocrCreateInvoiceFromOCR($fxParams('SUPGBP', array('datef' => '2026-11-08', 'draft_on_review' => 1, 'invoice_status' => '')), $user);
eo_assert('record says GBP, document USD: to review', $hasReview($r), true);
eo_assert('to review: unattended, it stays a draft', isset($r['forced_draft']) ? $r['forced_draft'] : null, true);
$conf->global->EASYOCR_INVOICE_DRAFT = $eoSaved['EASYOCR_INVOICE_DRAFT'];
$r = easyocrCreateInvoiceFromOCR($fxParams('SUPNOREAD', array('datef' => '2026-11-07', 'currency' => '')), $user);
$row = $inv($r['id']);
eo_assert('currency not read, record says GBP: to review', $hasReview($r), true);
eo_assert('currency not read: amounts as printed (nothing to convert)', $row ? (float) $row->total_ttc : null, 33.68, 0.001);
$setSupCur('');

eo_group('USD invoice paid automatically: paid in USD at the invoice rate');
$bank = $db->fetch_object($db->query("SELECT MIN(rowid) id FROM " . MAIN_DB_PREFIX . "bank_account WHERE courant = 1 AND clos = 0 AND currency_code = 'EUR' AND entity IN (" . getEntity('bank_account') . ")"));
$vir = $db->fetch_object($db->query("SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = 'VIR' AND active = 1"));
if ($bank && (int) $bank->id > 0 && $vir) {
	$r = easyocrCreateInvoiceFromOCR($fxParams('PAY', array('datef' => '2026-11-17', 'invoice_status' => 'validated',
		'create_payment' => '1', 'payment_bank_id' => (int) $bank->id, 'payment_type_id' => (int) $vir->id)), $user);
	eo_assert('created and validated', isset($r['status']) ? array($r['status'], $r['is_draft']) : null, array('ok', false));
	eo_assert('no payment error', isset($r['payment_error']) ? $r['payment_error'] : null, '');
	$pf = $db->fetch_object($db->query("SELECT pf.amount, pf.multicurrency_amount, pf.multicurrency_code FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn pf WHERE pf.fk_facturefourn = " . (int) $r['id']));
	eo_assert('payment in EUR 29.30', $pf ? (float) $pf->amount : null, 29.30, 0.006);
	eo_assert('payment in USD 33.68', $pf ? (float) $pf->multicurrency_amount : null, 33.68, 0.001);
	eo_assert('payment currency USD', $pf ? $pf->multicurrency_code : null, 'USD');
	$row = $inv($r['id']);
	eo_assert('invoice paid', $row ? array((int) $row->fk_statut, (int) $row->paye) : null, array(2, 1));
} else {
	eo_info('skipped: no open EUR current account or no VIR payment type');
}

eo_group('multicurrency on but the currency is not declared: converted');
$conf->global->EASYOCR_FX_FIXED_RATES = 'USD=0,87' . "\n" . 'CAD=0,62';
$hasCad = MultiCurrency::getIdFromCode($db, 'CAD') > 0;
if (!$hasCad) {
	$r = easyocrCreateInvoiceFromOCR($fxParams('CAD', array('currency' => 'CAD', 'datef' => '2026-11-16')), $user);
	$row = $inv($r['id']);
	eo_assert('created', isset($r['status']) ? $r['status'] : '', 'ok');
	eo_assert('in EUR, not silently in CAD at rate 1', $row ? array($row->multicurrency_code, (float) $row->total_ttc) : null, array('EUR', round(33.68 * 0.62, 2)));
	eo_assert('note says CAD is not declared', $row && strpos($row->note_private, $langs->transnoentities('EasyOcrFxNotInMulticurrency', 'CAD', 'EUR')) !== false, true);
} else {
	eo_info('skipped: CAD is declared in this database');
}

// Back to multicurrency off for the rest
unset($conf->modules['multicurrency']);
$conf->multicurrency->enabled = 0;
$conf->global->EASYOCR_FX_FIXED_RATES = '';

eo_group('conversion switched off (installation coming from before 2.10.0)');
$conf->global->EASYOCR_FX_ENABLED = '0';
$r = easyocrCreateInvoiceFromOCR($fxParams('OFF', array('datef' => '2026-11-15')), $user);
$row = $inv($r['id']);
eo_assert('created', isset($r['status']) ? $r['status'] : '', 'ok');
eo_assert('amounts as printed', $row ? (float) $row->total_ttc : null, 33.68, 0.001);
eo_assert('no conversion in the result', array_key_exists('fx', $r) ? $r['fx'] : 'missing', null);
eo_assert('the note says the conversion is off', $row && strpos($row->note_private, 'USD') !== false, true);
eo_assert('informs, does not ask for a review', isset($r['review_codes']) ? $r['review_codes'] : null, array());
unset($conf->global->EASYOCR_FX_ENABLED);

eo_group('our own currency: nothing changes');
$r = easyocrCreateInvoiceFromOCR($fxParams('EUR', array('currency' => 'EUR', 'datef' => '2026-11-14', 'total_ht' => '100.00', 'total_tva' => '21.00', 'total_ttc' => '121.00', 'items' => array())), $user);
$row = $inv($r['id']);
eo_assert('created', isset($r['status']) ? $r['status'] : '', 'ok');
eo_assert('total untouched', $row ? (float) $row->total_ttc : null, 121.00, 0.001);
eo_assert('no currency note', $row && strpos((string) $row->note_private, 'USD') === false, true);

eo_group('no rate: nothing is created');
$n0 = $countInv();
$s0 = $countSoc();
$noSoc = array('fk_soc' => 0, 'supplier_name' => 'EOQA FX NUEVO ' . $seed, 'supplier_tax_id' => 'B' . substr(preg_replace('/\D/', '', md5($seed)) . '00000000', 0, 8));
$r = easyocrCreateInvoiceFromOCR($fxParams('XYZ', array_merge($noSoc, array('currency' => 'XYZ'))), $user);
eo_assert('currency the ECB does not publish: error', isset($r['error_code']) ? array($r['status'], $r['error_code']) : null, array('error', 'fx_currency_unknown'));
$r2 = easyocrCreateInvoiceFromOCR($fxParams('ABC', array_merge($noSoc, array('currency' => 'ABC'))), $user);
eo_assert('ECB unreachable: retry later', isset($r2['error_code']) ? array($r2['status'], $r2['error_code'], $r2['retry_after']) : null, array('retry', 'fx_rate_unavailable', 300));
eo_assert('no invoice created', $countInv(), $n0);
eo_assert('no supplier created for it', $countSoc(), $s0);

eo_group('invoice date not read');
$r = easyocrCreateInvoiceFromOCR($fxParams('NODATE', array('datef' => '')), $user);
eo_assert('created (rate of today)', isset($r['status']) ? $r['status'] : ($r['message'] ?? ''), 'ok');
eo_assert('asks for a review: the rate may not be the one of the invoice', in_array('fx_no_date', isset($r['review_codes']) ? $r['review_codes'] : array(), true), true);

eo_group('template detection against the database');
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (entity, name, fk_soc, scale) VALUES (" . ((int) $conf->entity) . ", 'EOQA GitHub old', " . ((int) $supplierId) . ", 1.5)");
$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (entity, name, fk_soc, scale) VALUES (" . ((int) $conf->entity) . ", 'EOQA GitHub', " . ((int) $supplierId) . ", 1.5)");
$tplNew = (int) $db->last_insert_id(MAIN_DB_PREFIX . "easyocr_template");
// Only the fixture is named in the text: a real "GitHub" supplier with a template in this
// database would otherwise also match ("GitHub Actions") and make it ambiguous
$text = str_replace(array('GitHub, Inc.', 'GitHub Actions'), array('EOQA FX GITHUB ' . $seed . ', Inc.', 'Cloud Actions'), $github);
$d = easyocrDetectTemplateForText($text);
eo_assert('one supplier with a template: found', array($d['match'], $d['fk_soc']), array('single', $supplierId));
eo_assert('by its name (its tax id field holds its name)', $d['via'], 'name');
eo_assert('the most recent of its templates', $d['template_id'], $tplNew);
eo_assert('a text that is not it: none', easyocrDetectTemplateForText('Factura de otro proveedor cualquiera')['match'], 'none');
eo_assert('empty text: none', easyocrDetectTemplateForText('   ')['match'], 'none');

// ═════════════════════════════════════════════════════════════════════════
$db->rollback();
foreach ($eoSaved as $k => $v) {
	if ($v === null) {
		unset($conf->global->$k);
	} else {
		$conf->global->$k = $v;
	}
}
$conf->currency = $eoSavedCurrency;
$conf->modules = $eoSavedModules;
$conf->multicurrency = $eoSavedMc;

eo_group('cleanup');
eo_assert('the test supplier is gone after rollback', (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . (int) $supplierId))->n, 0);

if ($live) {
	eo_group('live: the real ECB API');
	unset($GLOBALS['easyocr_fx_cache']);
	$r = easyocrFxEcbRate('USD', '2026-11-19');
	eo_info(json_encode($r));
	eo_assert('USD on 2026-11-19 = 1.0992', isset($r['rate']) ? $r['rate'] : (isset($r['error']) ? $r['error'] : null), 1.0992);
	$r = easyocrFxEcbRate('USD', '2026-11-15');
	eo_assert('Sunday 2026-11-15 takes Friday 2026-11-13', isset($r['date']) ? $r['date'] : (isset($r['error']) ? $r['error'] : null), '2026-11-13');
	eo_assert('a code the ECB does not have: unknown', easyocrFxEcbRate('XYZ', '2026-11-19'), array('error' => 'unknown'));
}

$total = $GLOBALS['eo_pass'] + $GLOBALS['eo_fail'];
echo "\n" . str_repeat('─', 52) . "\n";
if ($GLOBALS['eo_fail'] === 0) {
	echo "\033[32mAll " . $total . " currency assertions passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $GLOBALS['eo_fail'] . " of " . $total . " currency assertions failed.\033[0m\n";
exit(1);
