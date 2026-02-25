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
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1";
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
	$import_key        = isset($params['import_key']) ? $params['import_key'] : 'easyocr-ai';

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

	$supplier_payment_mode = 0;
	$supplier_payment_cond = 0;
	if (!empty($socTmp->id)) {
		if (!empty($socTmp->mode_reglement_supplier_id)) {
			$supplier_payment_mode = $socTmp->mode_reglement_supplier_id;
		}
		if (!empty($socTmp->cond_reglement_supplier_id)) {
			$supplier_payment_cond = $socTmp->cond_reglement_supplier_id;
		}
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
	$db->query($sql_upd);

	// ── Add lines — full tax support (IVA/TVA, RE, IRPF) + product matching ─
	$lineErrors = array();
	if (!empty($items)) {
		$lineIndex = 0;
		foreach ($items as $item) {
			$lineIndex++;
			$desc = !empty($item['description']) ? $item['description'] : 'Línea';
			$qty = !empty($item['quantity']) ? floatval($item['quantity']) : 1;
			$unit_price = isset($item['unit_price']) && $item['unit_price'] !== '' ? easyocrParseNumber($item['unit_price']) : 0;
			$discount = !empty($item['discount_percent']) ? floatval($item['discount_percent']) : 0;
			$itemType = isset($item['item_type']) ? strtolower(trim($item['item_type'])) : '';

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

			// Calculate unit_price from net_amount or total if missing
			if ($unit_price == 0 && !empty($item['net_amount'])) {
				$net = easyocrParseNumber($item['net_amount']);
				$unit_price = $net / ($qty > 0 ? $qty : 1);
				if ($discount > 0) {
					$unit_price = $unit_price / (1 - $discount / 100);
				}
			}
			if ($unit_price == 0 && !empty($item['total'])) {
				$lineTotal = easyocrParseNumber($item['total']);
				$lineTaxAmt = 0;
				if (!empty($item['taxes']) && is_array($item['taxes'])) {
					foreach ($item['taxes'] as $tax) {
						$lineTaxAmt += floatval($tax['tax_amount'] ?? 0);
					}
				} elseif (!empty($item['tax_amount'])) {
					$lineTaxAmt = easyocrParseNumber($item['tax_amount']);
				}
				$unit_price = ($lineTotal - $lineTaxAmt) / ($qty > 0 ? $qty : 1);
			}

			// Product matching by code/ref — skip for discount/surcharge/other types
			$fk_product = 0;
			$skipProductMatch = in_array($itemType, ['discount', 'surcharge', 'other', '']);
			if (!$skipProductMatch && !empty($item['code'])) {
				$sqlProd = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
				$sqlProd .= " WHERE (ref = '" . $db->escape($item['code']) . "'";
				$sqlProd .= " OR barcode = '" . $db->escape($item['code']) . "')";
				$sqlProd .= " AND entity IN (" . getEntity('product') . ") LIMIT 1";
				$resProd = $db->query($sqlProd);
				if ($resProd && $db->num_rows($resProd) > 0) {
					$fk_product = $db->fetch_object($resProd)->rowid;
				} else {
					// Auto-create product if not found
					$newProduct = new Product($db);
					$newProduct->ref = $item['code'];
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
					}
				}
			}

			// Determine line type: 0=product, 1=service
			$line_type = 0;
			if (in_array($itemType, ['service', 'shipping', 'fee', 'surcharge', 'discount'])) {
				$line_type = 1;
			}

			dol_syslog("EasyOCR addline #$lineIndex: desc=$desc, pu=$unit_price, tva=$tva_rate, ltx1=$localtax1_rate, ltx2=$localtax2_rate, qty=$qty, fk_prod=$fk_product, disc=$discount, type=$line_type", LOG_DEBUG);

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
				$line_type           // type (0=product, 1=service)
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

	// ── Upload PDF ───────────────────────────────────────────────────────
	if (!empty($file_tmp_path) && file_exists($file_tmp_path)) {
		$ref_clean = dol_sanitizeFileName($ref);
		$reldir = 'fournisseur/facture/' . get_exdir($newId, 2, 0, 0, $facture, 'invoice_supplier') . $ref_clean;
		$upload_dir = DOL_DATA_ROOT . '/' . $reldir;

		if (!@is_dir($upload_dir)) {
			@mkdir($upload_dir, 0755, true); // Native mkdir avoids dol_mkdir open_basedir issue
		}

		$fileName = dol_sanitizeFileName(basename($file_name));
		$destFileName = $ref_clean . '-' . $fileName;
		$destFullPath = $upload_dir . '/' . $destFileName;

		// move_uploaded_file for HTTP uploads, copy as fallback (webhook / CLI)
		if (move_uploaded_file($file_tmp_path, $destFullPath) || copy($file_tmp_path, $destFullPath)) {
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

	// ── Return result ────────────────────────────────────────────────────
	dol_syslog('EasyOCR-CREATE: SUCCESS — id=' . $newId . ', ref=' . $ref . ', fk_soc=' . $fk_soc . ', draft=' . ($invoice_status === 'draft' ? 'YES' : 'NO') . ', line_errors=' . count($lineErrors), LOG_INFO);
	return [
		'status'           => 'ok',
		'id'               => $newId,
		'ref'              => $ref,
		'supplier_id'      => $fk_soc,
		'supplier_created' => $supplier_created,
		'supplier_name'    => $supplier_created_name,
		'is_draft'         => ($invoice_status === 'draft'),
		'line_errors'      => $lineErrors,
		// Aliases for webhook compatibility
		'invoice_id'       => $newId,
		'invoice_ref'      => $ref,
	];
}

