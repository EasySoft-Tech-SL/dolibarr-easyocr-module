<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
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
 * \file       tests/easyocr_lib_test.php
 * \ingroup    easyocr
 * \brief      Standalone unit tests for the pure helpers of lib/easyocr.lib.php
 *
 * No PHPUnit, no Dolibarr bootstrap: the helpers under test do not touch the
 * database, so the library can be included directly.
 *
 * Run:  php tests/easyocr_lib_test.php
 * Exit code 0 = all green, 1 = at least one failure.
 */

// ── Minimal stubs for the Dolibarr symbols referenced inside untested code paths.
// PHP only resolves them when those functions run, but defining them keeps any
// accidental call from fatally aborting the suite.
if (!defined('MAIN_DB_PREFIX')) define('MAIN_DB_PREFIX', 'llx_');
if (!defined('DOL_DOCUMENT_ROOT')) define('DOL_DOCUMENT_ROOT', __DIR__);
if (!defined('LOG_DEBUG')) define('LOG_DEBUG', 7);
if (!defined('LOG_INFO')) define('LOG_INFO', 6);
if (!defined('LOG_WARNING')) define('LOG_WARNING', 4);
if (!defined('LOG_ERR')) define('LOG_ERR', 3);
if (!function_exists('dol_syslog')) { function dol_syslog($m, $l = 6) {} }
if (!function_exists('dol_now')) { function dol_now() { return time(); } }
if (!function_exists('dol_trunc')) { function dol_trunc($s, $n = 80) { return substr($s, 0, $n); } }
if (!function_exists('getEntity')) { function getEntity($e) { return '1'; } }

require_once __DIR__ . '/../lib/easyocr.lib.php';

// ── Tiny assertion harness ───────────────────────────────────────────────
$GLOBALS['eo_pass'] = 0;
$GLOBALS['eo_fail'] = 0;
$GLOBALS['eo_group'] = '';

function eo_group($name)
{
	$GLOBALS['eo_group'] = $name;
	echo "\n\033[1m" . $name . "\033[0m\n";
}

function eo_assert($label, $actual, $expected, $epsilon = null)
{
	$ok = ($epsilon === null) ? ($actual === $expected) : (abs($actual - $expected) <= $epsilon);
	if ($ok) {
		$GLOBALS['eo_pass']++;
		echo "  \033[32mPASS\033[0m  " . $label . "\n";
	} else {
		$GLOBALS['eo_fail']++;
		echo "  \033[31mFAIL\033[0m  " . $label . "\n";
		echo "        expected: " . var_export($expected, true) . "\n";
		echo "        actual:   " . var_export($actual, true) . "\n";
	}
}


// ============================================================
// easyocrParseNumber — regression guard for the discount cascade
// ============================================================
eo_group('easyocrParseNumber (regression)');

eo_assert('European thousands + decimals "1.500,00"', easyocrParseNumber('1.500,00'), 1500.0, 0.0001);
eo_assert('European decimals "274,80"', easyocrParseNumber('274,80'), 274.80, 0.0001);
eo_assert('Anglo decimals "274.80"', easyocrParseNumber('274.80'), 274.80, 0.0001);
eo_assert('Currency noise "1.234,56 EUR"', easyocrParseNumber('1.234,56 EUR'), 1234.56, 0.0001);
eo_assert('Negative "-10,50"', easyocrParseNumber('-10,50'), -10.50, 0.0001);
eo_assert('Empty string', easyocrParseNumber(''), 0.0, 0.0001);
eo_assert('Three decimals "3,434"', easyocrParseNumber('3,434'), 3434.0, 0.0001); // documented behaviour: 3 digits = grouping

// Native JSON numbers must bypass the string heuristics: the AI returns
// unit prices like 3.434, which the grouping rule would inflate to 3434.
eo_assert('float 3.434 stays 3.434', easyocrParseNumber(3.434), 3.434, 0.000001);
eo_assert('float 1500.0 stays 1500', easyocrParseNumber(1500.0), 1500.0, 0.0001);
eo_assert('int 42 stays 42', easyocrParseNumber(42), 42.0, 0.0001);
eo_assert('negative float -10.5', easyocrParseNumber(-10.5), -10.5, 0.0001);
eo_assert('float 0.001 stays 0.001', easyocrParseNumber(0.001), 0.001, 0.000001);
eo_assert('null is zero', easyocrParseNumber(null), 0.0, 0.0001);
eo_assert('JSON-decoded price keeps its value', easyocrParseNumber(json_decode('{"p":3.434}', true)['p']), 3.434, 0.000001);


// ============================================================
// easyocrResolveLineDiscount — P1
// ============================================================
eo_group('easyocrResolveLineDiscount');

// 1) Explicit percentage wins
eo_assert(
	'explicit discount_percent = 10',
	easyocrResolveLineDiscount(array('discount_percent' => 10), 2, 50),
	10.0, 0.0001
);

// An explicit zero means "no discount": must NOT fall through to inference
eo_assert(
	'explicit discount_percent = 0 blocks inference',
	easyocrResolveLineDiscount(array('discount_percent' => 0, 'net_amount' => 90), 2, 50),
	0.0, 0.0001
);

// 2) Absolute amount converted against the gross line
eo_assert(
	'discount_amount 10 over gross 100 = 10%',
	easyocrResolveLineDiscount(array('discount_amount' => 10), 2, 50),
	10.0, 0.0001
);
eo_assert(
	'discount_amount in European format "1,50" over gross 100',
	easyocrResolveLineDiscount(array('discount_amount' => '1,50'), 1, 100),
	1.5, 0.0001
);
eo_assert(
	'negative discount_amount is treated as magnitude',
	easyocrResolveLineDiscount(array('discount_amount' => -10), 2, 50),
	10.0, 0.0001
);

// 3) Implicit gap between qty*unit_price and net_amount
eo_assert(
	'implicit gap 100 -> 90 = 10%',
	easyocrResolveLineDiscount(array('net_amount' => 90), 2, 50),
	10.0, 0.0001
);
eo_assert(
	'implicit gap with European net "90,00"',
	easyocrResolveLineDiscount(array('net_amount' => '90,00'), 2, 50),
	10.0, 0.0001
);

// Tolerance: rounding noise must not become a discount
eo_assert(
	'gap of 0.01 is rounding, not a discount',
	easyocrResolveLineDiscount(array('net_amount' => 99.99), 1, 100),
	0.0, 0.0001
);
eo_assert(
	'gap of exactly 0.02 stays under tolerance',
	easyocrResolveLineDiscount(array('net_amount' => 99.98), 1, 100),
	0.0, 0.0001
);
// Relative floor: below 0.5% the gap is rounding noise, not a commercial discount
eo_assert(
	'gap of 0.03 on a 100 line is noise, not a discount',
	easyocrResolveLineDiscount(array('net_amount' => 99.97), 1, 100),
	0.0, 0.0001
);
eo_assert(
	'large line with 3-decimal unit price is not a 0.01% discount',
	easyocrResolveLineDiscount(array('net_amount' => 3433.50), 1000, 3.434),
	0.0, 0.0001
);
eo_assert(
	'gap of exactly 0.5% is kept',
	easyocrResolveLineDiscount(array('net_amount' => 99.5), 1, 100),
	0.5, 0.0001
);
eo_assert(
	'realistic 12% discount is kept',
	easyocrResolveLineDiscount(array('net_amount' => 88), 1, 100),
	12.0, 0.0001
);

// Sanity clamp: absurd values mean the OCR misread something
eo_assert(
	'absurd explicit 150% falls through to inference (none available)',
	easyocrResolveLineDiscount(array('discount_percent' => 150), 1, 100),
	0.0, 0.0001
);
eo_assert(
	'implicit gap over 90% is discarded',
	easyocrResolveLineDiscount(array('net_amount' => 5), 1, 100),
	0.0, 0.0001
);
eo_assert(
	'implicit gap of exactly 90% is kept',
	easyocrResolveLineDiscount(array('net_amount' => 10), 1, 100),
	90.0, 0.0001
);

// Separate "discount" lines carry negative amounts — never infer on them
eo_assert(
	'negative unit price yields no discount',
	easyocrResolveLineDiscount(array('net_amount' => -50), 1, -50),
	0.0, 0.0001
);
eo_assert(
	'zero unit price yields no discount',
	easyocrResolveLineDiscount(array('net_amount' => 90), 2, 0),
	0.0, 0.0001
);

// A surcharge (net above gross) is not a discount
eo_assert(
	'net above gross is not a discount',
	easyocrResolveLineDiscount(array('net_amount' => 120), 1, 100),
	0.0, 0.0001
);

// No usable data at all
eo_assert('empty item', easyocrResolveLineDiscount(array(), 1, 100), 0.0, 0.0001);
eo_assert(
	'null discount fields are ignored',
	easyocrResolveLineDiscount(array('discount_percent' => null, 'discount_amount' => null), 1, 100),
	0.0, 0.0001
);

// Precedence: percent beats amount beats gap
eo_assert(
	'percent beats amount',
	easyocrResolveLineDiscount(array('discount_percent' => 5, 'discount_amount' => 20), 1, 100),
	5.0, 0.0001
);
eo_assert(
	'amount beats implicit gap',
	easyocrResolveLineDiscount(array('discount_amount' => 20, 'net_amount' => 90), 1, 100),
	20.0, 0.0001
);


// ============================================================
// easyocrResolveLineUnitPrice — P1 (feeds addline, must be PRE-discount)
// ============================================================
eo_group('easyocrResolveLineUnitPrice');

eo_assert(
	'printed unit price is kept as-is',
	easyocrResolveLineUnitPrice(array('net_amount' => 90), 2, 10, 50),
	50.0, 0.0001
);
eo_assert(
	'derives from net_amount when no unit price',
	easyocrResolveLineUnitPrice(array('net_amount' => 100), 2, 0, 0),
	50.0, 0.0001
);

// Dolibarr re-applies remise_percent, so a net-derived price must be un-discounted
eo_assert(
	'net-derived price is un-discounted',
	easyocrResolveLineUnitPrice(array('net_amount' => 90), 1, 10, 0),
	100.0, 0.0001
);
eo_assert(
	'total-derived price is un-discounted too',
	easyocrResolveLineUnitPrice(array('total' => 108.9, 'taxes' => array(array('tax_type' => 'iva', 'tax_amount' => 18.9))), 1, 10, 0),
	100.0, 0.0001
);
eo_assert(
	'total minus taxes without discount',
	easyocrResolveLineUnitPrice(array('total' => 121, 'taxes' => array(array('tax_type' => 'iva', 'tax_amount' => 21))), 1, 0, 0),
	100.0, 0.0001
);
eo_assert(
	'flat tax_amount fallback is honoured',
	easyocrResolveLineUnitPrice(array('total' => 121, 'tax_amount' => 21), 1, 0, 0),
	100.0, 0.0001
);
eo_assert(
	'net_amount wins over total',
	easyocrResolveLineUnitPrice(array('net_amount' => 100, 'total' => 999), 1, 0, 0),
	100.0, 0.0001
);
eo_assert(
	'zero quantity does not divide by zero',
	easyocrResolveLineUnitPrice(array('net_amount' => 100), 0, 0, 0),
	100.0, 0.0001
);
eo_assert(
	'100% discount does not divide by zero',
	easyocrResolveLineUnitPrice(array('net_amount' => 100), 1, 100, 0),
	100.0, 0.0001
);
eo_assert(
	'European format net amount',
	easyocrResolveLineUnitPrice(array('net_amount' => '1.500,00'), 2, 0, 0),
	750.0, 0.0001
);
eo_assert('nothing to derive from', easyocrResolveLineUnitPrice(array(), 1, 0, 0), 0.0, 0.0001);
eo_assert(
	'negative printed price (discount line) is kept',
	easyocrResolveLineUnitPrice(array(), 1, 0, -50),
	-50.0, 0.0001
);

// Round trip: what addline() recomputes must match the document's net amount
$rtPrice = easyocrResolveLineUnitPrice(array('net_amount' => 88), 1, 12, 0);
eo_assert('round trip price * (1 - discount) == net', $rtPrice * (1 - 12 / 100), 88.0, 0.0001);


// ============================================================
// Integration — an OCR line must round-trip to the document's net amount
//
// Dolibarr computes the line net as qty * unit_price * (1 - remise/100), so
// whatever the cascade hands to addline() has to reproduce net_amount exactly.
// ============================================================
eo_group('Integration: OCR line -> addline() -> document net');

function eo_line_net($item)
{
	$qty = isset($item['quantity']) && $item['quantity'] !== '' ? easyocrParseNumber($item['quantity']) : 1;
	$printed = isset($item['unit_price']) && $item['unit_price'] !== '' ? easyocrParseNumber($item['unit_price']) : 0;
	$discount = easyocrResolveLineDiscount($item, $qty, $printed);
	$price = easyocrResolveLineUnitPrice($item, $qty, $discount, $printed);
	// Mirrors Dolibarr's own line computation
	return $qty * $price * (1 - $discount / 100);
}

// A) Inline discount (prompt "approach A"): percent column on the item row
eo_assert(
	'inline percent discount reproduces net',
	eo_line_net(array('quantity' => 10, 'unit_price' => 5, 'discount_percent' => 10, 'net_amount' => 45)),
	45.0, 0.0001
);

// B) Discount printed only in euros — the case that silently lost the discount
eo_assert(
	'euro-only discount reproduces net',
	eo_line_net(array('quantity' => 10, 'unit_price' => 5, 'discount_amount' => 5, 'net_amount' => 45)),
	45.0, 0.0001
);

// C) Discount not reported at all, only visible as a gap
eo_assert(
	'implicit discount reproduces net',
	eo_line_net(array('quantity' => 10, 'unit_price' => 5, 'net_amount' => 45)),
	45.0, 0.0001
);

// D) No printed unit price, explicit percent
eo_assert(
	'no unit price + explicit percent reproduces net',
	eo_line_net(array('quantity' => 10, 'net_amount' => 45, 'discount_percent' => 10)),
	45.0, 0.0001
);

// E) Plain line, no discount anywhere
eo_assert(
	'plain line reproduces net',
	eo_line_net(array('quantity' => 2, 'unit_price' => 10, 'net_amount' => 20)),
	20.0, 0.0001
);

// F) Neither unit price nor a way to know the gross: net must still be honoured
eo_assert(
	'net-only line reproduces net',
	eo_line_net(array('quantity' => 10, 'net_amount' => 45, 'discount_amount' => 5)),
	45.0, 0.0001
);

// G) Derived from total minus taxes
eo_assert(
	'total-minus-taxes line reproduces net',
	eo_line_net(array('quantity' => 2, 'total' => 121, 'taxes' => array(array('tax_type' => 'iva', 'tax_amount' => 21)))),
	100.0, 0.0001
);

// H) Separate discount line: negative amounts must survive untouched
eo_assert(
	'separate discount line keeps its negative net',
	eo_line_net(array('quantity' => 1, 'unit_price' => -6.88, 'net_amount' => -6.88, 'item_type' => 'discount')),
	-6.88, 0.0001
);

// I) European formatting throughout
eo_assert(
	'European formatted line reproduces net',
	eo_line_net(array('quantity' => '2', 'unit_price' => '50,00', 'discount_percent' => '10', 'net_amount' => '90,00')),
	90.0, 0.0001
);

// J) A small gap is rounding, not a discount — but the line must still land on
//    the net amount the document prints, because that is what its totals sum.
//    Also guards the JSON-float regression: 3.434 must not be read as 3434.
$noisy = array('quantity' => 1000, 'unit_price' => 3.434, 'net_amount' => 3433.50);
eo_assert('rounding gap does not become a discount', easyocrResolveLineDiscount($noisy, 1000, 3.434), 0.0, 0.0001);
eo_assert('line still reproduces the printed net', eo_line_net($noisy), 3433.50, 0.0001);

// J2) REAL payload captured from the AI service for EASYOCR-TEST-01.
//     The service rounds unit prices to 2 decimals, so 0.347 arrives as 0.35
//     and 3.434 as 3.43. Every line must still reproduce the net amount the
//     document states, and the eight lines must add up to its stated subtotal.
$realInvoiceLines = array(
	array('quantity' => 10,   'unit_price' => 5,     'discount_percent' => 10, 'net_amount' => 45),
	array('quantity' => 20,   'unit_price' => 12.5,  'discount_amount' => 25,  'net_amount' => 225),
	array('quantity' => 8,    'unit_price' => 15,                              'net_amount' => 102),
	array('quantity' => 1000, 'unit_price' => 0.35,                            'net_amount' => 347),
	array('quantity' => 1500, 'unit_price' => 3.43,                            'net_amount' => 5150.5),
	array('quantity' => 1,    'unit_price' => -50,                             'net_amount' => -50),
	array('quantity' => 1,    'unit_price' => 35,                              'net_amount' => 35),
	array('quantity' => 1,    'unit_price' => 200,                             'net_amount' => 200),
);
$realSum = 0.0;
foreach ($realInvoiceLines as $idx => $line) {
	$net = eo_line_net($line);
	eo_assert('real line ' . ($idx + 1) . ' reproduces its net amount', $net, (float) $line['net_amount'], 0.005);
	$realSum += $net;
}
eo_assert('real invoice lines add up to the stated subtotal', $realSum, 6054.50, 0.005);

// A rounded unit price must not be mistaken for a discount
eo_assert(
	'2-decimal rounding on 1000 units is not a discount',
	easyocrResolveLineDiscount(array('net_amount' => 347), 1000, 0.35),
	0.0, 0.0001
);
eo_assert(
	'and the real unit price is recovered from the net',
	easyocrResolveLineUnitPrice(array('net_amount' => 347), 1000, 0, 0.35),
	0.347, 0.00001
);
// ...but a genuine discount on a large quantity still survives
eo_assert(
	'a real 20% discount on 1000 units is still detected',
	easyocrResolveLineDiscount(array('net_amount' => 280), 1000, 0.35),
	20.0, 0.0001
);

// J3) The model is NOT deterministic about where it puts a discount.
//     A later real run of the same TEST-01 PDF returned discount_amount = null
//     for TUB-450, whose 25 EUR discount is printed in its own column: the
//     amount has to be recovered from the gap between 20x12.50 and 225.
//     Same document, different cascade rule, identical result required.
$sameDocOtherRun = array(
	array('label' => 'TUB-450 without discount_amount', 'item' => array('quantity' => 20, 'unit_price' => 12.5, 'discount_percent' => null, 'discount_amount' => null, 'net_amount' => 225), 'pct' => 10.0, 'net' => 225.0),
	array('label' => 'TUB-450 with discount_amount',    'item' => array('quantity' => 20, 'unit_price' => 12.5, 'discount_amount' => 25, 'net_amount' => 225),                                'pct' => 10.0, 'net' => 225.0),
	array('label' => 'BRD-08 implicit gap',             'item' => array('quantity' => 8,  'unit_price' => 15, 'net_amount' => 102),                                                          'pct' => 15.0, 'net' => 102.0),
);
foreach ($sameDocOtherRun as $case) {
	$qty = easyocrParseNumber($case['item']['quantity']);
	$pu  = easyocrParseNumber($case['item']['unit_price']);
	eo_assert($case['label'] . ': discount', easyocrResolveLineDiscount($case['item'], $qty, $pu), $case['pct'], 0.0001);
	eo_assert($case['label'] . ': net', eo_line_net($case['item']), $case['net'], 0.005);
}
// Both routes must also leave the unit price GROSS, or addline() double-discounts
eo_assert(
	'the recovered unit price stays pre-discount either way',
	easyocrResolveLineUnitPrice($sameDocOtherRun[0]['item'], 20, 10.0, 12.5),
	12.5, 0.00001
);

// K) Hand-edited quantity with a comma decimal mark
eo_assert(
	'comma-decimal quantity is parsed',
	eo_line_net(array('quantity' => '3,5', 'unit_price' => 10)),
	35.0, 0.0001
);

// L) Real payload shape from the AI service (JSON-decoded, native numbers)
$payload = json_decode('{"quantity": 2, "unit_price": 3.434, "discount_percent": null, "discount_amount": null, "net_amount": 6.87}', true);
eo_assert('AI payload keeps the 3-decimal unit price', eo_line_net($payload), 6.868, 0.001);


// ============================================================
// easyocrNormalizeTaxId — P2
// ============================================================
eo_group('easyocrNormalizeTaxId');

eo_assert('strips separators and upcases', easyocrNormalizeTaxId('es-b123 456.78'), 'ESB12345678');
eo_assert('already normalized', easyocrNormalizeTaxId('B12345678'), 'B12345678');
eo_assert('empty input', easyocrNormalizeTaxId(''), '');
eo_assert('null input', easyocrNormalizeTaxId(null), '');


// ============================================================
// easyocrIsOwnCompanyTaxId — P2
// ============================================================
eo_group('easyocrIsOwnCompanyTaxId');

$mysoc = new stdClass();
$mysoc->idprof1 = 'B12345678';
$mysoc->tva_intra = 'ESB12345678';
$conf = new stdClass();
$conf->global = new stdClass();
$conf->entity = 1;
$GLOBALS['mysoc'] = $mysoc;
$GLOBALS['conf'] = $conf;

eo_assert('exact match on idprof1', easyocrIsOwnCompanyTaxId('B12345678'), true);
eo_assert('match ignoring separators', easyocrIsOwnCompanyTaxId('B-12.345 678'), true);
eo_assert('match with country prefix', easyocrIsOwnCompanyTaxId('ESB12345678'), true);
eo_assert('match lowercase', easyocrIsOwnCompanyTaxId('esb12345678'), true);
eo_assert('different tax id', easyocrIsOwnCompanyTaxId('A87654321'), false);
eo_assert('empty tax id', easyocrIsOwnCompanyTaxId(''), false);
eo_assert('too short to be meaningful', easyocrIsOwnCompanyTaxId('B12'), false);

// Falls back to the raw constants when $mysoc is not initialised (NOLOGIN context).
// At global scope $mysoc IS $GLOBALS['mysoc'], so keep a separate handle to restore.
$savedMysoc = $mysoc;
$GLOBALS['mysoc'] = null;
$conf->global->MAIN_INFO_SIREN = 'B12345678';
eo_assert('falls back to MAIN_INFO_SIREN', easyocrIsOwnCompanyTaxId('B12345678'), true);
unset($conf->global->MAIN_INFO_SIREN);
$GLOBALS['mysoc'] = $savedMysoc;
eo_assert('mysoc restored for the next group', is_object($GLOBALS['mysoc']), true);


// ============================================================
// easyocrBuildReceiverContext / easyocrAugmentInstructions — P2
// ============================================================
eo_group('easyocrBuildReceiverContext + easyocrAugmentInstructions');

$mysoc->name = 'Easysoft Tech S.L.';

// Off by default: this is the only v2.7.0 feature that changes the request sent
// to the AI service, so it must never be active unless explicitly switched on.
eo_assert('DEFAULT IS OFF — no context without the setting', easyocrBuildReceiverContext(), '');
eo_assert('default off -> instructions untouched', easyocrAugmentInstructions('Mine only.'), 'Mine only.');
eo_assert('default off -> empty stays empty', easyocrAugmentInstructions(''), '');

$conf->global->EASYOCR_AI_RECEIVER_CONTEXT = 1;
$ctx = easyocrBuildReceiverContext();
eo_assert('context mentions the receiver name', strpos($ctx, 'Easysoft Tech S.L.') !== false, true);
eo_assert('context lists the tax ids', strpos($ctx, 'B12345678') !== false, true);
eo_assert('context tells the model to extract as printed', stripos($ctx, 'as printed') !== false, true);

// The block must stay DECLARATIVE. Procedural wording ("verify before
// returning", "re-read the document") made a ~10 s extraction take minutes
// against a structured-output model with reasoning disabled.
eo_assert('context has no verification procedure', stripos($ctx, 'verify') === false, true);
eo_assert('context does not ask for a re-read', stripos($ctx, 're-read') === false, true);
eo_assert('context stays short', strlen($ctx) < 400, true);
eo_assert('context is a single line', substr_count(trim($ctx), "\n"), 0);

// It must NOT assert anything about the document's contents. Claiming "the
// supplier is the other company" is false when issuer and receiver are the
// same party, and an unsatisfiable instruction made the model degenerate:
// it repeated newlines to the output-token ceiling (~75 s + truncated JSON).
eo_assert('context does not claim there is another company', stripos($ctx, 'other company') === false, true);
eo_assert('context does not dictate where values belong', stripos($ctx, 'belong') === false, true);
eo_assert('context makes no claim about the supplier', stripos($ctx, 'the supplier is') === false, true);

$augmented = easyocrAugmentInstructions('Focus on page 2.');
eo_assert('user instructions are preserved', strpos($augmented, 'Focus on page 2.') !== false, true);
eo_assert('receiver context comes first', strpos($augmented, 'Context, for telling') === 0, true);

$augmentedEmpty = easyocrAugmentInstructions('');
eo_assert('empty user instructions still get context', strpos($augmentedEmpty, 'Context, for telling') === 0, true);

// Kill switch: explicitly off behaves like the default
$conf->global->EASYOCR_AI_RECEIVER_CONTEXT = 0;
eo_assert('setting off -> no context', easyocrBuildReceiverContext(), '');
eo_assert('setting off -> instructions untouched', easyocrAugmentInstructions('Mine only.'), 'Mine only.');
unset($conf->global->EASYOCR_AI_RECEIVER_CONTEXT);
eo_assert('unset setting -> still off', easyocrBuildReceiverContext(), '');

// Unknown company identity must not inject an empty block
$GLOBALS['mysoc'] = null;
$blankConf = new stdClass();
$blankConf->global = new stdClass();
$blankConf->entity = 1;
$GLOBALS['conf'] = $blankConf;
eo_assert('no identity -> no context', easyocrBuildReceiverContext(), '');
eo_assert('no identity -> instructions untouched', easyocrAugmentInstructions('Only mine.'), 'Only mine.');
$GLOBALS['mysoc'] = $mysoc;
$GLOBALS['conf'] = $conf;


// ============================================================
// easyocrCheckTotalsConsistency — P5
// ============================================================
eo_group('easyocrCheckTotalsConsistency');

$goodItems = array(
	array('quantity' => 2, 'unit_price' => 50, 'net_amount' => 100, 'taxes' => array(array('tax_type' => 'iva', 'tax_rate' => 21, 'tax_amount' => 21))),
	array('quantity' => 1, 'unit_price' => 100, 'net_amount' => 100, 'taxes' => array(array('tax_type' => 'iva', 'tax_rate' => 21, 'tax_amount' => 21))),
);
eo_assert(
	'lines matching the totals produce no warning',
	count(easyocrCheckTotalsConsistency($goodItems, array('total_ht' => 200, 'total_tva' => 42))),
	0
);

eo_assert(
	'net mismatch is reported',
	count(easyocrCheckTotalsConsistency($goodItems, array('total_ht' => 250, 'total_tva' => 42))),
	1
);

$warnings = easyocrCheckTotalsConsistency($goodItems, array('total_ht' => 250, 'total_tva' => 42));
eo_assert('warning names the field', $warnings[0]['field'], 'total_ht');
eo_assert('warning reports the computed sum', $warnings[0]['computed'], 200.0, 0.001);
eo_assert('warning reports the declared total', $warnings[0]['expected'], 250.0, 0.001);
eo_assert('warning reports the signed difference', $warnings[0]['diff'], -50.0, 0.001);

eo_assert(
	'both net and tax can mismatch at once',
	count(easyocrCheckTotalsConsistency($goodItems, array('total_ht' => 250, 'total_tva' => 90))),
	2
);

eo_assert(
	'rounding within tolerance is not reported',
	count(easyocrCheckTotalsConsistency($goodItems, array('total_ht' => 200.04, 'total_tva' => 42))),
	0
);

eo_assert(
	'a zero declared total means "not reported"',
	count(easyocrCheckTotalsConsistency($goodItems, array('total_ht' => 0, 'total_tva' => 0))),
	0
);

eo_assert('no items, no warnings', count(easyocrCheckTotalsConsistency(array(), array('total_ht' => 100))), 0);
eo_assert('non-array items are tolerated', count(easyocrCheckTotalsConsistency('nope', array('total_ht' => 100))), 0);

// RE and IRPF have their own totals and must not inflate total_tva
$mixedTaxItems = array(
	array('quantity' => 1, 'unit_price' => 100, 'net_amount' => 100, 'taxes' => array(
		array('tax_type' => 'iva', 'tax_rate' => 21, 'tax_amount' => 21),
		array('tax_type' => 're', 'tax_rate' => 5.2, 'tax_amount' => 5.2),
		array('tax_type' => 'irpf', 'tax_rate' => 15, 'tax_amount' => 15),
	)),
);
eo_assert(
	'RE and IRPF are excluded from the VAT check',
	count(easyocrCheckTotalsConsistency($mixedTaxItems, array('total_ht' => 100, 'total_tva' => 21))),
	0
);

// Tax computed from the rate when the amount is missing
$noAmountItems = array(
	array('quantity' => 1, 'unit_price' => 100, 'net_amount' => 100, 'taxes' => array(array('tax_type' => 'tva', 'tax_rate' => 21))),
);
eo_assert(
	'VAT is derived from the rate when no amount is given',
	count(easyocrCheckTotalsConsistency($noAmountItems, array('total_ht' => 100, 'total_tva' => 21))),
	0
);

// Without net_amount, the discount cascade feeds the net used for the check
$discountItems = array(
	array('quantity' => 2, 'unit_price' => 50, 'discount_percent' => 10),
);
eo_assert(
	'net is reconstructed through the discount cascade',
	count(easyocrCheckTotalsConsistency($discountItems, array('total_ht' => 90))),
	0
);


// ============================================================
// easyocrComputeFileHash — P6
// ============================================================
eo_group('easyocrComputeFileHash');

eo_assert(
	'known sha256 of "easyocr"',
	easyocrComputeFileHash('easyocr'),
	hash('sha256', 'easyocr')
);
eo_assert('deterministic', easyocrComputeFileHash('abc'), easyocrComputeFileHash('abc'));
eo_assert('different content, different hash', easyocrComputeFileHash('abc') === easyocrComputeFileHash('abd'), false);
eo_assert('hash length is 64 hex chars', strlen(easyocrComputeFileHash('x')), 64);
eo_assert('fits the varchar(64) column', strlen(easyocrComputeFileHash(str_repeat('y', 100000))), 64);


// ============================================================
// easyocrAiResultIsUsable — P8
// The service answers 200/"success" even when the model could not
// emit JSON. Accepting that spends credits AND fingerprints the
// document, so the retry would be refused as a duplicate.
// ============================================================

eo_group('easyocrAiResultIsUsable');

$goodPayload = array(
	'status' => 'success',
	'structured_data' => array(
		'document_number' => 'A/2026-0042',
		'issue_date'      => '2026-07-14',
		'supplier'        => array('tax_id' => 'B99887766'),
		'items'           => array(array('description' => 'x')),
		'totals'          => array('net_subtotal' => 100),
	),
);
eo_assert('a normal payload is usable', easyocrAiResultIsUsable($goodPayload), true);

// This is the real shape returned for the degenerate documents
eo_assert(
	'parse_error is not usable',
	easyocrAiResultIsUsable(array('status' => 'success', 'structured_data' => array('raw' => '{"supplier"...', 'parse_error' => 'Expecting value'))),
	false
);
eo_assert(
	'a null parse_error still counts as a failure',
	easyocrAiResultIsUsable(array('status' => 'success', 'structured_data' => array('raw' => '...', 'parse_error' => null))),
	false
);
eo_assert('missing structured_data', easyocrAiResultIsUsable(array('status' => 'success')), false);
eo_assert('empty structured_data', easyocrAiResultIsUsable(array('structured_data' => array())), false);
eo_assert('structured_data without any document field', easyocrAiResultIsUsable(array('structured_data' => array('currency' => 'EUR'))), false);
eo_assert('an error_code disqualifies it', easyocrAiResultIsUsable(array('error_code' => 'timeout', 'structured_data' => $goodPayload['structured_data'])), false);
eo_assert('a structuring_error disqualifies it', easyocrAiResultIsUsable(array('structuring_error' => 'boom', 'structured_data' => $goodPayload['structured_data'])), false);
eo_assert('false input', easyocrAiResultIsUsable(false), false);
eo_assert('null input', easyocrAiResultIsUsable(null), false);
eo_assert('string input', easyocrAiResultIsUsable('{}'), false);

// One identifying field is enough — partial extractions are still worth showing
eo_assert(
	'only a document number is enough to review',
	easyocrAiResultIsUsable(array('structured_data' => array('document_number' => 'X-1'))),
	true
);


// ============================================================
// Duplicate-guard settings — P7
// The guard costs nothing when it hits and saves AI credits when
// it does, so it must stay ON unless explicitly switched off.
// ============================================================

eo_group('duplicate guard settings');

$GLOBALS['conf'] = $conf;
unset($conf->global->EASYOCR_DUPLICATE_CHECK);
eo_assert('DEFAULT IS ON when the setting was never saved', easyocrDuplicateCheckEnabled(), true);

$conf->global->EASYOCR_DUPLICATE_CHECK = 1;
eo_assert('explicitly enabled', easyocrDuplicateCheckEnabled(), true);

$conf->global->EASYOCR_DUPLICATE_CHECK = 0;
eo_assert('explicitly disabled', easyocrDuplicateCheckEnabled(), false);

$conf->global->EASYOCR_DUPLICATE_CHECK = '0';
eo_assert('the string "0" disables it too (Dolibarr stores chaine)', easyocrDuplicateCheckEnabled(), false);

$conf->global->EASYOCR_DUPLICATE_CHECK = '1';
eo_assert('the string "1" enables it', easyocrDuplicateCheckEnabled(), true);

unset($conf->global->EASYOCR_DUPLICATE_CHECK);

// Window: 0 means "remember every document", which is the default
unset($conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS);
eo_assert('window defaults to unlimited', easyocrDuplicateWindowDays(), 0);

$conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS = 30;
eo_assert('window honours the configured value', easyocrDuplicateWindowDays(), 30);

$conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS = '45';
eo_assert('window casts the stored string', easyocrDuplicateWindowDays(), 45);

$conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS = -10;
eo_assert('a negative window cannot invert the SQL interval', easyocrDuplicateWindowDays(), 0);

$conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS = 'abc';
eo_assert('garbage falls back to unlimited', easyocrDuplicateWindowDays(), 0);

unset($conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS);

// No $conf at all (CLI / early bootstrap): the guard must not blow up
$GLOBALS['conf'] = null;
eo_assert('no $conf — guard stays on', easyocrDuplicateCheckEnabled(), true);
eo_assert('no $conf — window is unlimited', easyocrDuplicateWindowDays(), 0);
$GLOBALS['conf'] = $conf;


// ── Summary ──────────────────────────────────────────────────────────────
$total = $GLOBALS['eo_pass'] + $GLOBALS['eo_fail'];
echo "\n" . str_repeat('─', 52) . "\n";
if ($GLOBALS['eo_fail'] === 0) {
	echo "\033[32mAll " . $total . " assertions passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $GLOBALS['eo_fail'] . " of " . $total . " assertions failed.\033[0m\n";
exit(1);
