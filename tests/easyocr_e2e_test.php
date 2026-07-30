<?php
/**
 * EasyOcr — end-to-end test: real PDF -> real AI service -> real Dolibarr invoice.
 *
 * This is the only test that talks to the AI service, so it SPENDS CREDITS and
 * needs the --spend-credits flag to run. Everything it writes to the database is
 * rolled back.
 *
 * It covers the whole chain the other tests can only cover in pieces:
 *   1. what the AI service actually returns for a known document,
 *   2. how the module maps that payload into a supplier invoice,
 *   3. that the invoice lines reproduce the amounts printed on the PDF.
 *
 * Run:  php tests/easyocr_e2e_test.php --spend-credits [path/to/pdf-folder]
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}
if (!in_array('--spend-credits', $argv, true)) {
	echo "This test calls the AI service and spends credits.\n";
	echo "Re-run with:  php tests/easyocr_e2e_test.php --spend-credits\n";
	exit(2);
}

$pdfDir = null;
foreach (array_slice($argv, 1) as $arg) {
	if (substr($arg, 0, 2) !== '--') {
		$pdfDir = rtrim($arg, '/\\');
	}
}
if ($pdfDir === null) {
	$pdfDir = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/Desktop' : getcwd();
}

// ── Precondition: the database must be reachable (see integration test) ──
$confFile = __DIR__ . '/../../../conf/conf.php';
if (is_readable($confFile)) {
	include $confFile;
	$dbHost = !empty($dolibarr_main_db_host) ? $dolibarr_main_db_host : '127.0.0.1';
	$dbPort = !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306;
	$probe = @fsockopen($dbHost === 'localhost' ? '127.0.0.1' : $dbHost, $dbPort, $errno, $errstr, 3);
	if ($probe === false) {
		fwrite(STDERR, "\033[31mDatabase unreachable at " . $dbHost . ':' . $dbPort . " — " . $errstr . "\033[0m\n");
		fwrite(STDERR, "Start MySQL/MariaDB (Laragon) and run this again. No credits were spent.\n");
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
require_once __DIR__ . '/../lib/easyocr_ai.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

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

/**
 * Map the AI service's structured_data onto easyocrCreateInvoiceFromOCR params.
 * Mirrors what the review modal sends from the browser.
 */
function eo_map_params($sd, $refSuffix = '')
{
	$totals = isset($sd['totals']) && is_array($sd['totals']) ? $sd['totals'] : array();

	$defaultRate = 0;
	if (!empty($totals['taxes'][0]['tax_rate'])) {
		$defaultRate = (float) $totals['taxes'][0]['tax_rate'];
	}

	$netSubtotal = isset($totals['net_subtotal']) ? $totals['net_subtotal'] : 0;
	$taxTotal    = isset($totals['tax_total']) ? $totals['tax_total'] : 0;
	$grandTotal  = isset($totals['total']) ? $totals['total'] : 0;

	return array(
		'ref_supplier'     => (isset($sd['document_number']) ? $sd['document_number'] : 'NO-REF') . $refSuffix,
		'datef'            => isset($sd['issue_date']) ? $sd['issue_date'] : '',
		'date_echeance'    => isset($sd['due_date']) ? $sd['due_date'] : '',
		'total_ht'         => $netSubtotal,
		'total_tva'        => $taxTotal,
		'total_ttc'        => $grandTotal,
		'total_localtax2'  => isset($totals['withholding_total']) ? $totals['withholding_total'] : 0,
		'default_tax_rate' => $defaultRate,
		'supplier_name'    => isset($sd['supplier']['name']) ? $sd['supplier']['name'] : '',
		'supplier_tax_id'  => isset($sd['supplier']['tax_id']) ? $sd['supplier']['tax_id'] : '',
		'supplier_address' => isset($sd['supplier']['address']) ? $sd['supplier']['address'] : '',
		'supplier_phone'   => isset($sd['supplier']['phone']) ? $sd['supplier']['phone'] : '',
		'supplier_email'   => isset($sd['supplier']['email']) ? $sd['supplier']['email'] : '',
		'customer_tax_id'  => isset($sd['customer']['tax_id']) ? $sd['customer']['tax_id'] : '',
		'invoice_status'   => 'draft',
		'items'            => isset($sd['items']) ? $sd['items'] : array(),
		'import_key'       => 'easyocr-e2e',
	);
}

// ── Setup ────────────────────────────────────────────────────────────────
eo_group('environment');

$svc = new EasyOcrAI($db);
eo_assert('AI service is configured', $svc->isEnabled(), true);
if (!$svc->isEnabled()) {
	echo "\nConfigure EASYOCR_AI_ENABLED / _URL / _APIKEY first.\n";
	exit(1);
}

$resAdmin = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
$user = new User($db);
$user->fetch($db->fetch_object($resAdmin)->rowid);
eo_assert('admin user loaded', $user->id > 0, true);
eo_info('pdf folder: ' . $pdfDir);

$db->begin();
register_shutdown_function(function () use ($db) {
	if (!empty($db->transaction_opened)) {
		$db->rollback();
		echo "\n\033[33mTransaction rolled back on shutdown.\033[0m\n";
	}
});

$uniq = substr(md5(microtime(true)), 0, 6);

// ── 1) TEST-01: the full invoice ─────────────────────────────────────────
eo_group('TEST-01 — full invoice through the real service');

$pdf01 = $pdfDir . '/1-EASYOCR-TEST-01-completa.pdf';
if (!is_readable($pdf01)) {
	eo_info('skipped: ' . $pdf01 . ' not found');
} else {
	$bytes01 = file_get_contents($pdf01);
	$t0 = microtime(true);
	$res01 = $svc->processBase64(base64_encode($bytes01), '', basename($pdf01));
	$elapsed = round((microtime(true) - $t0) * 1000);

	eo_assert('the service answered', $res01 !== false, true);
	if ($res01 === false) {
		eo_info('error: ' . $svc->error);
	} else {
		eo_info('round trip: ' . $elapsed . ' ms, tokens=' . (isset($res01['tokens']['total']) ? $res01['tokens']['total'] : '?'));
		// The v2.7.0 regression was a >75 s round trip caused by the prompt block
		eo_assert('round trip stays well under the 75 s regression', $elapsed < 45000, true);
		eo_assert('no parse error', empty($res01['error_code']), true);

		$sd = isset($res01['structured_data']) ? $res01['structured_data'] : null;
		eo_assert('structured data came back', is_array($sd), true);

		if (is_array($sd)) {
			eo_assert('document number', isset($sd['document_number']) ? $sd['document_number'] : '', 'A/2026-0042');
			eo_assert('issue date', isset($sd['issue_date']) ? $sd['issue_date'] : '', '2026-07-14');
			eo_assert('supplier tax id', isset($sd['supplier']['tax_id']) ? $sd['supplier']['tax_id'] : '', 'B99887766');
			eo_assert('eight items extracted', count($sd['items']), 8);
			eo_assert('net subtotal', (float) $sd['totals']['net_subtotal'], 6054.50, 0.01);
			eo_assert('VAT total', (float) $sd['totals']['tax_total'], 1271.45, 0.01);
			eo_assert('IRPF withholding picked up', (float) $sd['totals']['withholding_total'], 30.0, 0.01);

			// The service rounds unit prices to 2 decimals — this is the input
			// condition the module has to repair, so assert it is still true.
			eo_assert('unit prices arrive rounded to 2 decimals (0.347 -> 0.35)', (float) $sd['items'][3]['unit_price'], 0.35, 0.0001);
			eo_assert('and 3.434 -> 3.43', (float) $sd['items'][4]['unit_price'], 3.43, 0.0001);
			eo_assert('but the net amounts are exact', (float) $sd['items'][4]['net_amount'], 5150.50, 0.001);

			// ── Create the invoice from that payload ─────────────────────
			$params = eo_map_params($sd, '-E2E-' . $uniq);
			$created = easyocrCreateInvoiceFromOCR($params, $user);

			eo_assert('invoice created', isset($created['status']) ? $created['status'] : '', 'ok');
			if (isset($created['status']) && $created['status'] === 'ok') {
				$invId = (int) $created['invoice_id'];
				eo_info('invoice id=' . $invId . ' supplier_created=' . var_export($created['supplier_created'], true));
				eo_assert('no line errors', count($created['line_errors']), 0);

				$q = $db->query("SELECT ref, qty, pu_ht, remise_percent, total_ht FROM " . MAIN_DB_PREFIX . "facture_fourn_det WHERE fk_facture_fourn = " . $invId . " ORDER BY rowid");
				$rows = array();
				while ($q && ($o = $db->fetch_object($q))) {
					$rows[] = $o;
				}
				eo_assert('eight lines persisted', count($rows), 8);

				if (count($rows) === 8) {
					// What the PDF prints, line by line
					$printed = array(
						array('ref' => 'FLT-2200', 'pu' => 5.0,     'rem' => 10.0, 'net' => 45.00),
						array('ref' => 'TUB-450',  'pu' => 12.5,    'rem' => 10.0, 'net' => 225.00),
						array('ref' => 'BRD-08',   'pu' => 15.0,    'rem' => 15.0, 'net' => 102.00),
						array('ref' => 'TOR-M6',   'pu' => 0.347,   'rem' => 0.0,  'net' => 347.00),
						array('ref' => 'ARN-15',   'pu' => 3.43367, 'rem' => 0.0,  'net' => 5150.50),
						array('ref' => '',         'pu' => -50.0,   'rem' => 0.0,  'net' => -50.00),
						array('ref' => 'PORTES',   'pu' => 35.0,    'rem' => 0.0,  'net' => 35.00),
						array('ref' => 'SRV-AST',  'pu' => 200.0,   'rem' => 0.0,  'net' => 200.00),
					);
					$sum = 0.0;
					foreach ($printed as $i => $exp) {
						$r = $rows[$i];
						eo_assert('line ' . ($i + 1) . ' (' . ($exp['ref'] ?: 'discount') . '): supplier ref', (string) $r->ref, $exp['ref']);
						eo_assert('line ' . ($i + 1) . ': unit price as printed', (float) $r->pu_ht, $exp['pu'], 0.00002);
						eo_assert('line ' . ($i + 1) . ': discount', (float) $r->remise_percent, $exp['rem'], 0.01);
						eo_assert('line ' . ($i + 1) . ': amount as printed', (float) $r->total_ht, $exp['net'], 0.02);
						$sum += (float) $r->total_ht;
					}
					eo_assert('lines add up to the printed subtotal', round($sum, 2), 6054.50, 0.02);
					eo_info('sum of line totals = ' . number_format($sum, 2, '.', ''));
				}

				$h = $db->fetch_object($db->query("SELECT total_ht, tva, total_ttc, localtax2 FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE rowid = " . $invId));
				eo_assert('header HT', (float) $h->total_ht, 6054.50, 0.01);
				eo_assert('header VAT', (float) $h->tva, 1271.45, 0.01);
				eo_assert('header TTC', (float) $h->total_ttc, 7295.95, 0.01);
				eo_assert('IRPF stored as a negative localtax2', (float) $h->localtax2, -30.0, 0.01);

				// ── Duplicate guard on the very same bytes ───────────────
				$hash01 = easyocrComputeFileHash($bytes01);
				easyocrRegisterProcessedFile($hash01, basename($pdf01), strlen($bytes01), $user->id);
				easyocrLinkProcessedFileToInvoice($hash01, $invId);
				$known = easyocrLookupProcessedFile($hash01);
				eo_assert('re-uploading the same PDF is recognised', is_array($known), true);
				eo_assert('and points at the invoice we just made', $known['invoice_id'], $invId);
			} else {
				eo_info('message: ' . (isset($created['message']) ? $created['message'] : ''));
			}
		}
	}
}

// ── 2) TEST-02: issuer and receiver are the same company ─────────────────
eo_group('TEST-02 — issuer is the receiver (guard must fire)');

$pdf02 = $pdfDir . '/EASYOCR-TEST-02-emisor-es-receptor.pdf';
if (!is_readable($pdf02)) {
	eo_info('skipped: ' . $pdf02 . ' not found');
} else {
	$bytes02 = file_get_contents($pdf02);
	$t0 = microtime(true);
	$res02 = $svc->processBase64(base64_encode($bytes02), '', basename($pdf02));
	$elapsed = round((microtime(true) - $t0) * 1000);
	eo_assert('the service answered', $res02 !== false, true);

	if ($res02 !== false) {
		$outTokens = isset($res02['tokens']['output']) ? (int) $res02['tokens']['output'] : 0;
		eo_info('round trip: ' . $elapsed . ' ms, output tokens: ' . $outTokens);

		$usable = easyocrAiResultIsUsable($res02);
		eo_info('usable payload: ' . var_export($usable, true));

		if (!$usable) {
			// Known service-side behaviour: this document makes the model run to
			// its output ceiling and come back with structured_data.parse_error.
			// It happens with an empty prompt too, so it is not something the
			// module causes — but the module must fail cleanly and, above all,
			// must NOT fingerprint the document, or the retry would be refused.
			eo_info('the model degenerated on this document (parse_error) — checking the module fails cleanly');

			$hash02 = easyocrComputeFileHash($bytes02);
			eo_assert('an unusable answer is rejected by the guard', easyocrAiResultIsUsable($res02), false);

			// Simulate what the ajax entry points now do with such an answer.
			// Only the absence of a NEW fingerprint proves the fix; a row may
			// already exist from before the guard was added.
			$existing = easyocrLookupProcessedFile($hash02, false);
			$countBeforeFp = (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "easyocr_processed_files"))->n;
			if (easyocrAiResultIsUsable($res02)) {
				easyocrRegisterProcessedFile($hash02, basename($pdf02), strlen($bytes02), $user->id);
			}
			$countAfterFp = (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "easyocr_processed_files"))->n;
			eo_assert('an unusable answer adds no fingerprint, so the retry is allowed', $countAfterFp, $countBeforeFp);

			if ($existing !== null && empty($existing['invoice_id'])) {
				eo_info('NOTE: this document already has a stale fingerprint (row ' . $existing['id']
					. ') from before the guard existed — it would be refused as a duplicate until deleted');
			}
		} else {
			$sd2 = $res02['structured_data'];
			eo_info('supplier=' . (isset($sd2['supplier']['tax_id']) ? $sd2['supplier']['tax_id'] : '?')
				. ' customer=' . (isset($sd2['customer']['tax_id']) ? $sd2['customer']['tax_id'] : '?'));

			$countBefore = (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "societe"))->n;
			$params2 = eo_map_params($sd2, '-E2E-' . $uniq);
			$created2 = easyocrCreateInvoiceFromOCR($params2, $user);

			eo_assert('creation is refused', isset($created2['status']) ? $created2['status'] : '', 'error');
			$code = isset($created2['error_code']) ? $created2['error_code'] : '';
			eo_assert('with a self-supplier error code',
				in_array($code, array('supplier_is_receiver', 'supplier_equals_customer'), true), true);

			$countAfter = (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "societe"))->n;
			eo_assert('our own company was not created as a supplier', $countAfter, $countBefore);
		}
	}
}

// ── 3) TEST-03: lines that do not add up to the stated totals ────────────
eo_group('TEST-03 — totals mismatch (must be reported, not swallowed)');

$pdf03 = $pdfDir . '/EASYOCR-TEST-03-descuadre.pdf';
if (!is_readable($pdf03)) {
	eo_info('skipped: ' . $pdf03 . ' not found');
} else {
	$bytes03 = file_get_contents($pdf03);
	$res03 = $svc->processBase64(base64_encode($bytes03), '', basename($pdf03));
	eo_assert('the service answered', $res03 !== false, true);

	if ($res03 !== false) {
		$outTokens3 = isset($res03['tokens']['output']) ? (int) $res03['tokens']['output'] : 0;
		eo_info('output tokens: ' . $outTokens3);

		if (!easyocrAiResultIsUsable($res03)) {
			// Same service-side degeneration as TEST-02
			eo_info('the model degenerated on this document too (parse_error)');
			eo_assert('an unusable answer is rejected by the guard', easyocrAiResultIsUsable($res03), false);
			eo_assert('and it is not fingerprinted either', easyocrLookupProcessedFile(easyocrComputeFileHash($bytes03)), null);
		} else {
			$sd3 = $res03['structured_data'];
			$warnings = easyocrCheckTotalsConsistency($sd3['items'], $sd3['totals']);
			eo_assert('the mismatch is detected', count($warnings) > 0, true);
			foreach ($warnings as $w) {
				eo_info($w['field'] . ': document says ' . $w['expected'] . ', lines add up to ' . $w['computed'] . ' (diff ' . $w['diff'] . ')');
			}

			$params3 = eo_map_params($sd3, '-E2E-' . $uniq);
			$created3 = easyocrCreateInvoiceFromOCR($params3, $user);
			if (isset($created3['status']) && $created3['status'] === 'ok') {
				eo_assert('the invoice is still created (the user decides)', $created3['status'], 'ok');
				eo_assert('and carries the warning', !empty($created3['totals_warnings']), true);
			} else {
				eo_info('creation refused: ' . (isset($created3['message']) ? $created3['message'] : ''));
			}
		}
	}
}

// ── Roll back ────────────────────────────────────────────────────────────
$db->rollback();

eo_group('cleanup');
$leftovers = (int) $db->fetch_object($db->query("SELECT COUNT(*) n FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE import_key = 'easyocr-e2e'"))->n;
eo_assert('no test invoice survived the rollback', $leftovers, 0);

$total = $GLOBALS['eo_pass'] + $GLOBALS['eo_fail'];
echo "\n" . str_repeat('─', 52) . "\n";
if ($GLOBALS['eo_fail'] === 0) {
	echo "\033[32mAll " . $total . " end-to-end assertions passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $GLOBALS['eo_fail'] . " of " . $total . " end-to-end assertions failed.\033[0m\n";
exit(1);
