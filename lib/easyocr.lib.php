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
 * \file       lib/easyocr.lib.php
 * \ingroup    easyocr
 * \brief      Library file for EasyOcr module
 */

/**
 * Check user permission, compatible with Dolibarr v14 through v23+.
 * Uses $user->hasRight() when available (v16+), falls back to
 * $user->rights->module->perm for older versions.
 *
 * @param  User   $user    User object
 * @param  string $module  Module name (e.g. 'easyocr')
 * @param  string $perm    Permission name (e.g. 'read', 'write', 'delete')
 * @return bool             True if user has the permission
 */
function easyocrCheckRight($user, $module, $perm)
{
	if (method_exists($user, 'hasRight')) {
		return $user->hasRight($module, $perm);
	}
	return !empty($user->rights->{$module}->{$perm});
}

/**
 * Prepare admin pages header
 *
 * @return array Array of tabs
 */
function easyocr_admin_prepare_head()
{
	global $langs, $conf;

	$langs->load('easyocr@easyocr');

	$h = 0;
	$head = array();

	// Setup/Configuration tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('EasyOcrSetup');
	$head[$h][2] = 'settings';
	$h++;

	// Service Plan tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/plan.php', 1);
	$head[$h][1] = '<span class="fas fa-star" style="color: #f39c12;"></span> ' . $langs->trans('EasyOcrPlan');
	$head[$h][2] = 'plan';
	$h++;

	// License agreement tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/copying.php', 1);
	$head[$h][1] = '<span class="fas fa-file-contract" style="color: #34495e;"></span> ' . $langs->trans('EasyOcrCopying');
	$head[$h][2] = 'copying';
	$h++;

	// Telemetry & Data Protection tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/telemetry.php', 1);
	$head[$h][1] = '<span class="fas fa-shield-alt" style="color: #3498db;"></span> ' . $langs->trans('EasyOcrTelemetry');
	$head[$h][2] = 'telemetry';
	$h++;

	// About tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/about.php', 1);
	$head[$h][1] = '<span class="fas fa-info-circle" style="color: #3498db;"></span> ' . $langs->trans('EasyOcrAbout');
	$head[$h][2] = 'about';
	$h++;

	// ChangeLog tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/changelog.php', 1);
	$head[$h][1] = '<span class="fas fa-list-ul" style="color: #52c41a;"></span> ' . $langs->trans('EasyOcrChangeLog');
	$head[$h][2] = 'changelog';
	$h++;

	// Complete the array
	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin', 'remove');

	return $head;
}


// ============================================================
// Helper functions (shared across AJAX & webhook)
// ============================================================

/**
 * Normalise a date string into ISO Y-m-d format.
 *
 * Uses a regex-based approach: first normalises all common separators
 * (/, -, .) to a single canonical separator, then applies strtotime()
 * with explicit day/month reordering for European formats.
 *
 * @param  string $input  Raw date string from OCR
 * @return string         Date in Y-m-d, falls back to today
 */
function easyocrParseDate($input)
{
	$raw = trim($input);
	if ($raw === '') {
		return date('Y-m-d');
	}

	// Normalise separators to dash
	$normalised = preg_replace('/[\\/\\.]/', '-', $raw);

	// Try ISO first (YYYY-MM-DD)
	if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $normalised, $m)) {
		return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
	}

	// European day-first (DD-MM-YYYY or DD-MM-YY)
	if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $normalised, $m)) {
		$day   = (int) $m[1];
		$month = (int) $m[2];
		$year  = (int) $m[3];

		// Expand two-digit year
		if ($year < 100) {
			$year += ($year <= 50) ? 2000 : 1900;
		}

		// Validate: if day > 12, it can only be day-month
		// If ambiguous (both <= 12), assume European d-m-Y
		if ($day > 12 && $month <= 12) {
			return sprintf('%04d-%02d-%02d', $year, $month, $day);
		}
		if ($month > 12 && $day <= 12) {
			// Likely US m-d-Y
			return sprintf('%04d-%02d-%02d', $year, $day, $month);
		}
		// Default: European d-m-Y
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	// Fallback: let PHP guess
	$ts = strtotime($raw);
	if ($ts !== false) {
		return date('Y-m-d', $ts);
	}

	return date('Y-m-d');
}

/**
 * Convert a localised number string to a PHP float.
 *
 * Strategy: strip non-numeric characters except separators, then use a
 * single regex to detect the decimal part as the trailing separator group
 * (1-2 digits after the last separator).
 *
 * @param  string $value  OCR-extracted number (e.g. "1.234,56", "$1,234.56")
 * @return float
 */
function easyocrParseNumber($value)
{
	// Native numbers arrive already normalized (the AI service returns JSON
	// numbers). Running them through the string heuristics below would read
	// 3.434 as a thousands separator and turn 3,434 € into 3434 €.
	if (is_int($value) || is_float($value)) {
		return (float) $value;
	}
	if (is_bool($value) || $value === null) {
		return 0.0;
	}

	$raw = trim($value);
	if ($raw === '') {
		return 0.0;
	}

	// Preserve sign
	$sign = 1;
	if (preg_match('/^-/', $raw)) {
		$sign = -1;
	}

	// Remove everything that isn't a digit, comma, or dot
	$stripped = preg_replace('/[^\d.,]/', '', $raw);
	if ($stripped === '') {
		return 0.0;
	}

	// If only digits remain after stripping separators, simple conversion
	$onlyDigits = str_replace(['.', ','], '', $stripped);
	if ($stripped === $onlyDigits) {
		return $sign * floatval($stripped);
	}

	// Find the last separator character
	$lastSepPos = max(
		($p1 = strrpos($stripped, '.')) !== false ? $p1 : -1,
		($p2 = strrpos($stripped, ',')) !== false ? $p2 : -1
	);

	if ($lastSepPos === -1) {
		return $sign * floatval($onlyDigits);
	}

	$afterLast = substr($stripped, $lastSepPos + 1);

	// If the group after the last separator has 1 or 2 digits → decimal part
	// If it has 3 digits → thousands separator (e.g. 1.000 or 1,000)
	if (strlen($afterLast) <= 2) {
		// Last separator is the decimal mark
		$intPart = str_replace(['.', ','], '', substr($stripped, 0, $lastSepPos));
		return $sign * floatval($intPart . '.' . $afterLast);
	}

	// 3+ digits after last separator → treat all separators as grouping
	return $sign * floatval($onlyDigits);
}

/**
 * Compute the applicable tax rate given the base and the tax amount.
 *
 * @param  float|string $base  Net amount (HT / excl. tax)
 * @param  float|string $tax   Tax amount (TVA / IVA)
 * @return float               Rate percentage rounded to 2 decimals (e.g. 21.00)
 */
function easyocrCalcTaxRate($base, $tax)
{
	$b = abs(floatval($base));
	$t = abs(floatval($tax));
	if ($b < 0.01) {
		return 0.0;
	}
	return round($t / $b * 100, 2);
}

/**
 * Resolve the discount percentage of an OCR line.
 *
 * The AI returns discounts in several shapes depending on the document layout,
 * and sometimes not at all (the discount is then only visible as a gap between
 * qty * unit_price and net_amount). This resolves them in order of reliability:
 *
 *   1. discount_percent      — explicit percentage from the document
 *   2. discount_amount       — absolute amount, converted against the gross line
 *   3. implicit gap          — qty * unit_price vs net_amount (tolerance 0.02)
 *
 * Values outside 0-90% are discarded: they almost always mean the OCR misread
 * unit_price, quantity or net_amount, and a bogus discount corrupts the line.
 *
 * Lines whose gross amount is <= 0 (separate "discount" lines carrying negative
 * amounts) never get an inferred discount — their negative total IS the discount.
 *
 * @param  array      $item       OCR line item
 * @param  float      $qty        Parsed quantity
 * @param  float      $unitPrice  Parsed unit price as printed on the document (gross, pre-discount)
 * @return float                  Discount percentage (0 when none applies)
 */
function easyocrResolveLineDiscount($item, $qty, $unitPrice)
{
	// 1) Explicit percentage — trust it as-is
	if (isset($item['discount_percent']) && $item['discount_percent'] !== null && $item['discount_percent'] !== '') {
		$pct = easyocrParseNumber($item['discount_percent']);
		if ($pct > 0 && $pct <= 90) {
			return round($pct, 4);
		}
		// An explicit 0 means "no discount": stop here, do not try to infer one.
		if ($pct == 0) {
			return 0.0;
		}
	}

	$gross = floatval($qty) * floatval($unitPrice);
	if ($gross <= 0) {
		return 0.0;
	}

	// 2) Absolute discount amount → percentage over the gross line
	if (isset($item['discount_amount']) && $item['discount_amount'] !== null && $item['discount_amount'] !== '') {
		$amount = abs(easyocrParseNumber($item['discount_amount']));
		if ($amount > 0) {
			$pct = $amount / $gross * 100;
			if ($pct > 0 && $pct <= 90) {
				return round($pct, 4);
			}
			return 0.0;
		}
	}

	// 3) Implicit discount — the net line total is lower than qty * unit_price
	$net = null;
	if (isset($item['net_amount']) && $item['net_amount'] !== null && $item['net_amount'] !== '') {
		$net = easyocrParseNumber($item['net_amount']);
	}
	if ($net === null) {
		return 0.0;
	}
	// The AI reports unit prices rounded to 2 decimals, so a line billed at
	// 0.347 €/unit arrives as 0.35. Multiplied by the quantity that alone opens
	// a gap of up to qty * 0.005 — on 1000 units, 5 €. Attributing it to a
	// discount would invent one that is not on the document.
	$roundingSlack = abs($qty) * 0.005 + 0.01;

	// Three guards, because rounding noise scales with the size of the line:
	//   - absolute (0.02)      small lines printed to 2 decimals
	//   - unit-price rounding  large quantities with 3+ decimal unit prices
	//   - relative (0.5%)      a real commercial discount is always well above
	if ($net > 0 && $net < $gross && abs($gross - $net) > 0.02 && abs($gross - $net) > $roundingSlack) {
		$pct = (1 - $net / $gross) * 100;
		if ($pct >= 0.5 && $pct <= 90) {
			return round($pct, 4);
		}
	}

	return 0.0;
}

/**
 * Resolve the gross unit price to hand to Dolibarr's addline().
 *
 * addline() expects a PRE-discount unit price and applies remise_percent
 * itself, so any amount derived from a net figure has to be un-discounted
 * first — otherwise the discount is applied twice.
 *
 * Order: printed unit price (reconciled against net_amount) -> derive from
 * net_amount -> derive from total minus its taxes.
 *
 * @param  array $item      OCR line item
 * @param  float $qty       Parsed quantity
 * @param  float $discount  Discount percentage already resolved for this line
 * @param  float $unitPrice Unit price as printed (0 when absent)
 * @return float            Gross unit price to hand to addline()
 */
function easyocrResolveLineUnitPrice($item, $qty, $discount, $unitPrice)
{
	$unitPrice = (float) $unitPrice;
	$divisor = ($qty > 0) ? $qty : 1;
	// A 100% discount would divide by zero; nothing sensible to reconstruct
	$undiscount = ($discount > 0 && $discount < 100) ? (1 - $discount / 100) : 1;

	$net = null;
	if (isset($item['net_amount']) && $item['net_amount'] !== null && $item['net_amount'] !== '') {
		$net = easyocrParseNumber($item['net_amount']);
	}

	if ($unitPrice != 0) {
		// The AI rounds unit prices to 2 decimals. On large quantities the
		// printed price no longer reproduces the line total the document
		// states (1500 x 3.434 arrives as 1500 x 3.43 = 5145, not 5150.50),
		// which would create an invoice whose lines do not add up to its own
		// total. The net amount is the figure the document's totals agree
		// with, so it wins and the unit price is derived back from it.
		if ($net !== null && $net != 0) {
			$reproduced = $divisor * $unitPrice * $undiscount;
			if (abs($reproduced - $net) > 0.01) {
				return $net / $divisor / $undiscount;
			}
		}

		return $unitPrice;
	}

	// 1) From the net line amount (already discounted on the document)
	if ($net !== null && $net != 0) {
		return $net / $divisor / $undiscount;
	}

	// 2) From the line total, stripping its taxes — also a net figure, so it
	//    needs the same un-discounting as branch 1.
	if (isset($item['total']) && $item['total'] !== null && $item['total'] !== '') {
		$lineTotal = easyocrParseNumber($item['total']);
		if ($lineTotal != 0) {
			$lineTaxAmt = 0;
			if (!empty($item['taxes']) && is_array($item['taxes'])) {
				foreach ($item['taxes'] as $tax) {
					if (is_array($tax) && isset($tax['tax_amount'])) {
						$lineTaxAmt += easyocrParseNumber($tax['tax_amount']);
					}
				}
			} elseif (isset($item['tax_amount']) && $item['tax_amount'] !== '') {
				$lineTaxAmt = easyocrParseNumber($item['tax_amount']);
			}
			return ($lineTotal - $lineTaxAmt) / $divisor / $undiscount;
		}
	}

	return 0.0;
}

/**
 * Normalize a tax id (CIF/NIF/VAT) for comparison: uppercase, no separators.
 *
 * @param  string $taxId Raw tax id
 * @return string        Normalized tax id ('' when empty)
 */
function easyocrNormalizeTaxId($taxId)
{
	return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $taxId));
}

/**
 * Collect every tax id identifying the Dolibarr company itself (the invoice receiver).
 *
 * Returns both the raw normalized value and, for country-prefixed ids such as
 * "ESB12345678", the bare number — suppliers print either form.
 *
 * @return array Normalized tax ids, deduplicated, empty values removed
 */
function easyocrGetOwnTaxIds()
{
	global $conf, $mysoc;

	$raw = array();

	if (!empty($mysoc) && is_object($mysoc)) {
		foreach (array('idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra') as $prop) {
			if (!empty($mysoc->{$prop})) {
				$raw[] = $mysoc->{$prop};
			}
		}
	}

	// Fall back to the raw constants — $mysoc may be un-initialized in NOLOGIN context
	if (!empty($conf) && is_object($conf) && !empty($conf->global)) {
		foreach (array('MAIN_INFO_SIREN', 'MAIN_INFO_SIRET', 'MAIN_INFO_APE', 'MAIN_INFO_RCS', 'MAIN_INFO_PROFID5', 'MAIN_INFO_PROFID6', 'MAIN_INFO_TVAINTRA') as $key) {
			if (!empty($conf->global->{$key})) {
				$raw[] = $conf->global->{$key};
			}
		}
	}

	$out = array();
	foreach ($raw as $value) {
		$norm = easyocrNormalizeTaxId($value);
		if ($norm === '') {
			continue;
		}
		$out[] = $norm;
		// Country-prefixed VAT number → also keep the bare id
		if (preg_match('/^[A-Z]{2}([A-Z0-9]{5,})$/', $norm, $m)) {
			$out[] = $m[1];
		}
	}

	return array_values(array_unique($out));
}

/**
 * Check whether a tax id belongs to the Dolibarr company itself.
 *
 * Used to catch the classic OCR failure where the model reads the invoice
 * RECEIVER as the supplier, which would otherwise create the user's own
 * company as a supplier in llx_societe.
 *
 * @param  string $taxId Tax id extracted by the AI
 * @return bool          True when it matches one of our own tax ids
 */
function easyocrIsOwnCompanyTaxId($taxId)
{
	$norm = easyocrNormalizeTaxId($taxId);
	// Below 5 chars a match is far more likely to be noise than a real id
	if ($norm === '' || strlen($norm) < 5) {
		return false;
	}

	foreach (easyocrGetOwnTaxIds() as $own) {
		if ($own === $norm) {
			return true;
		}
		// Country prefix present on one side only (ESB1234 vs B1234)
		if (strlen($own) > 2 && substr($own, 2) === $norm) {
			return true;
		}
		if (strlen($norm) > 2 && substr($norm, 2) === $own) {
			return true;
		}
	}

	return false;
}

/**
 * Build the "receiver context" block appended to the AI custom instructions.
 *
 * Deliberately DECLARATIVE and short: it states who the receiver is and nothing
 * else. An earlier version asked the model to "verify before returning the JSON"
 * and to "re-read the document" on a mismatch; against a structured-output model
 * with reasoning disabled that turned a ~10 s extraction into minutes, because
 * the model cannot deliberate and instead runs to the output-token ceiling and
 * times out (the service then retries). Facts are cheap, procedures are not.
 *
 * Can be switched off entirely with EASYOCR_AI_RECEIVER_CONTEXT=0 — the
 * post-OCR guard in easyocrCreateInvoiceFromOCR() still catches the bad case.
 *
 * @return string Instruction block, or '' when disabled / identity unknown
 */
function easyocrBuildReceiverContext()
{
	global $conf, $mysoc;

	// OPT-IN, off by default. This is the only feature of v2.7.0 that alters the
	// request sent to the AI service, and getting its wording wrong has already
	// caused two production regressions (see the comment further down). The
	// guard that actually prevents the bad write lives in
	// easyocrCreateInvoiceFromOCR() and works regardless of this setting, so
	// the default carries no prompt risk: with it off, the payload sent to the
	// service is byte-for-byte the one v2.6.0 sent.
	if (!is_object($conf) || empty($conf->global->EASYOCR_AI_RECEIVER_CONTEXT)) {
		return '';
	}

	$name = '';
	if (!empty($mysoc) && is_object($mysoc) && !empty($mysoc->name)) {
		$name = $mysoc->name;
	} elseif (!empty($conf) && is_object($conf) && !empty($conf->global->MAIN_INFO_SOCIETE_NOM)) {
		$name = $conf->global->MAIN_INFO_SOCIETE_NOM;
	}

	$taxIds = easyocrGetOwnTaxIds();

	if ($name === '' && empty($taxIds)) {
		return '';
	}

	// States WHO WE ARE and nothing else. Two earlier versions were harmful:
	//
	//  1. A verification procedure ("verify before returning the JSON",
	//     "re-read the document") — expensive against a structured-output
	//     model with reasoning disabled.
	//  2. A claim about the document itself ("the supplier is the OTHER
	//     company on the document") — false whenever issuer and receiver are
	//     the same party. Faced with an unsatisfiable instruction the model
	//     degenerated, repeating newlines until it hit the output-token
	//     ceiling, which both burned ~75 s and truncated the JSON.
	//
	// So: never assert anything about what the document contains, and always
	// leave the model an instruction it can satisfy ("extract as printed").
	$parts = array();
	if ($name !== '') {
		$parts[] = '"' . $name . '"';
	}
	if (!empty($taxIds)) {
		$parts[] = 'tax id ' . implode(' / ', $taxIds);
	}

	$block  = 'Context, for telling the parties apart: this document is being processed by ' . implode(', ', $parts) . '. ';
	$block .= 'Extract supplier and customer exactly as printed on the document.';

	return $block;
}

/**
 * Prepend the receiver context to user-supplied custom instructions.
 *
 * @param  string $customInstructions Instructions coming from the template / UI
 * @return string                     Combined instructions
 */
function easyocrAugmentInstructions($customInstructions)
{
	$context = easyocrBuildReceiverContext();
	if ($context === '') {
		return $customInstructions;
	}
	$customInstructions = trim((string) $customInstructions);
	if ($customInstructions === '') {
		return $context;
	}

	return $context . "\n\n" . $customInstructions;
}

/**
 * Resolve payment defaults for a supplier.
 *
 * The supplier record's own defaults are usually empty, so fall back to what
 * actually happened on that supplier's recent invoices: the most frequent
 * payment term, payment mode and bank account of the last invoices wins.
 *
 * @param  int $fk_soc  Supplier id
 * @param  int $history Number of recent invoices to inspect (default 3)
 * @return array        cond_reglement_id, mode_reglement_id, fk_account (0 when unknown)
 */
function easyocrGetSupplierPaymentDefaults($fk_soc, $history = 3)
{
	global $db;

	if (empty($db) && !empty($GLOBALS['db'])) {
		$db = $GLOBALS['db'];
	}

	$out = array(
		'cond_reglement_id' => 0,
		'mode_reglement_id' => 0,
		'fk_account'        => 0,
		'source'            => 'none',
	);

	$fk_soc = (int) $fk_soc;
	if ($fk_soc <= 0 || empty($db) || !is_object($db)) {
		return $out;
	}

	// 1) Supplier record defaults — explicit configuration wins over history
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
	$soc = new Societe($db);
	if ($soc->fetch($fk_soc) > 0) {
		if (!empty($soc->cond_reglement_supplier_id)) {
			$out['cond_reglement_id'] = (int) $soc->cond_reglement_supplier_id;
		}
		if (!empty($soc->mode_reglement_supplier_id)) {
			$out['mode_reglement_id'] = (int) $soc->mode_reglement_supplier_id;
		}
		if ($out['cond_reglement_id'] || $out['mode_reglement_id']) {
			$out['source'] = 'supplier';
		}
	}

	// 2) Fill the gaps from the most frequent values of the last invoices
	$history = max(1, (int) $history);
	$sql  = "SELECT f.fk_cond_reglement, f.fk_mode_reglement, f.fk_account";
	$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn as f";
	$sql .= " WHERE f.fk_soc = " . $fk_soc;
	$sql .= " AND f.entity IN (" . getEntity('supplier_invoice') . ")";
	$sql .= " ORDER BY f.datef DESC, f.rowid DESC";
	$sql .= " LIMIT " . $history;

	$resql = $db->query($sql);
	if (!$resql) {
		return $out;
	}

	$counts = array('cond_reglement_id' => array(), 'mode_reglement_id' => array(), 'fk_account' => array());
	$rows = 0;
	while ($obj = $db->fetch_object($resql)) {
		$rows++;
		$map = array(
			'cond_reglement_id' => (int) $obj->fk_cond_reglement,
			'mode_reglement_id' => (int) $obj->fk_mode_reglement,
			'fk_account'        => (int) $obj->fk_account,
		);
		foreach ($map as $key => $value) {
			if ($value <= 0) {
				continue;
			}
			if (!isset($counts[$key][$value])) {
				$counts[$key][$value] = 0;
			}
			$counts[$key][$value]++;
		}
	}
	$db->free($resql);

	if ($rows === 0) {
		return $out;
	}

	$usedHistory = false;
	foreach ($counts as $key => $values) {
		if ($out[$key] > 0 || empty($values)) {
			continue;
		}
		// arsort keeps the first-inserted value on ties, i.e. the most recent invoice
		arsort($values);
		$out[$key] = (int) array_keys($values)[0];
		$usedHistory = true;
	}

	if ($usedHistory) {
		$out['source'] = ($out['source'] === 'supplier') ? 'mixed' : 'history';
	}

	return $out;
}

/**
 * Compare the sum of OCR line items against the OCR document totals.
 *
 * The invoice is stored with the document totals forced by SQL, so a line that
 * the AI misread stays invisible: Dolibarr shows correct totals over incorrect
 * lines. This surfaces the gap so the user can fix it before creating.
 *
 * @param  array $items     OCR line items
 * @param  array $totals    Document totals: total_ht, total_tva, total_ttc
 * @param  float $tolerance Absolute tolerance in currency units (default 0.05)
 * @return array            List of warnings: field, expected, computed, diff
 */
function easyocrCheckTotalsConsistency($items, $totals, $tolerance = 0.05)
{
	$warnings = array();

	if (!is_array($items) || empty($items)) {
		return $warnings;
	}

	$sumNet = 0.0;
	$sumTax = 0.0;
	foreach ($items as $item) {
		if (!is_array($item)) {
			continue;
		}

		$qty = isset($item['quantity']) && $item['quantity'] !== '' ? easyocrParseNumber($item['quantity']) : 1;
		$unitPrice = isset($item['unit_price']) && $item['unit_price'] !== '' ? easyocrParseNumber($item['unit_price']) : 0;

		if (isset($item['net_amount']) && $item['net_amount'] !== '' && $item['net_amount'] !== null) {
			$net = easyocrParseNumber($item['net_amount']);
		} else {
			$discount = easyocrResolveLineDiscount($item, $qty, $unitPrice);
			$net = $qty * $unitPrice * (1 - $discount / 100);
		}
		$sumNet += $net;

		// Only IVA/VAT counts toward total_tva — RE and IRPF have their own totals
		if (!empty($item['taxes']) && is_array($item['taxes'])) {
			foreach ($item['taxes'] as $tax) {
				if (!is_array($tax)) {
					continue;
				}
				$type = strtolower(trim($tax['tax_type'] ?? ''));
				if (!in_array($type, array('tva', 'iva', 'vat'), true)) {
					continue;
				}
				if (isset($tax['tax_amount']) && $tax['tax_amount'] !== '' && $tax['tax_amount'] !== null && easyocrParseNumber($tax['tax_amount']) != 0) {
					$sumTax += easyocrParseNumber($tax['tax_amount']);
				} elseif (!empty($tax['tax_rate'])) {
					$sumTax += $net * easyocrParseNumber($tax['tax_rate']) / 100;
				}
			}
		}
	}

	$checks = array(
		'total_ht'  => $sumNet,
		'total_tva' => $sumTax,
	);

	foreach ($checks as $field => $computed) {
		if (!isset($totals[$field]) || $totals[$field] === '' || $totals[$field] === null) {
			continue;
		}
		$expected = easyocrParseNumber($totals[$field]);
		// A zero declared total is "not reported", not a real mismatch
		if (abs($expected) < 0.005) {
			continue;
		}
		$diff = $computed - $expected;
		if (abs($diff) > $tolerance) {
			$warnings[] = array(
				'field'    => $field,
				'expected' => round($expected, 2),
				'computed' => round($computed, 2),
				'diff'     => round($diff, 2),
			);
		}
	}

	return $warnings;
}


// ============================================================
// Processed-file fingerprints (avoid spending AI credits twice)
// ============================================================

/**
 * Fingerprint a document's raw bytes.
 *
 * @param  string $content Raw file content
 * @return string          Lowercase sha256 hex digest
 */
function easyocrComputeFileHash($content)
{
	return hash('sha256', (string) $content);
}

/**
 * Whether an AI response actually carries usable structured data.
 *
 * The service answers HTTP 200 with status "success" even when the model failed
 * to produce valid JSON — in that case structured_data is {raw, parse_error}
 * instead of the document fields. Treating that as a success charges the user
 * for nothing AND, worse, fingerprints the document so the retry is refused as
 * a duplicate.
 *
 * @param  mixed $result Decoded service response
 * @return bool          True when structured data can be used
 */
function easyocrAiResultIsUsable($result)
{
	if (!is_array($result)) {
		return false;
	}
	if (!empty($result['error_code']) || !empty($result['structuring_error'])) {
		return false;
	}

	$data = isset($result['structured_data']) ? $result['structured_data'] : null;
	if (!is_array($data) || empty($data)) {
		return false;
	}
	if (isset($data['parse_error'])) {
		return false;
	}

	// A payload with none of the identifying fields is not worth showing either
	$signals = array('document_number', 'issue_date', 'supplier', 'items', 'totals');
	foreach ($signals as $key) {
		if (!empty($data[$key])) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the duplicate-document check is active.
 *
 * On by default: re-processing a document the module has already seen costs AI
 * credits for nothing. EASYOCR_DUPLICATE_CHECK=0 disables it entirely.
 *
 * @return bool
 */
function easyocrDuplicateCheckEnabled()
{
	global $conf;

	if (!is_object($conf) || !isset($conf->global->EASYOCR_DUPLICATE_CHECK)) {
		return true;
	}

	return !empty($conf->global->EASYOCR_DUPLICATE_CHECK);
}

/**
 * How far back the duplicate check looks, in days. 0 = no limit (default).
 *
 * Useful for recurring documents: a supplier whose monthly invoice is byte
 * identical would otherwise be flagged forever.
 *
 * @return int Days, or 0 for unlimited
 */
function easyocrDuplicateWindowDays()
{
	global $conf;

	if (!is_object($conf) || empty($conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS)) {
		return 0;
	}

	return max(0, (int) $conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS);
}

/**
 * Look up a previously processed document by fingerprint, in the current entity.
 *
 * @param  string $hash        sha256 digest
 * @param  bool   $applyWindow Honour EASYOCR_DUPLICATE_WINDOW_DAYS (false when
 *                             checking for an existing row before inserting)
 * @return array|null          Record data (filename, date_creation, invoice_id, invoice_ref) or null
 */
function easyocrLookupProcessedFile($hash, $applyWindow = true)
{
	global $db, $conf;

	if (empty($db) && !empty($GLOBALS['db'])) {
		$db = $GLOBALS['db'];
	}
	$hash = trim((string) $hash);
	if ($hash === '' || empty($db) || !is_object($db)) {
		return null;
	}

	$sql  = "SELECT p.rowid, p.filename, p.file_size, p.date_creation, p.fk_facture_fourn, f.ref as invoice_ref";
	$sql .= " FROM " . MAIN_DB_PREFIX . "easyocr_processed_files as p";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn as f ON f.rowid = p.fk_facture_fourn";
	$sql .= " WHERE p.file_hash = '" . $db->escape($hash) . "'";
	$sql .= " AND p.entity = " . ((int) $conf->entity);
	if ($applyWindow) {
		$windowDays = easyocrDuplicateWindowDays();
		if ($windowDays > 0) {
			// Bound computed in PHP, not with NOW(): rows are written in GMT via
			// idate(), while NOW() returns the database server's local time.
			$sql .= " AND p.date_creation >= '" . $db->idate(dol_now() - ($windowDays * 86400)) . "'";
		}
	}
	$sql .= " LIMIT 1";

	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) < 1) {
		return null;
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);

	return array(
		'id'            => (int) $obj->rowid,
		'filename'      => $obj->filename,
		'file_size'     => (int) $obj->file_size,
		'date_creation' => $db->jdate($obj->date_creation),
		'invoice_id'    => (int) $obj->fk_facture_fourn,
		'invoice_ref'   => $obj->invoice_ref,
	);
}

/**
 * Record a document fingerprint after it has been sent to the AI service.
 *
 * Idempotent: re-registering the same hash refreshes the filename instead of
 * failing on the unique index.
 *
 * @param  string $hash     sha256 digest
 * @param  string $filename Original filename
 * @param  int    $fileSize Size in bytes
 * @param  int    $userId   Author user id
 * @return int              >0 on success, <=0 on failure
 */
function easyocrRegisterProcessedFile($hash, $filename = '', $fileSize = 0, $userId = 0)
{
	global $db, $conf;

	if (empty($db) && !empty($GLOBALS['db'])) {
		$db = $GLOBALS['db'];
	}
	$hash = trim((string) $hash);
	if ($hash === '' || empty($db) || !is_object($db)) {
		return -1;
	}

	// Ignore the time window here: an existing row must be found whatever its
	// age, or the INSERT below would collide with the unique index. Refresh its
	// date so the window is measured from the LAST time we processed the file.
	$existing = easyocrLookupProcessedFile($hash, false);
	if ($existing !== null) {
		$sqlTouch  = "UPDATE " . MAIN_DB_PREFIX . "easyocr_processed_files";
		$sqlTouch .= " SET date_creation = '" . $db->idate(dol_now()) . "'";
		$sqlTouch .= " WHERE rowid = " . ((int) $existing['id']);
		$db->query($sqlTouch);

		return $existing['id'];
	}

	$sql  = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_processed_files";
	$sql .= " (entity, file_hash, filename, file_size, fk_user, date_creation)";
	$sql .= " VALUES (" . ((int) $conf->entity);
	$sql .= ", '" . $db->escape($hash) . "'";
	$sql .= ", '" . $db->escape(dol_trunc((string) $filename, 250, 'right', 'UTF-8', 1)) . "'";
	$sql .= ", " . ((int) $fileSize);
	$sql .= ", " . ((int) $userId > 0 ? (int) $userId : "NULL");
	$sql .= ", '" . $db->idate(dol_now()) . "')";

	if (!$db->query($sql)) {
		// Concurrent insert hit the unique index — treat as success
		$existing = easyocrLookupProcessedFile($hash, false);
		if ($existing !== null) {
			return $existing['id'];
		}
		dol_syslog('EasyOCR: could not register processed file hash — ' . $db->lasterror(), LOG_WARNING);
		return -1;
	}

	return (int) $db->last_insert_id(MAIN_DB_PREFIX . 'easyocr_processed_files');
}

/**
 * Attach a created supplier invoice to a document fingerprint.
 *
 * @param  string $hash      sha256 digest
 * @param  int    $invoiceId Supplier invoice id
 * @return bool              True when a row was updated
 */
function easyocrLinkProcessedFileToInvoice($hash, $invoiceId)
{
	global $db, $conf;

	if (empty($db) && !empty($GLOBALS['db'])) {
		$db = $GLOBALS['db'];
	}
	$hash = trim((string) $hash);
	if ($hash === '' || (int) $invoiceId <= 0 || empty($db) || !is_object($db)) {
		return false;
	}

	$sql  = "UPDATE " . MAIN_DB_PREFIX . "easyocr_processed_files";
	$sql .= " SET fk_facture_fourn = " . ((int) $invoiceId);
	$sql .= " WHERE file_hash = '" . $db->escape($hash) . "'";
	$sql .= " AND entity = " . ((int) $conf->entity);

	return (bool) $db->query($sql);
}


// ============================================================
// Shared invoice creation function (AJAX + Webhook)
// ============================================================

/**
 * Create a supplier invoice from OCR-extracted data.
 * Shared function used by both AJAX (newInvoiceAI) and webhook processing.
 *
 * @param  array      $params   Associative array of invoice parameters:
 *   - fk_soc            int    Supplier ID (0 = auto-detect from tax_id)
 *   - ref_supplier      string Invoice reference from supplier
 *   - datef             string Invoice date (flexible format)
 *   - total_ttc/ht/tva  string Raw total strings
 *   - total_localtax1/2 string Local tax totals (RE / IRPF)
 *   - date_echeance     string Due date
 *   - notes             string Private notes
 *   - items             mixed  JSON string or array of line items
 *   - default_tax_rate  float  Fallback tax rate for lines without tax
 *   - supplier_*        string Supplier data (name, tax_id, address, city, zip, country, phone, email)
 *   - invoice_status    string 'draft' or 'validated'
 *   - invoice_type      int    0=standard, 2=credit_note
 *   - journal_code      string Accounting journal code
 *   - import_key        string Import key tag (default: 'easyocr-ai')
 *   - create_payment    string '1' to auto-create payment
 *   - payment_bank_id   int    Bank account ID for payment
 *   - payment_type_id   int    Payment type ID
 *   - file_tmp_path     string Temp path of uploaded PDF
 *   - file_name         string Original filename of PDF
 * @param  User|null  $userObj  User object (null = auto-detect first admin)
 * @return array                Result: status, id, ref, supplier_created, supplier_name, is_draft, line_errors
 */
function easyocrCreateInvoiceFromOCR($params, $userObj = null)
{
	global $db, $conf, $langs, $mysoc;

	// Robust $db recovery — in NOLOGIN/webhook context, global may not resolve
	if (empty($db) && !empty($GLOBALS['db'])) {
		$db = $GLOBALS['db'];
	}
	if (empty($db) || !is_object($db)) {
		return ['status' => 'error', 'message' => 'Database connection not available ($db is null)'];
	}

	dol_syslog('EasyOCR-CREATE: START — $db OK (class=' . get_class($db) . ')', LOG_INFO);

	// Robust $conf/$mysoc/$langs recovery
	if (empty($conf) && !empty($GLOBALS['conf'])) $conf = $GLOBALS['conf'];
	if (empty($langs) && !empty($GLOBALS['langs'])) $langs = $GLOBALS['langs'];

	// Ensure required Dolibarr classes are loaded
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

	// $mysoc must be a valid Societe — in NOLOGIN context it may not be initialized
	if (empty($mysoc) && !empty($GLOBALS['mysoc'])) $mysoc = $GLOBALS['mysoc'];
	if (empty($mysoc) || !is_object($mysoc) || empty($mysoc->country_code)) {
		dol_syslog('EasyOCR-CREATE: $mysoc was empty/invalid, creating new instance', LOG_WARNING);
		$mysoc = new Societe($db);
		if (method_exists($mysoc, 'setMysoc') && is_object($conf)) {
			$mysoc->setMysoc($conf);
		}
	}
	dol_syslog('EasyOCR-CREATE: $mysoc country_code=' . ($mysoc->country_code ?? 'EMPTY') . ', $conf entity=' . ($conf->entity ?? '?'), LOG_INFO);
	require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
	require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
	require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
	require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
	require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

	if (is_object($langs)) {
		$langs->load('easyocr@easyocr');
	}

	// ── Resolve user ─────────────────────────────────────────────────────
	if (empty($userObj) || !is_object($userObj) || empty($userObj->id)) {
		dol_syslog('EasyOCR-CREATE: No user provided, auto-detecting admin...', LOG_INFO);
		require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
		// Pick admin from current entity (or shared), so multicompany webhooks
		// don't grab an admin from a foreign entity.
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1";
		$sql .= " AND entity IN (" . getEntity('user') . ")";
		$sql .= " ORDER BY rowid ASC LIMIT 1";
		$res = $db->query($sql);
		if (!$res || $db->num_rows($res) < 1) {
			dol_syslog('EasyOCR-CREATE: ERROR — No admin user found', LOG_ERR);
			return ['status' => 'error', 'message' => 'No admin user found for invoice creation'];
		}
		$userObj = new User($db);
		$userObj->fetch($db->fetch_object($res)->rowid);
		dol_syslog('EasyOCR-CREATE: Using admin user id=' . $userObj->id . ' login=' . $userObj->login, LOG_INFO);
	} else {
		dol_syslog('EasyOCR-CREATE: Using provided user id=' . $userObj->id, LOG_INFO);
	}

	// ── Extract parameters with defaults ─────────────────────────────────
	$fk_soc            = isset($params['fk_soc']) ? (int) $params['fk_soc'] : 0;
	$ref_supplier      = isset($params['ref_supplier']) ? trim($params['ref_supplier']) : '';
	$datef_str         = !empty($params['datef']) ? easyocrParseDate($params['datef']) : date('Y-m-d');
	$total_ttc_str     = isset($params['total_ttc']) ? $params['total_ttc'] : '0';
	$total_ht_str      = isset($params['total_ht']) ? $params['total_ht'] : '0';
	$total_tva_str     = isset($params['total_tva']) ? $params['total_tva'] : '';
	$total_localtax1   = isset($params['total_localtax1']) ? $params['total_localtax1'] : '0';
	$total_localtax2   = isset($params['total_localtax2']) ? $params['total_localtax2'] : '0';
	$date_echeance_str = isset($params['date_echeance']) ? trim($params['date_echeance']) : '';
	$notes             = isset($params['notes']) ? $params['notes'] : '';
	$default_tax_rate  = isset($params['default_tax_rate']) ? floatval($params['default_tax_rate']) : 0;
	$invoice_status    = isset($params['invoice_status']) ? $params['invoice_status'] : '';
	$invoice_type      = isset($params['invoice_type']) ? (int) $params['invoice_type'] : 0;
	$journal_code      = isset($params['journal_code']) ? trim($params['journal_code']) : '';
	// facture_fourn.import_key is varchar(14): a longer value makes the UPDATE
	// below fail outright under STRICT_TRANS_TABLES instead of being truncated.
	$import_key        = isset($params['import_key']) ? $params['import_key'] : 'easyocr-ai';
	$import_key        = dol_trunc((string) $import_key, 14, 'right', 'UTF-8', 1);

	// Supplier data
	$supplier_name    = isset($params['supplier_name']) ? trim($params['supplier_name']) : '';
	$supplier_tax_id  = isset($params['supplier_tax_id']) ? trim($params['supplier_tax_id']) : '';
	$supplier_address = isset($params['supplier_address']) ? trim($params['supplier_address']) : '';
	$supplier_city    = isset($params['supplier_city']) ? trim($params['supplier_city']) : '';
	$supplier_zip     = isset($params['supplier_zip']) ? trim($params['supplier_zip']) : '';
	$supplier_country = isset($params['supplier_country']) ? trim($params['supplier_country']) : '';
	$supplier_phone   = isset($params['supplier_phone']) ? trim($params['supplier_phone']) : '';
	$supplier_email   = isset($params['supplier_email']) ? trim($params['supplier_email']) : '';

	// Payment params
	$create_payment  = isset($params['create_payment']) ? $params['create_payment'] : '';
	$payment_bank_id = isset($params['payment_bank_id']) ? (int) $params['payment_bank_id'] : 0;
	$payment_type_id = isset($params['payment_type_id']) ? (int) $params['payment_type_id'] : 0;

	// Project link (optional) — column is fk_projet in DB, property fk_project in PHP
	$project_id      = isset($params['project_id']) ? (int) $params['project_id'] : (isset($params['fk_projet']) ? (int) $params['fk_projet'] : 0);

	// File upload params
	$file_tmp_path = isset($params['file_tmp_path']) ? $params['file_tmp_path'] : '';
	$file_name     = isset($params['file_name']) ? $params['file_name'] : '';

	// Items — accept JSON string or already-decoded array
	$items_raw = isset($params['items']) ? $params['items'] : array();
	if (is_string($items_raw)) {
		$items = json_decode($items_raw, true);
		if (!is_array($items)) $items = array();
	} else {
		$items = is_array($items_raw) ? $items_raw : array();
	}

	$supplier_created = false;
	$supplier_created_name = '';

	dol_syslog('EasyOCR-CREATE: Params — fk_soc=' . $fk_soc . ', ref_supplier=' . $ref_supplier . ', supplier_name=' . $supplier_name . ', supplier_tax_id=' . $supplier_tax_id . ', datef=' . $datef_str . ', total_ht=' . $total_ht_str . ', total_ttc=' . $total_ttc_str . ', items=' . (is_array($items) ? count($items) : 'N/A'), LOG_INFO);

	// ── Receiver-as-supplier guard ───────────────────────────────────────
	// Classic OCR failure: the model reads the invoice RECEIVER (us) as the
	// supplier. Without this check we would look up — or worse, create — our
	// own company as a supplier in llx_societe.
	// Only applies when no supplier was chosen explicitly: with fk_soc set the
	// user already decided and the OCR tax id is not used to resolve anything.
	if (empty($fk_soc) && !empty($supplier_tax_id) && empty($conf->global->EASYOCR_ALLOW_SELF_SUPPLIER)) {
		if (easyocrIsOwnCompanyTaxId($supplier_tax_id)) {
			$msg = is_object($langs) ? $langs->trans('EasyOcrSupplierIsReceiver', $supplier_tax_id) : 'The extracted supplier tax id (' . $supplier_tax_id . ') is your own company: the OCR read the invoice receiver as the supplier. Fix the supplier before creating the invoice.';
			dol_syslog('EasyOCR-CREATE: ABORT — supplier_tax_id=' . $supplier_tax_id . ' matches our own company tax ids', LOG_ERR);
			return ['status' => 'error', 'message' => $msg, 'error_code' => 'supplier_is_receiver'];
		}

		// Same id on both sides means the model duplicated one party
		$customer_tax_id = isset($params['customer_tax_id']) ? trim($params['customer_tax_id']) : '';
		if (!empty($customer_tax_id)) {
			$normSup = easyocrNormalizeTaxId($supplier_tax_id);
			$normCus = easyocrNormalizeTaxId($customer_tax_id);
			if ($normSup !== '' && $normSup === $normCus) {
				$msg = is_object($langs) ? $langs->trans('EasyOcrSupplierEqualsCustomer', $supplier_tax_id) : 'Supplier and customer share the same tax id (' . $supplier_tax_id . '): the OCR could not tell them apart. Fix the supplier before creating the invoice.';
				dol_syslog('EasyOCR-CREATE: ABORT — supplier_tax_id equals customer_tax_id (' . $supplier_tax_id . ')', LOG_ERR);
				return ['status' => 'error', 'message' => $msg, 'error_code' => 'supplier_equals_customer'];
			}
		}
	}

	// ── Advisory lock to prevent race condition on concurrent webhooks ──
	// Serializes BOTH supplier search/creation AND invoice duplicate check.
	// When a batch sends multiple webhooks simultaneously, without this lock
	// two requests could both (a) create the same supplier and/or (b) pass
	// the duplicate invoice check before either commits.
	$lockKey = '';
	if (!empty($supplier_tax_id)) {
		$lockKey = strtoupper(preg_replace('/[\s\-\.]/', '', trim($supplier_tax_id)));
	} elseif (!empty($fk_soc)) {
		$lockKey = 'soc_' . ((int) $fk_soc);
	}
	$lockName = 'eo_' . ($lockKey !== '' ? substr(md5($lockKey), 0, 30) : 'global');
	$lockTimeout = 30; // seconds
	$lockAcquired = false;
	if (!empty($lockKey)) {
		$sqlLock = "SELECT GET_LOCK('" . $db->escape($lockName) . "', " . ((int) $lockTimeout) . ")";
		$resLock = $db->query($sqlLock);
		if ($resLock) {
			$objLock = $db->fetch_array($resLock);
			if (isset($objLock[0]) && $objLock[0] == 1) {
				$lockAcquired = true;
			}
		}
		if (!$lockAcquired) {
			dol_syslog('EasyOCR-CREATE: WARNING — Could not acquire advisory lock "' . $lockName . '", proceeding without lock', LOG_WARNING);
		}
	}

	// ── Resolve supplier if fk_soc not provided ─────────────────────────
	if (empty($fk_soc) && !empty($supplier_tax_id)) {
		$cif_clean = preg_replace('/[\s\-\.]/', '', trim($supplier_tax_id));

		// 1) Search as supplier (7-field search)
		$sqlS = "SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s";
		$sqlS .= " WHERE s.fournisseur = 1 AND s.status = 1 AND s.entity IN (" . getEntity('societe') . ") AND (";
		$sqlS .= " REPLACE(REPLACE(REPLACE(s.siren,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= " OR REPLACE(REPLACE(REPLACE(s.siret,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= " OR REPLACE(REPLACE(REPLACE(s.ape,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= " OR REPLACE(REPLACE(REPLACE(s.idprof4,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= " OR REPLACE(REPLACE(REPLACE(s.idprof5,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= " OR REPLACE(REPLACE(REPLACE(s.idprof6,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= " OR REPLACE(REPLACE(REPLACE(s.tva_intra,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
		$sqlS .= ") LIMIT 1";
		$resS = $db->query($sqlS);
		if ($resS && $db->num_rows($resS) > 0) {
			$fk_soc = $db->fetch_object($resS)->rowid;
		}

		// 2) Search as non-supplier (client) and upgrade to supplier
		if (empty($fk_soc)) {
			$sqlNS = "SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s";
			$sqlNS .= " WHERE s.status = 1 AND s.entity IN (" . getEntity('societe') . ") AND (";
			$sqlNS .= " REPLACE(REPLACE(REPLACE(s.siren,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= " OR REPLACE(REPLACE(REPLACE(s.siret,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= " OR REPLACE(REPLACE(REPLACE(s.ape,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= " OR REPLACE(REPLACE(REPLACE(s.idprof4,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= " OR REPLACE(REPLACE(REPLACE(s.idprof5,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= " OR REPLACE(REPLACE(REPLACE(s.idprof6,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= " OR REPLACE(REPLACE(REPLACE(s.tva_intra,' ',''),'-',''),'.','')='" . $db->escape($cif_clean) . "'";
			$sqlNS .= ") LIMIT 1";
			$resNS = $db->query($sqlNS);
			if ($resNS && $db->num_rows($resNS) > 0) {
				$objNS = $db->fetch_object($resNS);
				$existingSoc = new Societe($db);
				$existingSoc->fetch($objNS->rowid);

				// Generate supplier code if needed
				$newCodeFournisseur = $existingSoc->code_fournisseur;
				if (empty($newCodeFournisseur) || $newCodeFournisseur == '-1') {
					$existingSoc->get_codefournisseur();
					$newCodeFournisseur = $existingSoc->code_fournisseur;
				}

				// Update only fournisseur flag and code (preserves country etc.)
				$sqlUpgrade = "UPDATE " . MAIN_DB_PREFIX . "societe SET fournisseur = 1";
				if (!empty($newCodeFournisseur) && $newCodeFournisseur != '-1') {
					$sqlUpgrade .= ", code_fournisseur = '" . $db->escape($newCodeFournisseur) . "'";
				}
				$sqlUpgrade .= " WHERE rowid = " . ((int) $objNS->rowid);
				$db->query($sqlUpgrade);

				$fk_soc = $existingSoc->id;
			}
		}

		// 3) Create new supplier
		if (empty($fk_soc) && !empty($supplier_name)) {
			// Pre-analyze items to detect localtax (RE/IRPF) requirements
			$has_recargo = false;
			$has_irpf = false;
			$irpf_value = 0;

			if (is_array($items)) {
				foreach ($items as $item) {
					if (!empty($item['taxes']) && is_array($item['taxes'])) {
						foreach ($item['taxes'] as $tax) {
							$taxType = strtolower($tax['tax_type'] ?? '');
							$taxRate = floatval($tax['tax_rate'] ?? 0);
							if (in_array($taxType, ['re', 'recargo', 'recargo_equivalencia'])) {
								$has_recargo = true;
							}
							if (in_array($taxType, ['irpf', 'retencion', 'withholding'])) {
								$has_irpf = true;
								$irpf_value = $taxRate;
							}
						}
					}
				}
			}

			$newSoc = new Societe($db);
			$newSoc->name        = $supplier_name;
			$newSoc->client      = 0;
			$newSoc->fournisseur = 1;
			$newSoc->status      = 1;
			$newSoc->idprof1     = $supplier_tax_id;

			$cifUpper = strtoupper($cif_clean);
			if (preg_match('/^[A-Z]{2}/', $cifUpper)) {
				$newSoc->tva_intra    = $supplier_tax_id;
				$newSoc->country_code = substr($cifUpper, 0, 2);
			}

			if (!empty($supplier_address)) $newSoc->address = $supplier_address;
			if (!empty($supplier_city))    $newSoc->town    = $supplier_city;
			if (!empty($supplier_zip))     $newSoc->zip     = $supplier_zip;
			if (!empty($supplier_phone))   $newSoc->phone   = $supplier_phone;
			if (!empty($supplier_email))   $newSoc->email   = $supplier_email;

			// Configure localtax based on detected taxes in invoice
			if ($has_recargo) {
				$newSoc->localtax1_assuj = 1;
				$newSoc->localtax1_value = 0; // Let Dolibarr calculate from tax tables
			}
			if ($has_irpf && $irpf_value > 0) {
				$newSoc->localtax2_assuj = 1;
				$newSoc->localtax2_value = -abs($irpf_value); // Negative for IRPF
			}

			// Resolve country
			if (!empty($supplier_country)) {
				$cc = trim($supplier_country);
				$sqlC = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE (code='" . $db->escape(strtoupper(substr($cc, 0, 2))) . "' OR label LIKE '" . $db->escape($cc) . "%') AND active=1 LIMIT 1";
				$resC = $db->query($sqlC);
				if ($resC && $db->num_rows($resC) > 0) $newSoc->country_id = $db->fetch_object($resC)->rowid;
			} elseif (!empty($newSoc->country_code)) {
				$sqlCC = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code='" . $db->escape($newSoc->country_code) . "' AND active=1 LIMIT 1";
				$resCC = $db->query($sqlCC);
				if ($resCC && $db->num_rows($resCC) > 0) $newSoc->country_id = $db->fetch_object($resCC)->rowid;
			}

			$newSoc->get_codefournisseur();

			$createdId = $newSoc->create($userObj);
			if ($createdId > 0) {
				$fk_soc = $createdId;
				$supplier_created = true;
				$supplier_created_name = $newSoc->name;
			} else {
				$errorDetails = [];
				$errorDetails[] = "Main error: " . ($newSoc->error ?: 'Unknown error');
				if (!empty($newSoc->errors)) {
					$errorDetails[] = "Additional: " . implode(', ', $newSoc->errors);
				}
				$errorDetails[] = "Name: '" . ($newSoc->name ?: 'N/A') . "'";
				$errorDetails[] = "CIF: '" . ($supplier_tax_id ?: 'N/A') . "'";
				if (!empty($db->lasterror())) {
					$errorDetails[] = "DB: " . $db->lasterror();
				}
				if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
				return ['status' => 'error', 'message' => 'Error creating supplier. ' . implode(' | ', $errorDetails)];
			}
		}
	}

	// Still no supplier?
	if (empty($fk_soc)) {
		$msg = is_object($langs) ? $langs->trans('EasyOcrAISupplierRequired') : 'Supplier required';
		dol_syslog('EasyOCR-CREATE: ERROR — No supplier resolved. tax_id=' . $supplier_tax_id . ', name=' . $supplier_name, LOG_ERR);
		if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
		return ['status' => 'error', 'message' => $msg];
	}
	dol_syslog('EasyOCR-CREATE: Supplier resolved — fk_soc=' . $fk_soc . ', created=' . ($supplier_created ? 'YES' : 'NO'), LOG_INFO);

	// ── Parse totals ─────────────────────────────────────────────────────
	$total_ht  = easyocrParseNumber($total_ht_str);
	$total_ttc = easyocrParseNumber($total_ttc_str);
	$total_tva = !empty($total_tva_str) ? easyocrParseNumber($total_tva_str) : ($total_ttc - $total_ht);

	// ── Duplicate check ──────────────────────────────────────────────────
	// 1) Primary: by ref_supplier + supplier (normalized: trimmed, case-insensitive)
	if (!empty($ref_supplier)) {
		$ref_clean_check = trim($ref_supplier);
		$sql_check = "SELECT rowid, ref, ref_supplier FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql_check .= " WHERE UPPER(TRIM(ref_supplier)) = UPPER('" . $db->escape($ref_clean_check) . "')";
		$sql_check .= " AND fk_soc = " . ((int) $fk_soc);
		$sql_check .= " AND entity IN (" . getEntity('supplier_invoice') . ")";
		$resql_check = $db->query($sql_check);
		if ($resql_check && $db->num_rows($resql_check) > 0) {
			$existingObj = $db->fetch_object($resql_check);
			$msg = is_object($langs) ? $langs->trans('EasyOcrDuplicateRefSupplier', $ref_supplier, $existingObj->ref) : 'Duplicate ref_supplier: ' . $ref_supplier . ' (existing: ' . $existingObj->ref . ')';
			dol_syslog('EasyOCR-CREATE: DUPLICATE ref_supplier=' . $ref_supplier . ' for fk_soc=' . $fk_soc . ' => existing id=' . $existingObj->rowid . ' ref=' . $existingObj->ref, LOG_WARNING);
			if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
			return [
				'status' => 'repeat',
				'message' => $msg,
				'existing_id' => $existingObj->rowid,
				'existing_ref' => $existingObj->ref,
				'existing_ref_supplier' => $existingObj->ref_supplier,
				// Aliases for webhook compatibility
				'invoice_id' => $existingObj->rowid,
				'invoice_ref' => $existingObj->ref,
				'supplier_id' => $fk_soc,
			];
		}
	}
	// 2) Secondary: when ref_supplier is empty, check by amount + date + supplier
	//    to prevent duplicate invoices from webhook retries or re-uploads
	if (empty($ref_supplier) && $total_ttc != 0) {
		$sql_dup2 = "SELECT rowid, ref, ref_supplier FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql_dup2 .= " WHERE fk_soc = " . ((int) $fk_soc);
		$sql_dup2 .= " AND total_ttc = " . ((float) $total_ttc);
		$sql_dup2 .= " AND datef = '" . $db->escape($datef_str) . "'";
		$sql_dup2 .= " AND import_key IN ('easyocr-ai', 'easyocr-wh')";
		$sql_dup2 .= " AND entity IN (" . getEntity('supplier_invoice') . ")";
		$resql_dup2 = $db->query($sql_dup2);
		if ($resql_dup2 && $db->num_rows($resql_dup2) > 0) {
			$existingObj2 = $db->fetch_object($resql_dup2);
			$msg = is_object($langs) ? $langs->trans('EasyOcrDuplicateAmountDate', $existingObj2->ref) : 'Probable duplicate (same supplier + amount + date): ' . $existingObj2->ref;
			dol_syslog('EasyOCR-CREATE: PROBABLE DUPLICATE by amount+date — fk_soc=' . $fk_soc . ', total_ttc=' . $total_ttc . ', datef=' . $datef_str . ' => existing id=' . $existingObj2->rowid, LOG_WARNING);
			if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
			return [
				'status' => 'repeat',
				'message' => $msg,
				'existing_id' => $existingObj2->rowid,
				'existing_ref' => $existingObj2->ref,
				'existing_ref_supplier' => $existingObj2->ref_supplier,
				'invoice_id' => $existingObj2->rowid,
				'invoice_ref' => $existingObj2->ref,
				'supplier_id' => $fk_soc,
			];
		}
	}

	// ── Load supplier object (payment info + localtax calc) ──────────────
	$socTmp = new Societe($db);
	$socTmp->fetch($fk_soc);

	// Payment defaults: supplier record first, then the most frequent values of
	// its recent invoices (the supplier record is usually left empty).
	$supplier_payment_mode = 0;
	$supplier_payment_cond = 0;
	$supplier_payment_account = 0;
	if (!empty($socTmp->id)) {
		$paymentDefaults = easyocrGetSupplierPaymentDefaults($fk_soc);
		$supplier_payment_mode    = (int) $paymentDefaults['mode_reglement_id'];
		$supplier_payment_cond    = (int) $paymentDefaults['cond_reglement_id'];
		$supplier_payment_account = (int) $paymentDefaults['fk_account'];
		dol_syslog('EasyOCR-CREATE: Payment defaults (' . $paymentDefaults['source'] . ') — cond=' . $supplier_payment_cond . ', mode=' . $supplier_payment_mode . ', account=' . $supplier_payment_account, LOG_DEBUG);
	}

	// ── Create invoice ───────────────────────────────────────────────────
	$facture = new FactureFournisseur($db);
	$facture->socid = $fk_soc;
	$facture->ref_supplier = $ref_supplier;
	$facture->type = (!empty($invoice_type) && in_array((int) $invoice_type, [0, 2, 3, 5])) ? (int) $invoice_type : 0;
	$facture->date = dol_mktime(
		12, 0, 0,
		date('m', strtotime($datef_str)),
		date('d', strtotime($datef_str)),
		date('Y', strtotime($datef_str))
	);
	$facture->multicurrency_code = $conf->currency;
	$facture->special_code = 0;
	$facture->import_key = $import_key;
	if ($project_id > 0) {
		$facture->fk_project = $project_id;
	}

	if ($supplier_payment_mode > 0) {
		$facture->mode_reglement_id = $supplier_payment_mode;
	}
	if ($supplier_payment_cond > 0) {
		$facture->cond_reglement_id = $supplier_payment_cond;
	}
	if (!empty($notes)) {
		$facture->note_private = $notes;
	}
	if (!empty($date_echeance_str)) {
		$date_ech = easyocrParseDate($date_echeance_str);
		$facture->date_echeance = dol_mktime(
			12, 0, 0,
			date('m', strtotime($date_ech)),
			date('d', strtotime($date_ech)),
			date('Y', strtotime($date_ech))
		);
	}

	dol_syslog('EasyOCR-CREATE: Creating invoice — socid=' . $facture->socid . ', ref_supplier=' . $facture->ref_supplier . ', date=' . date('Y-m-d', $facture->date) . ', type=' . $facture->type, LOG_INFO);
	$newId = $facture->create($userObj);
	if ($newId <= 0) {
		$msg = is_object($langs) ? $langs->trans('EasyOcrErrorCreatingInvoice') : 'Error creating invoice';
		dol_syslog('EasyOCR-CREATE: ERROR creating invoice: ' . $facture->error . ' | errors: ' . implode(', ', $facture->errors ?? []), LOG_ERR);
		if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
		return ['status' => 'error', 'message' => $msg . ': ' . $facture->error];
	}
	dol_syslog('EasyOCR-CREATE: Invoice created OK — id=' . $newId, LOG_INFO);

	// Set import_key and journal code
	$sql_upd = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET import_key = '" . $db->escape($import_key) . "'";
	if (!empty($journal_code)) {
		$sql_upd .= ", fk_account = (SELECT rowid FROM " . MAIN_DB_PREFIX . "accounting_journal WHERE code = '" . $db->escape($journal_code) . "' AND entity = " . ((int) $conf->entity) . " LIMIT 1)";
	}
	$sql_upd .= " WHERE rowid = " . ((int) $newId);
	if (!$db->query($sql_upd)) {
		// Not fatal, but it used to fail silently and lose the origin tag
		dol_syslog('EasyOCR-CREATE: could not set import_key/journal — ' . $db->lasterror(), LOG_WARNING);
	}

	// ── Add lines — full tax support (IVA/TVA, RE, IRPF) + product matching ─
	$lineErrors = array();
	if (!empty($items)) {
		$lineIndex = 0;
		foreach ($items as $item) {
			$lineIndex++;
			$desc = !empty($item['description']) ? $item['description'] : 'Línea';
			// easyocrParseNumber, not floatval: a hand-edited "3,5" would become 3
			$qty = !empty($item['quantity']) ? easyocrParseNumber($item['quantity']) : 1;
			if ($qty == 0) {
				$qty = 1;
			}
			$unit_price = isset($item['unit_price']) && $item['unit_price'] !== '' ? easyocrParseNumber($item['unit_price']) : 0;
			// Discount cascade: explicit % -> absolute amount -> implicit gap between
			// qty*unit_price and net_amount. Resolved against the unit price as printed
			// on the document, before any reconstruction below.
			$discount = easyocrResolveLineDiscount($item, $qty, $unit_price);
			$itemType = isset($item['item_type']) ? strtolower(trim($item['item_type'])) : '';
			// Réf. produit fournisseur (CODE OCR): capturar SIEMPRE, antes del gate de producto,
			// para conservarla también en líneas service/discount/surcharge/other. Se persiste abajo en addline().
			$lineRef = isset($item['code']) ? trim((string) $item['code']) : '';

			// Tax handling — parse IVA rate from AI data
			$tva_rate = 0;
			if (!empty($item['taxes']) && is_array($item['taxes'])) {
				foreach ($item['taxes'] as $tax) {
					$taxType = strtolower($tax['tax_type'] ?? '');
					$taxRate = floatval($tax['tax_rate'] ?? 0);
					if (in_array($taxType, ['tva', 'iva', 'vat'])) {
						$tva_rate = $taxRate;
					}
				}
			}

			// Fallback: flat tax_rate field
			if ($tva_rate == 0 && !empty($item['tax_rate'])) {
				$tva_rate = floatval($item['tax_rate']);
			}
			// Final fallback: default tax rate
			if ($tva_rate == 0 && $default_tax_rate > 0) {
				$tva_rate = $default_tax_rate;
				dol_syslog("EasyOCR: Line #$lineIndex using default_tax_rate=$default_tax_rate (line had empty taxes)", LOG_DEBUG);
			}

			// Resolve localtax from Dolibarr tax tables (RE / IRPF based on fiscal regime)
			$localtax1_rate = get_localtax($tva_rate, 1, $mysoc, $socTmp);
			$localtax2_rate = get_localtax($tva_rate, 2, $mysoc, $socTmp);

			// Reconstruct the gross unit price when the document did not print one
			$unit_price = easyocrResolveLineUnitPrice($item, $qty, $discount, $unit_price);

			// Product matching — skip for discount/surcharge/other types.
			// The OCR "code" is the SUPPLIER's article reference, so fk_product is resolved in order:
			//   1) supplier ref (product_fournisseur_price.ref_fourn) for this supplier -> links the EXISTING product
			//   2) internal product ref / barcode                                       -> in case the code is the internal ref
			//   3) auto-create product                                                  -> opt-in only (EASYOCR_AI_AUTOCREATE_PRODUCT), OFF by default
			$fk_product = 0;
			$skipProductMatch = in_array($itemType, ['discount', 'surcharge', 'other', '']);

			// A product picked by hand in the review screen wins over any lookup:
			// the user has seen the document and the match, we have not.
			if (!empty($item['fk_product']) && (int) $item['fk_product'] > 0) {
				$explicitProductId = (int) $item['fk_product'];
				$sqlChk = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
				$sqlChk .= " WHERE rowid = " . $explicitProductId;
				$sqlChk .= " AND entity IN (" . getEntity('product') . ") LIMIT 1";
				$resChk = $db->query($sqlChk);
				if ($resChk && $db->num_rows($resChk) > 0) {
					$fk_product = $explicitProductId;
					$skipProductMatch = true;
					dol_syslog("EasyOCR: line #$lineIndex uses user-selected fk_product=$fk_product", LOG_DEBUG);
				} else {
					dol_syslog("EasyOCR: line #$lineIndex ignored out-of-entity fk_product=$explicitProductId", LOG_WARNING);
				}
			}

			if (!$skipProductMatch && $lineRef !== '') {
				// 1) PRIMARY: supplier reference (ref_fourn) registered for this supplier
				$sqlPfp = "SELECT fk_product FROM " . MAIN_DB_PREFIX . "product_fournisseur_price";
				$sqlPfp .= " WHERE fk_soc = " . ((int) $fk_soc);
				$sqlPfp .= " AND ref_fourn = '" . $db->escape($lineRef) . "'";
				$sqlPfp .= " AND entity IN (" . getEntity('product') . ") LIMIT 1";
				$resPfp = $db->query($sqlPfp);
				if ($resPfp && $db->num_rows($resPfp) > 0) {
					$fk_product = (int) $db->fetch_object($resPfp)->fk_product;
					dol_syslog("EasyOCR: line #$lineIndex matched fk_product=$fk_product by ref_fourn='$lineRef' (fk_soc=$fk_soc)", LOG_DEBUG);
				}

				// 2) FALLBACK: internal product ref / barcode (code may be the internal ref)
				if ($fk_product == 0) {
					$sqlProd = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
					$sqlProd .= " WHERE (ref = '" . $db->escape($lineRef) . "'";
					$sqlProd .= " OR barcode = '" . $db->escape($lineRef) . "')";
					$sqlProd .= " AND entity IN (" . getEntity('product') . ") LIMIT 1";
					$resProd = $db->query($sqlProd);
					if ($resProd && $db->num_rows($resProd) > 0) {
						$fk_product = (int) $db->fetch_object($resProd)->rowid;
					}
				}

				// 3) AUTO-CREATE (opt-in, OFF by default): only when no existing product matched
				if ($fk_product == 0 && !empty($conf->global->EASYOCR_AI_AUTOCREATE_PRODUCT)) {
					$newProduct = new Product($db);
					$newProduct->ref = $lineRef;
					$newProduct->label = $desc;
					$newProduct->status = 1;        // On sale
					$newProduct->status_buy = 1;     // On purchase
					$newProduct->type = 0;           // Product
					if (in_array($itemType, ['service', 'shipping', 'fee'])) {
						$newProduct->type = 1;       // Service
					}
					$newProduct->price = abs($unit_price);
					$newProduct->price_base_type = 'HT';
					$newProduct->tva_tx = $tva_rate;
					$newProduct->localtax1_tx = $localtax1_rate;
					$newProduct->localtax2_tx = $localtax2_rate;
					$prodId = $newProduct->create($userObj);
					if ($prodId > 0) {
						$fk_product = $prodId;
					} else {
						dol_syslog("EasyOCR: product auto-create failed for code='$lineRef' (line #$lineIndex): " . $newProduct->error, LOG_WARNING);
					}
				}
			}

			// Determine line type: 0=product, 1=service
			$line_type = 0;
			if (in_array($itemType, ['service', 'shipping', 'fee', 'surcharge', 'discount'])) {
				$line_type = 1;
			}

			dol_syslog("EasyOCR addline #$lineIndex: ref=$lineRef, desc=$desc, pu=$unit_price, tva=$tva_rate, ltx1=$localtax1_rate, ltx2=$localtax2_rate, qty=$qty, fk_prod=$fk_product, disc=$discount, type=$line_type", LOG_DEBUG);

			$addLineResult = $facture->addline(
				$desc,              // description
				$unit_price,         // pu (unit price HT)
				$tva_rate,           // txtva
				$localtax1_rate,     // txlocaltax1 (RE)
				$localtax2_rate,     // txlocaltax2 (IRPF)
				$qty,                // qty
				$fk_product,         // fk_product
				$discount,           // remise_percent
				'',                  // date_start
				'',                  // date_end
				0,                   // ventil
				'',                  // info_bits
				'HT',               // price_base_type
				$line_type,          // 14 type (0=product, 1=service)
				-1,                  // 15 rang
				false,               // 16 notrigger
				array(),             // 17 array_options (is_array()-guarded en core v14-v23 -> seguro)
				null,                // 18 fk_unit
				0,                   // 19 origin_id
				0,                   // 20 pu_devise (pu_ht_devise en v10/v14; posicional, valor sin cambio)
				$lineRef             // 21 ref_supplier -> llx_facture_fourn_det.ref ("Réf. produit fournisseur")
			);

			if ($addLineResult < 0) {
				dol_syslog("EasyOCR addline #$lineIndex FAILED: " . $facture->error, LOG_ERR);
				$lineErrors[] = "Line $lineIndex ($desc): " . $facture->error;
			}
		}
	} else {
		// Fallback: single line with totals
		$tva_tx = easyocrCalcTaxRate($total_ht, $total_tva);
		$localtax1_tx = get_localtax($tva_tx, 1, $mysoc, $socTmp);
		$localtax2_tx = get_localtax($tva_tx, 2, $mysoc, $socTmp);
		$lineDesc = is_object($langs) ? $langs->trans('EasyOcrInvoiceLineDesc') : 'Invoice total';
		$facture->addline(
			$lineDesc, $total_ht, $tva_tx,
			$localtax1_tx, $localtax2_tx,
			1, 0, 0, '', '', 0, '', 'HT', 0
		);
	}

	// ── Override totals with OCR values (before validation) ──────────────
	$ocr_total_ht  = $total_ht;
	$ocr_total_tva = $total_tva;
	$ocr_total_ttc = $total_ttc;
	$ocr_localtax1 = easyocrParseNumber($total_localtax1);
	$ocr_localtax2 = easyocrParseNumber($total_localtax2);

	$sql_totals = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET";
	$sql_totals .= " total_ht = " . ((float) $ocr_total_ht);
	$sql_totals .= ", tva = " . ((float) $ocr_total_tva);
	$sql_totals .= ", total_ttc = " . ((float) $ocr_total_ttc);
	if ($ocr_localtax1 != 0) {
		$sql_totals .= ", localtax1 = " . ((float) $ocr_localtax1);
	}
	if ($ocr_localtax2 != 0) {
		$sql_totals .= ", localtax2 = " . ((float) -abs($ocr_localtax2));
	}
	$sql_totals .= " WHERE rowid = " . ((int) $newId);
	$db->query($sql_totals);

	dol_syslog("EasyOCR: Updated invoice totals - HT: $ocr_total_ht, TVA: $ocr_total_tva, TTC: $ocr_total_ttc, LTX1: $ocr_localtax1, LTX2: $ocr_localtax2", LOG_DEBUG);

	// ── Validate or leave as draft ───────────────────────────────────────
	$ref = '(PROV' . $newId . ')';
	if (empty($invoice_status)) {
		$invoice_status = !empty($conf->global->EASYOCR_INVOICE_DRAFT) ? 'draft' : 'validated';
	}
	dol_syslog('EasyOCR-CREATE: Invoice status target=' . $invoice_status . ', EASYOCR_INVOICE_DRAFT=' . ($conf->global->EASYOCR_INVOICE_DRAFT ?? 'NOT_SET'), LOG_INFO);
	if ($invoice_status !== 'draft') {
		$result = $facture->validate($userObj);
		if ($result <= 0) {
			$msg = is_object($langs) ? $langs->trans('EasyOcrErrorValidating') : 'Error validating';
			$errMsg = $msg . ': ' . $facture->error;
			if (!empty($lineErrors)) {
				$errMsg .= ' | Line errors: ' . implode('; ', $lineErrors);
			}
			dol_syslog('EasyOCR-CREATE: ERROR validating: ' . $errMsg, LOG_ERR);
			if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
			return ['status' => 'error', 'message' => $errMsg];
		}
		$facture->fetch($newId);
		$ref = $facture->ref;
	} else {
		$facture->fetch($newId);
	}

	// ── Attach PDF to invoice ────────────────────────────────────────────
	if (!empty($file_tmp_path) && file_exists($file_tmp_path)) {
		$ref_clean = dol_sanitizeFileName($ref);
		$reldir = 'fournisseur/facture/' . get_exdir($newId, 2, 0, 0, $facture, 'invoice_supplier') . $ref_clean;
		$destDir = DOL_DATA_ROOT . '/' . $reldir;

		if (!@is_dir($destDir)) {
			@mkdir($destDir, 0755, true); // Native mkdir avoids dol_mkdir open_basedir issue
		}

		$fileName = dol_sanitizeFileName(basename($file_name));
		// Only prefix with ref when invoice is validated (clean ref).
		// Draft refs like (PROVx) contain parentheses that cause URL issues
		// and Dolibarr renames files starting with the old ref on validation anyway.
		if ($invoice_status !== 'draft') {
			$destFileName = $ref_clean . '-' . $fileName;
		} else {
			$destFileName = $fileName;
		}
		$destFullPath = $destDir . '/' . $destFileName;

		// dol_move for HTTP context, copy() as fallback for webhook / CLI
		$fileMoved = @dol_move($file_tmp_path, $destFullPath, 0, 1, 0, 0);
		if (!$fileMoved) {
			$fileMoved = @copy($file_tmp_path, $destFullPath);
		}
		if ($fileMoved) {
			$ecmfile = new EcmFiles($db);
			$ecmfile->filepath = $reldir;
			$ecmfile->filename = $destFileName;
			$ecmfile->fullpath_orig = $fileName;
			$ecmfile->gen_or_uploaded = 'uploaded';
			$ecmfile->src_object_type = 'supplier_invoice';
			$ecmfile->src_object_id = $newId;
			$ecmfile->fk_user_c = $userObj->id;
			$ecmfile->create($userObj);
		}
	}

	// ── Create payment ───────────────────────────────────────────────────
	// No explicit bank account: reuse the one this supplier's recent invoices
	// were paid into, so the webhook does not need it configured up front.
	if ($create_payment == '1' && $payment_bank_id <= 0 && $supplier_payment_account > 0) {
		$payment_bank_id = $supplier_payment_account;
		dol_syslog('EasyOCR-CREATE: payment_bank_id defaulted to ' . $payment_bank_id . ' from supplier payment history', LOG_INFO);
	}
	if ($create_payment == '1' && $payment_bank_id > 0 && $invoice_status !== 'draft') {
		if ($payment_type_id <= 0) $payment_type_id = 6;
		$paymentAmount = $facture->total_ttc;

		$paiement = new PaiementFourn($db);
		$paiement->datepaye = $facture->date;
		$paiement->amounts = array($newId => $paymentAmount);
		$paiement->multicurrency_amounts = array($newId => $paymentAmount);
		$paiement->multicurrency_code = array($newId => $conf->currency);
		$paiement->multicurrency_tx = array($newId => 1);
		$paiement->paiementid = $payment_type_id;
		$paiement->num_payment = $ref_supplier;
		$paiement->note_private = is_object($langs) ? $langs->trans('EasyOcrPaymentAutoNote') : 'Auto-payment by EasyOCR';
		$paiement->fk_account = $payment_bank_id;

		$paiement_id = $paiement->create($userObj, 1);
		if ($paiement_id > 0) {
			$paiement->addPaymentToBank($userObj, 'payment_supplier', '(SupplierInvoicePayment)', $payment_bank_id, '', '');
		}
	}

	// ── Release advisory lock ────────────────────────────────────────────
	if ($lockAcquired) $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");

	// ── Totals consistency ───────────────────────────────────────────────
	// Document totals are forced by SQL above, so a misread line stays hidden:
	// Dolibarr would show correct totals over incorrect lines. Report the gap.
	$totalsWarnings = easyocrCheckTotalsConsistency(
		$items,
		array('total_ht' => $total_ht, 'total_tva' => $total_tva)
	);
	foreach ($totalsWarnings as $tw) {
		dol_syslog('EasyOCR-CREATE: TOTALS MISMATCH on invoice ' . $ref . ' — ' . $tw['field'] . ': lines=' . $tw['computed'] . ', document=' . $tw['expected'] . ', diff=' . $tw['diff'], LOG_WARNING);
	}

	// ── Return result ────────────────────────────────────────────────────
	dol_syslog('EasyOCR-CREATE: SUCCESS — id=' . $newId . ', ref=' . $ref . ', fk_soc=' . $fk_soc . ', draft=' . ($invoice_status === 'draft' ? 'YES' : 'NO') . ', line_errors=' . count($lineErrors) . ', totals_warnings=' . count($totalsWarnings), LOG_INFO);
	return [
		'status'           => 'ok',
		'id'               => $newId,
		'ref'              => $ref,
		'supplier_id'      => $fk_soc,
		'supplier_created' => $supplier_created,
		'supplier_name'    => $supplier_created_name,
		'is_draft'         => ($invoice_status === 'draft'),
		'line_errors'      => $lineErrors,
		'totals_warnings'  => $totalsWarnings,
		// Aliases for webhook compatibility
		'invoice_id'       => $newId,
		'invoice_ref'      => $ref,
	];
}

/**
 * Create a Dolibarr expense report (nota de gastos) from OCR/AI structured data.
 *
 * Mirror of easyocrCreateInvoiceFromOCR() but targeting the native ExpenseReport
 * object — the correct model when an EMPLOYEE pays a receipt out of pocket and
 * expects reimbursement. Used by the mobile scan-expense view (AI-only feature).
 *
 * The receipt is a single line: qty=1 with the paid total as unit price. Dolibarr's
 * addline() uses a TTC base, so it derives HT/VAT internally from total + rate.
 *
 * Expected $params (receipt-oriented subset):
 *   - datef            string Ticket date (Y-m-d or flexible); defaults to today
 *   - total_ttc        string Total paid (TTC) — the line unit price
 *   - vat_rate         float  VAT rate for the line (0 if unknown)
 *   - merchant         string Merchant name / description (line comment)
 *   - fk_c_type_fees   int    Expense type (dictionary c_type_fees); 0 if unknown
 *   - project_id       int    Project to link the line to (optional)
 *   - validate         bool   Validate the report after creation (else left Draft)
 *   - file_tmp_path    string Temp path of the receipt photo
 *   - file_name        string Original filename of the photo
 * @param  array      $params
 * @param  User|null  $userObj  The employee the expense is for (defaults to logged user)
 * @return array                status, id, ref, is_draft, expense_id, expense_ref
 */
function easyocrCreateExpenseFromOCR($params, $userObj = null)
{
	global $db, $conf, $langs, $user;

	if (empty($db) && !empty($GLOBALS['db'])) $db = $GLOBALS['db'];
	if (empty($db) || !is_object($db)) {
		return ['status' => 'error', 'message' => 'Database connection not available ($db is null)'];
	}
	if (empty($userObj) || !is_object($userObj)) {
		$userObj = (isset($user) && is_object($user) && !empty($user->id)) ? $user : null;
	}
	if (empty($userObj) || empty($userObj->id)) {
		return ['status' => 'error', 'message' => 'No user context for expense report'];
	}
	if (empty($conf->expensereport->enabled)) {
		return ['status' => 'error', 'message' => 'ExpenseReport module not enabled'];
	}

	require_once DOL_DOCUMENT_ROOT . '/expensereport/class/expensereport.class.php';
	require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

	// ExpenseReportLine::addline() uses the global $mysoc as the "seller" for the VAT
	// calc. In the AJAX context $mysoc can be empty → PHP "Creating default object from
	// empty value" warning that also pollutes the JSON response. Ensure it's a real object.
	global $mysoc;
	if (empty($mysoc) || !is_object($mysoc)) {
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
		$mysoc = new Societe($db);
		$mysoc->setMysoc($conf);
	}

	// ── Parse inputs with the shared helpers ─────────────────────────────
	$datef_str  = isset($params['datef']) ? trim($params['datef']) : '';
	$dateParsed = !empty($datef_str) ? easyocrParseDate($datef_str) : date('Y-m-d');
	$ticketTs   = dol_mktime(12, 0, 0, (int) date('m', strtotime($dateParsed)), (int) date('d', strtotime($dateParsed)), (int) date('Y', strtotime($dateParsed)));

	$total_ttc  = isset($params['total_ttc']) ? easyocrParseNumber($params['total_ttc']) : 0;
	$vat_rate   = isset($params['vat_rate']) ? (float) $params['vat_rate'] : 0;
	$merchant   = isset($params['merchant']) ? trim($params['merchant']) : '';
	$comments   = $merchant !== '' ? $merchant : (is_object($langs) ? $langs->trans('EasyOcrExpenseDefaultComment') : 'Gasto');
	$fk_type    = isset($params['fk_c_type_fees']) ? (int) $params['fk_c_type_fees'] : 0;
	$project_id = isset($params['project_id']) ? (int) $params['project_id'] : 0;
	$doValidate = !empty($params['validate']);

	if ($total_ttc <= 0) {
		return ['status' => 'error', 'message' => 'Invalid or missing total amount'];
	}

	$db->begin();

	$expense = new ExpenseReport($db);
	$expense->fk_user_author = $userObj->id;   // the employee the report is FOR (see class note)
	$expense->date_debut     = $ticketTs;
	$expense->date_fin       = $ticketTs;
	$expense->note_private   = 'EasyOCR scan-expense' . ($merchant !== '' ? ' — ' . $merchant : '');

	$newId = $expense->create($userObj);
	if ($newId <= 0) {
		$db->rollback();
		return ['status' => 'error', 'message' => 'Error creating expense report: ' . $expense->error];
	}
	// addline() requires the in-memory status to be DRAFT (create() leaves it unset)
	$expense->status = ExpenseReport::STATUS_DRAFT;

	// ── Attach the receipt photo (ECM) and link it to the line ───────────
	$fk_ecm_files  = 0;
	$file_tmp_path = isset($params['file_tmp_path']) ? $params['file_tmp_path'] : '';
	$file_name     = isset($params['file_name']) ? $params['file_name'] : '';
	if (!empty($file_tmp_path) && file_exists($file_tmp_path)) {
		$ref_clean = dol_sanitizeFileName($expense->ref);
		$reldir    = 'expensereport/' . $ref_clean;
		$destDir   = DOL_DATA_ROOT . '/' . $reldir;
		if (!@is_dir($destDir)) @mkdir($destDir, 0755, true); // native mkdir avoids open_basedir issue
		$fileName     = dol_sanitizeFileName(basename($file_name !== '' ? $file_name : 'receipt.jpg'));
		$destFullPath = $destDir . '/' . $fileName;
		$fileMoved = @dol_move($file_tmp_path, $destFullPath, 0, 1, 0, 0);
		if (!$fileMoved) $fileMoved = @copy($file_tmp_path, $destFullPath);
		if ($fileMoved) {
			$ecmfile = new EcmFiles($db);
			$ecmfile->filepath        = $reldir;
			$ecmfile->filename        = $fileName;
			$ecmfile->fullpath_orig   = $fileName;
			$ecmfile->gen_or_uploaded = 'uploaded';
			$ecmfile->src_object_type = 'expensereport';
			$ecmfile->src_object_id   = $newId;
			$ecmfile->fk_user_c       = $userObj->id;
			$resEcm = $ecmfile->create($userObj);
			if ($resEcm > 0) $fk_ecm_files = $ecmfile->id;
			else dol_syslog('EasyOCR-EXPENSE: ECM create failed: ' . $ecmfile->error, LOG_WARNING);
		}
	}

	// ── Add the single expense line (TTC base -> HT/VAT derived) ─────────
	$lineRes = $expense->addline(
		1,             // qty
		$total_ttc,    // unit price (addline uses a TTC base)
		$fk_type,      // fk_c_type_fees
		$vat_rate,     // vatrate
		$ticketTs,     // date
		$comments,     // comments
		$project_id,   // fk_project
		0,             // fk_c_exp_tax_cat
		0,             // type
		$fk_ecm_files  // fk_ecm_files (receipt photo)
	);
	if ($lineRes <= 0) {
		$db->rollback();
		return ['status' => 'error', 'message' => 'Error adding expense line: ' . $expense->error];
	}

	$db->commit();

	// ── Optionally validate (only if the setting allows it) ──────────────
	$isDraft = true;
	if ($doValidate) {
		$vres = $expense->setValidate($userObj);
		if ($vres > 0) {
			$isDraft = false;
		} else {
			dol_syslog('EasyOCR-EXPENSE: setValidate failed, left as draft: ' . $expense->error, LOG_WARNING);
		}
	}

	dol_syslog('EasyOCR-EXPENSE: created id=' . $newId . ', ref=' . $expense->ref . ', user=' . $userObj->id . ', ttc=' . $total_ttc . ', draft=' . ($isDraft ? 'YES' : 'NO'), LOG_INFO);

	return [
		'status'      => 'ok',
		'id'          => $newId,
		'ref'         => $expense->ref,
		'is_draft'    => $isDraft,
		'expense_id'  => $newId,
		'expense_ref' => $expense->ref,
	];
}

/**
 * Create a Dolibarr "various payment" (pago diverso) from OCR/AI data.
 *
 * Simplest target: a single bank movement without a third party. Chosen when the
 * company doesn't use the Expense Reports module. Weaker model on purpose: NO
 * employee/reimbursement link and NO VAT breakdown (a single amount out of a bank).
 *
 * Requires a configured bank account and payment mode (EASYOCR_EXPENSE_VARIOUS_*).
 * The receipt VAT is not split — the paid total is recorded as the outgoing amount.
 *
 * Expected $params: datef, total_ttc, merchant, project_id, file_tmp_path, file_name
 * @param  array      $params
 * @param  User|null  $userObj
 * @return array                status, id, ref, is_draft, ...
 */
function easyocrCreateVariousPaymentFromOCR($params, $userObj = null)
{
	global $db, $conf, $langs, $user;

	if (empty($db) && !empty($GLOBALS['db'])) $db = $GLOBALS['db'];
	if (empty($db) || !is_object($db)) {
		return ['status' => 'error', 'message' => 'Database connection not available ($db is null)'];
	}
	if (empty($userObj) || !is_object($userObj)) {
		$userObj = (isset($user) && is_object($user) && !empty($user->id)) ? $user : null;
	}
	if (empty($userObj) || empty($userObj->id)) {
		return ['status' => 'error', 'message' => 'No user context for various payment'];
	}

	require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/paymentvarious.class.php';
	require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

	$bankId   = !empty($conf->global->EASYOCR_EXPENSE_VARIOUS_BANK_ID) ? (int) $conf->global->EASYOCR_EXPENSE_VARIOUS_BANK_ID : 0;
	$payType  = !empty($conf->global->EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE) ? (int) $conf->global->EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE : 0;
	$acctCode = !empty($conf->global->EASYOCR_EXPENSE_VARIOUS_ACCOUNT) ? trim($conf->global->EASYOCR_EXPENSE_VARIOUS_ACCOUNT) : '';

	// With the Bank module on, create() requires a bank account + payment mode.
	if (isModEnabled('banque') && ($bankId <= 0 || $payType <= 0)) {
		return ['status' => 'error', 'message' => is_object($langs) ? $langs->trans('EasyOcrExpenseVariousNotConfigured') : 'Configure a bank account and payment mode for the various payment target'];
	}

	// Parse inputs with shared helpers
	$datef_str  = isset($params['datef']) ? trim($params['datef']) : '';
	$dateParsed = !empty($datef_str) ? easyocrParseDate($datef_str) : date('Y-m-d');
	$ticketTs   = dol_mktime(12, 0, 0, (int) date('m', strtotime($dateParsed)), (int) date('d', strtotime($dateParsed)), (int) date('Y', strtotime($dateParsed)));
	$total_ttc  = isset($params['total_ttc']) ? easyocrParseNumber($params['total_ttc']) : 0;
	$merchant   = isset($params['merchant']) ? trim($params['merchant']) : '';
	$project_id = isset($params['project_id']) ? (int) $params['project_id'] : 0;

	if ($total_ttc <= 0) {
		return ['status' => 'error', 'message' => 'Invalid or missing total amount'];
	}

	$label = 'EasyOCR' . ($merchant !== '' ? ' — ' . $merchant : ' — ' . (is_object($langs) ? $langs->trans('EasyOcrExpenseDefaultComment') : 'gasto'));

	$pv = new PaymentVarious($db);
	$pv->datep            = $ticketTs;
	$pv->datev            = $ticketTs;
	$pv->sens             = '0'; // 0 = money OUT of the bank (expense)
	$pv->amount           = $total_ttc;
	$pv->label            = $label;
	$pv->type_payment     = $payType;
	$pv->fk_account       = $bankId;
	$pv->accountid        = $bankId; // legacy alias used by create()
	$pv->accountancy_code = $acctCode;
	$pv->fk_project       = $project_id;
	$pv->fk_user_author   = $userObj->id;

	$newId = $pv->create($userObj);
	if ($newId <= 0) {
		return ['status' => 'error', 'message' => 'Error creating various payment: ' . $pv->error];
	}

	// Attach the receipt photo (best-effort). Various payments have no standard
	// document tab, but we store + ECM-link the file for traceability.
	$file_tmp_path = isset($params['file_tmp_path']) ? $params['file_tmp_path'] : '';
	$file_name     = isset($params['file_name']) ? $params['file_name'] : '';
	if (!empty($file_tmp_path) && file_exists($file_tmp_path)) {
		$reldir  = 'easyocr/expense_various/' . $newId;
		$destDir = DOL_DATA_ROOT . '/' . $reldir;
		if (!@is_dir($destDir)) @mkdir($destDir, 0755, true);
		$fileName     = dol_sanitizeFileName(basename($file_name !== '' ? $file_name : 'receipt.jpg'));
		$destFullPath = $destDir . '/' . $fileName;
		$moved = @dol_move($file_tmp_path, $destFullPath, 0, 1, 0, 0);
		if (!$moved) $moved = @copy($file_tmp_path, $destFullPath);
		if ($moved) {
			$ecmfile = new EcmFiles($db);
			$ecmfile->filepath        = $reldir;
			$ecmfile->filename        = $fileName;
			$ecmfile->fullpath_orig   = $fileName;
			$ecmfile->gen_or_uploaded = 'uploaded';
			$ecmfile->src_object_type = 'payment_various';
			$ecmfile->src_object_id   = $newId;
			$ecmfile->fk_user_c       = $userObj->id;
			$ecmfile->create($userObj);
		}
	}

	dol_syslog('EasyOCR-VARIOUS: created id=' . $newId . ', amount=' . $total_ttc . ', bank=' . $bankId . ', payType=' . $payType, LOG_INFO);

	return [
		'status'      => 'ok',
		'id'          => $newId,
		'ref'         => $pv->ref ?: $newId,
		'is_draft'    => false,
		'expense_id'  => $newId,
		'expense_ref' => $pv->ref ?: $newId,
	];
}

