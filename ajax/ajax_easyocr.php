<?php

/**
 * EasyOcr - AJAX Handler
 *
 * @package    EasyOcr
 * @copyright  2025-2026 EasySoft Tech S.L.
 * @license    GPL-3.0+
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once __DIR__ . '/../lib/easyocr.lib.php';
require_once __DIR__ . '/../lib/easyocr_ai.class.php';

// For SSE stream, skip JSON content-type (will set text/event-stream later)
$_action = GETPOST('action', 'aZ09');
if ($_action !== 'aiOcrStream') {
	top_httphead('application/json');
}

$langs->load('easyocr@easyocr');

// Security
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
	exit;
}

// --- Helpers in lib/easyocr.lib.php (easyocrParseDate, easyocrParseNumber, easyocrCalcTaxRate) ---

// --- Actions ---

$action = isset($_POST["action"]) ? $_POST["action"] : '';

// ============================================================
// CREAR FACTURA DE PROVEEDOR
// ============================================================
if ($action == "createSupplierInvoice") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$fk_soc = GETPOST("fk_soc", "int");
	$ref_supplier = GETPOST("ref_supplier", "alphanohtml");
	$datef_str = easyocrParseDate(GETPOST("datef", "alphanohtml"));
	$total_ttc = easyocrParseNumber(GETPOST("total_ttc", "alphanohtml"));
	$total_ht = easyocrParseNumber(GETPOST("total_ht", "alphanohtml"));

	// Use IVA from OCR if provided, otherwise calculate
	$total_tva_ocr = GETPOST("total_tva", "alphanohtml");
	if (!empty($total_tva_ocr)) {
		$total_tva = easyocrParseNumber($total_tva_ocr);
	} else {
		$total_tva = $total_ttc - $total_ht;
	}

	// Optional: description from OCR
	$ocr_description = GETPOST("description", "restricthtml");

	// Optional: due date from OCR
	$date_echeance_str = GETPOST("date_echeance", "alphanohtml");

	// Verificar duplicado por ref_supplier + proveedor (normalizado: trim + case-insensitive)
	if (!empty($ref_supplier)) {
		$ref_clean_check = trim($ref_supplier);
		$sql_check = "SELECT rowid, ref, ref_supplier FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql_check .= " WHERE UPPER(TRIM(ref_supplier)) = UPPER('" . $db->escape($ref_clean_check) . "')";
		$sql_check .= " AND fk_soc = " . ((int) $fk_soc);
		$sql_check .= " AND entity IN (" . getEntity('supplier_invoice') . ")";
		$resql_check = $db->query($sql_check);
		if ($resql_check && $db->num_rows($resql_check) > 0) {
			$existingObj = $db->fetch_object($resql_check);
			print json_encode([
				"status" => "repeat",
				"message" => $langs->trans('EasyOcrDuplicateRefSupplier', $ref_supplier, $existingObj->ref),
				"existing_id" => $existingObj->rowid,
				"existing_ref" => $existingObj->ref,
				"existing_ref_supplier" => $existingObj->ref_supplier
			]);
			exit;
		}
	}

	// Crear factura usando el objeto nativo
	$facture = new FactureFournisseur($db);
	$facture->socid = $fk_soc;
	$facture->ref_supplier = $ref_supplier;
	$facture->date = dol_mktime(
		12,
		0,
		0,
		date('m', strtotime($datef_str)),
		date('d', strtotime($datef_str)),
		date('Y', strtotime($datef_str))
	);
	$facture->multicurrency_code = $conf->currency;
	$facture->import_key = 'easyocr';

	// Set due date if provided
	if (!empty($date_echeance_str)) {
		$date_ech = easyocrParseDate($date_echeance_str);
		$facture->date_echeance = dol_mktime(
			12,
			0,
			0,
			date('m', strtotime($date_ech)),
			date('d', strtotime($date_ech)),
			date('Y', strtotime($date_ech))
		);
	}

	$newId = $facture->create($user);

	if ($newId <= 0) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorCreatingInvoice') . ': ' . $facture->error]);
		exit;
	}

	// Setear import_key (create() no lo incluye en el INSERT, se hace via update)
	$sql = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET import_key = 'easyocr' WHERE rowid = " . ((int) $newId);
	$db->query($sql);

	// Load supplier for localtax calculation (RE / IRPF)
	$supplier = new Societe($db);
	$supplier->fetch($fk_soc);

	// Add invoice line with proper localtax resolution
	$line_desc = !empty($ocr_description) ? $ocr_description : $langs->trans('EasyOcrInvoiceLineDesc');
	$tva_tx = easyocrCalcTaxRate($total_ht, $total_tva);
	$localtax1_tx = get_localtax($tva_tx, 1, $mysoc, $supplier);
	$localtax2_tx = get_localtax($tva_tx, 2, $mysoc, $supplier);

	$result = $facture->addline(
		$line_desc,       // description
		$total_ht,        // pu (precio unitario HT)
		$tva_tx,          // txtva
		$localtax1_tx,    // txlocaltax1 (RE)
		$localtax2_tx,    // txlocaltax2 (IRPF)
		1                 // qty
	);

	if ($result <= 0) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorAddingLine') . ': ' . $facture->error]);
		exit;
	}

	// Validate or leave as draft based on config
	$ref = '(PROV' . $newId . ')';
	$is_draft = !empty($conf->global->EASYOCR_INVOICE_DRAFT);

	if (!$is_draft) {
		$result = $facture->validate($user);
		if ($result <= 0) {
			print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorValidating') . ': ' . $facture->error]);
			exit;
		}
		$facture->fetch($newId);
		$ref = $facture->ref;
	} else {
		$facture->fetch($newId);
	}

	// Attach PDF file to invoice
	$receivedFile = isset($_FILES['file']) ? $_FILES['file'] : null;
	if (!empty($receivedFile) && $receivedFile['error'] === UPLOAD_ERR_OK) {

		$ref = dol_sanitizeFileName($facture->ref);
		$reldir = 'fournisseur/facture/' . get_exdir($newId, 2, 0, 0, $facture, 'invoice_supplier') . $ref;
		$destDir = DOL_DATA_ROOT . '/' . $reldir;

		if (!dol_is_dir($destDir)) {
			dol_mkdir($destDir);
		}

		$fileName = dol_sanitizeFileName(basename($receivedFile['name']));
		$destFileName = $ref . '-' . $fileName;
		$destFullPath = $destDir . '/' . $destFileName;

		$moveResult = dol_move_uploaded_file($receivedFile['tmp_name'], $destFullPath, 1, 1, $receivedFile['error'], 1, 'file');
		if ($moveResult > 0) {
			// Register in ECM
			$ecmfile = new EcmFiles($db);
			$ecmfile->filepath = $reldir;
			$ecmfile->filename = $destFileName;
			$ecmfile->fullpath_orig = $fileName;
			$ecmfile->gen_or_uploaded = 'uploaded';
			$ecmfile->src_object_type = 'supplier_invoice';
			$ecmfile->src_object_id = $newId;
			$ecmfile->fk_user_c = $user->id;
			$ecmfile->create($user);
		}
	}

	// Create payment only for validated invoices
	$create_payment = GETPOST('create_payment', 'alpha');
	if ($create_payment == '1' && GETPOST('payment_bank_id', 'int') > 0 && !$is_draft) {

		$payment_bank_id = GETPOST('payment_bank_id', 'int');
		$payment_type_id = GETPOST('payment_type_id', 'int') > 0 ? GETPOST('payment_type_id', 'int') : 6;

		$paiement = new PaiementFourn($db);
		$paiement->datepaye = $facture->date;
		$paiement->amounts = array($newId => $total_ttc);
		$paiement->multicurrency_amounts = array($newId => $total_ttc);
		$paiement->multicurrency_code = array($newId => $conf->currency);
		$paiement->multicurrency_tx = array($newId => 1);
		$paiement->paiementid = $payment_type_id;
		$paiement->num_payment = $ref_supplier;
		$paiement->note_private = $langs->trans('EasyOcrPaymentAutoNote');
		$paiement->fk_account = $payment_bank_id;

		$paiement_id = $paiement->create($user, 1);
		if ($paiement_id > 0) {
			$paiement->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', $payment_bank_id, '', '');
		}
	}

	print json_encode(["status" => "ok", "id" => $newId, "ref" => $ref, "is_draft" => $is_draft]);


	// ============================================================
	// OBTENER DATOS PARA EL FORMULARIO (proveedores, templates, bancos, pagos)
	// ============================================================
} else if ($action == "loadFormData") {

	// Proveedores
	$sql = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE fournisseur = 1 AND entity IN (" . getEntity('societe') . ") ORDER BY nom";
	$resql = $db->query($sql);
	$result_suppliers = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result_suppliers[] = array('rowid' => $obj->rowid, 'nom' => $obj->nom);
		}
	}

	// Templates
	$sql = "SELECT t.rowid, t.name, t.fk_soc, t.custom_instructions, s.nom as supplier_name";
	$sql .= " FROM " . MAIN_DB_PREFIX . "easyocr_template t";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON t.fk_soc = s.rowid";
	$sql .= " ORDER BY t.name";
	$resql = $db->query($sql);
	$result_templates = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result_templates[] = array(
				'rowid' => $obj->rowid,
				'name' => $obj->name,
				'fk_soc' => $obj->fk_soc,
				'supplier_name' => $obj->supplier_name,
				'has_custom_instructions' => !empty($obj->custom_instructions) ? 1 : 0
			);
		}
	}

	// Cuentas bancarias activas
	$sql = "SELECT rowid, label, number, currency_code FROM " . MAIN_DB_PREFIX . "bank_account";
	$sql .= " WHERE clos = 0 AND entity = " . ((int) $conf->entity) . " ORDER BY label";
	$resql = $db->query($sql);
	$result_banks = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result_banks[] = array(
				'rowid' => $obj->rowid,
				'label' => $obj->label,
				'number' => $obj->number,
				'currency_code' => $obj->currency_code
			);
		}
	}

	// Tipos de pago
	$sql = "SELECT id, code, libelle as label FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 ORDER BY libelle";
	$resql = $db->query($sql);
	$result_payment_types = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$translated = $langs->transnoentitiesnoconv("PaymentTypeShort" . $obj->code);
			$label = ($translated !== "PaymentTypeShort" . $obj->code) ? $translated : $obj->label;
			$result_payment_types[] = array(
				'id' => $obj->id,
				'code' => $obj->code,
				'label' => $label
			);
		}
	}

	// Diarios contables (series de factura de proveedor)
	$result_journals = array();
	$sql = "SELECT rowid, code, label FROM " . MAIN_DB_PREFIX . "accounting_journal";
	$sql .= " WHERE nature = 2 AND active = 1 AND entity = " . ((int) $conf->entity) . " ORDER BY code";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result_journals[] = array('rowid' => $obj->rowid, 'code' => $obj->code, 'label' => $obj->label);
		}
	}

	print json_encode([
		"suppliers" => $result_suppliers,
		"templates" => $result_templates,
		"banks" => $result_banks,
		"payment_types" => $result_payment_types,
		"journals" => $result_journals
	]);


	// ============================================================
	// OBTENER DETALLES DE UN TEMPLATE
	// ============================================================
} else if ($action == "fetchTemplateData") {

	$template_id = GETPOST("template_id", "int");

	$sql = "SELECT fk_soc, scale, custom_instructions FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . ((int) $template_id);
	$resql = $db->query($sql);
	$tpl_data = $db->fetch_object($resql);
	$fk_soc = $tpl_data ? $tpl_data->fk_soc : null;
	$tpl_scale = $tpl_data && $tpl_data->scale ? floatval($tpl_data->scale) : 1.5;
	$custom_instructions = $tpl_data && !empty($tpl_data->custom_instructions) ? $tpl_data->custom_instructions : '';

	$sql = "SELECT page_index, pos_x, pos_y, sel_w, sel_h, color, label";
	$sql .= " FROM " . MAIN_DB_PREFIX . "easyocr_template_details";
	$sql .= " WHERE fk_template = " . ((int) $template_id);
	$resql = $db->query($sql);
	$result = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result[] = array(
				'page_index' => $obj->page_index,
				'pos_x' => $obj->pos_x,
				'pos_y' => $obj->pos_y,
				'sel_w' => $obj->sel_w,
				'sel_h' => $obj->sel_h,
				'color' => $obj->color,
				'label' => $obj->label
			);
		}
	}

	print json_encode(["details" => $result, "fk_soc" => $fk_soc, "scale" => $tpl_scale, "custom_instructions" => $custom_instructions]);


	// ============================================================
	// CREAR TEMPLATE
	// ============================================================
} else if ($action == "saveNewTemplate") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$name = GETPOST("name", "alphanohtml");
	$fk_soc = GETPOST("fk_soc", "int");
	$tpl_scale = GETPOST("scale", "alpha");
	$tpl_scale = $tpl_scale ? floatval($tpl_scale) : 1.5;
	$custom_instructions = GETPOST("custom_instructions", "restricthtml");
	$selections = json_decode(GETPOST("selections", "restricthtml"), true);

	$db->begin();

	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (name, fk_soc, scale, custom_instructions, date_creation)";
	$sql .= " VALUES ('" . $db->escape($name) . "', " . ($fk_soc > 0 ? ((int) $fk_soc) : "NULL") . ", " . $tpl_scale . ", " . (!empty($custom_instructions) ? "'" . $db->escape($custom_instructions) . "'" : "NULL") . ", NOW())";

	if (!$db->query($sql)) {
		$db->rollback();
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorCreatingTemplate')]);
		exit;
	}

	$template_id = $db->last_insert_id(MAIN_DB_PREFIX . "easyocr_template");

	if (is_array($selections)) {
		foreach ($selections as $item) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details";
			$sql .= " (fk_template, page_index, pos_x, pos_y, sel_w, sel_h, color, label)";
			$sql .= " VALUES (";
			$sql .= ((int) $template_id) . ", ";
			$sql .= ((int) $item['page_index']) . ", ";
			$sql .= floatval($item['pos_x']) . ", ";
			$sql .= floatval($item['pos_y']) . ", ";
			$sql .= floatval($item['sel_w']) . ", ";
			$sql .= floatval($item['sel_h']) . ", ";
			$sql .= "'" . $db->escape($item['color']) . "', ";
			$sql .= "'" . $db->escape($item['label']) . "'";
			$sql .= ")";
			$db->query($sql);
		}
	}

	$db->commit();
	print json_encode(["status" => "ok", "id" => $template_id]);


	// ============================================================
	// EDITAR TEMPLATE
	// ============================================================
} else if ($action == "updateTemplate") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$template_id = GETPOST("template_id", "int");
	$fk_soc = GETPOST("fk_soc", "int");
	$tpl_scale = GETPOST("scale", "alpha");
	$tpl_scale = $tpl_scale ? floatval($tpl_scale) : 1.5;
	$custom_instructions = GETPOST("custom_instructions", "restricthtml");
	$selections = json_decode(GETPOST("selections", "restricthtml"), true);

	$db->begin();

	$sql = "UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET fk_soc = " . ($fk_soc > 0 ? ((int) $fk_soc) : "NULL") . ", scale = " . $tpl_scale;
	$sql .= ", custom_instructions = " . (!empty($custom_instructions) ? "'" . $db->escape($custom_instructions) . "'" : "NULL");
	$sql .= " WHERE rowid = " . ((int) $template_id);
	$db->query($sql);

	// Borrar detalles anteriores y reinsertar
	$sql = "DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template = " . ((int) $template_id);
	$db->query($sql);

	if (is_array($selections)) {
		foreach ($selections as $item) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details";
			$sql .= " (fk_template, page_index, pos_x, pos_y, sel_w, sel_h, color, label)";
			$sql .= " VALUES (";
			$sql .= ((int) $template_id) . ", ";
			$sql .= ((int) $item['page_index']) . ", ";
			$sql .= floatval($item['pos_x']) . ", ";
			$sql .= floatval($item['pos_y']) . ", ";
			$sql .= floatval($item['sel_w']) . ", ";
			$sql .= floatval($item['sel_h']) . ", ";
			$sql .= "'" . $db->escape($item['color']) . "', ";
			$sql .= "'" . $db->escape($item['label']) . "'";
			$sql .= ")";
			$db->query($sql);
		}
	}

	$db->commit();
	print json_encode(["status" => "ok"]);


	// ============================================================
	// BUSCAR PROVEEDOR POR CIF/NIF
	// ============================================================
} else if ($action == "findSupplierByCIF") {

	$cif = GETPOST("cif", "alphanohtml");
	if (empty($cif)) {
		print json_encode(["status" => "error", "message" => "CIF/NIF required"]);
		exit;
	}

	// Clean the CIF - remove spaces, dashes
	$cif_clean = preg_replace('/[\s\-\.]/', '', trim($cif));

	// Search ALL suppliers with this CIF (no LIMIT) - support multiple suppliers with same tax ID
	$sql = "SELECT s.rowid, s.nom FROM " . MAIN_DB_PREFIX . "societe s";
	$sql .= " WHERE s.fournisseur = 1";
	$sql .= " AND s.status = 1";
	$sql .= " AND s.entity IN (" . getEntity('societe') . ")";
	$sql .= " AND (";
	$sql .= " REPLACE(REPLACE(REPLACE(s.siren, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.siret, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.ape, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.idprof4, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.idprof5, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.idprof6, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.tva_intra, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= ")";
	$sql .= " ORDER BY s.nom";

	$resql = $db->query($sql);
	$supplierCount = $resql ? $db->num_rows($resql) : 0;

	if ($supplierCount > 0) {
		$suppliers = array();
		while ($obj = $db->fetch_object($resql)) {
			$suppliers[] = array(
				"id" => $obj->rowid,
				"name" => $obj->nom
			);
		}

		// Return array of suppliers if multiple found, or single supplier data for backwards compatibility
		if (count($suppliers) > 1) {
			print json_encode([
				"status" => "ok",
				"found_count" => count($suppliers),
				"suppliers" => $suppliers,
				"created" => false
			]);
		} else {
			// Single supplier - keep existing format
			print json_encode([
				"status" => "ok",
				"fk_soc" => $suppliers[0]['id'],
				"name" => $suppliers[0]['name'],
				"found_count" => 1,
				"created" => false
			]);
		}
	} else {
		// Search also non-suppliers â€” maybe exists as client, upgrade to supplier
		$sql2 = "SELECT s.rowid, s.nom FROM " . MAIN_DB_PREFIX . "societe s";
		$sql2 .= " WHERE s.status = 1";
		$sql2 .= " AND s.entity IN (" . getEntity('societe') . ")";
		$sql2 .= " AND (";
		$sql2 .= " REPLACE(REPLACE(REPLACE(s.siren, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= " OR REPLACE(REPLACE(REPLACE(s.siret, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= " OR REPLACE(REPLACE(REPLACE(s.ape, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= " OR REPLACE(REPLACE(REPLACE(s.idprof4, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= " OR REPLACE(REPLACE(REPLACE(s.idprof5, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= " OR REPLACE(REPLACE(REPLACE(s.idprof6, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= " OR REPLACE(REPLACE(REPLACE(s.tva_intra, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
		$sql2 .= ") LIMIT 1";

		$resql2 = $db->query($sql2);
		if ($resql2 && $db->num_rows($resql2) > 0) {
			// Exists as non-supplier â€” upgrade to supplier
			$obj2 = $db->fetch_object($resql2);
			$existingSoc = new Societe($db);
			$existingSoc->fetch($obj2->rowid);

			// Generate supplier code if needed
			$newCodeFournisseur = $existingSoc->code_fournisseur;
			if (empty($newCodeFournisseur) || $newCodeFournisseur == '-1') {
				$existingSoc->get_codefournisseur();
				$newCodeFournisseur = $existingSoc->code_fournisseur;
			}

			// Update only fournisseur flag and code using SQL (preserves country and other fields)
			$sqlUpgrade = "UPDATE " . MAIN_DB_PREFIX . "societe SET fournisseur = 1";
			if (!empty($newCodeFournisseur) && $newCodeFournisseur != '-1') {
				$sqlUpgrade .= ", code_fournisseur = '" . $db->escape($newCodeFournisseur) . "'";
			}
			$sqlUpgrade .= " WHERE rowid = " . ((int) $obj2->rowid);
			$db->query($sqlUpgrade);

			print json_encode(["status" => "ok", "fk_soc" => $existingSoc->id, "name" => $existingSoc->nom, "created" => false, "upgraded" => true]);
		} else {
			// Not found at all â€” auto-create if requested
			$autoCreate = GETPOST('auto_create', 'alpha');
			if ($autoCreate == '1') {
				$supplierName    = GETPOST('supplier_name', 'alphanohtml');
				$supplierAddress = GETPOST('supplier_address', 'alphanohtml');
				$supplierCity    = GETPOST('supplier_city', 'alphanohtml');
				$supplierZip     = GETPOST('supplier_zip', 'alphanohtml');
				$supplierCountry = GETPOST('supplier_country', 'alphanohtml');
				$supplierPhone   = GETPOST('supplier_phone', 'alphanohtml');
				$supplierEmail   = GETPOST('supplier_email', 'alphanohtml');

				if (empty($supplierName)) {
					print json_encode(["status" => "error", "message" => "Supplier name is required to create"]);
					exit;
				}

				$newSoc = new Societe($db);
				$newSoc->name        = $supplierName;
				$newSoc->client      = 0;
				$newSoc->fournisseur = 1;
				$newSoc->status      = 1; // Active

				// Set CIF/NIF â€” try to identify type
				$cifUpper = strtoupper($cif_clean);
				// VAT number (starts with country code)
				if (preg_match('/^[A-Z]{2}/', $cifUpper)) {
					$newSoc->tva_intra = $cif;
					$newSoc->country_code = substr($cifUpper, 0, 2);
				}
				// Store in idprof1 (maps to DB column 'siren' - CIF/NIF in Spain, SIREN in France)
				// Note: Societe::create() reads from idprof1, NOT from the legacy alias 'siren'
				$newSoc->idprof1 = $cif;

				if (!empty($supplierAddress)) $newSoc->address = $supplierAddress;
				if (!empty($supplierCity))    $newSoc->town    = $supplierCity;
				if (!empty($supplierZip))     $newSoc->zip     = $supplierZip;
				if (!empty($supplierPhone))   $newSoc->phone   = $supplierPhone;
				if (!empty($supplierEmail))   $newSoc->email   = $supplierEmail;

				// Resolve country ID from country code or name
				if (!empty($supplierCountry)) {
					$countryClean = trim($supplierCountry);
					$sqlCountry = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country";
					$sqlCountry .= " WHERE (code = '" . $db->escape(strtoupper(substr($countryClean, 0, 2))) . "'";
					$sqlCountry .= " OR label LIKE '" . $db->escape($countryClean) . "%')";
					$sqlCountry .= " AND active = 1 LIMIT 1";
					$resCountry = $db->query($sqlCountry);
					if ($resCountry && $db->num_rows($resCountry) > 0) {
						$objC = $db->fetch_object($resCountry);
						$newSoc->country_id = $objC->rowid;
					}
				} elseif (!empty($newSoc->country_code)) {
					$sqlCC = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = '" . $db->escape($newSoc->country_code) . "' AND active = 1 LIMIT 1";
					$resCC = $db->query($sqlCC);
					if ($resCC && $db->num_rows($resCC) > 0) {
						$objCC = $db->fetch_object($resCC);
						$newSoc->country_id = $objCC->rowid;
					}
				}

				// Generate supplier code
				$newSoc->get_codefournisseur();

				$newId = $newSoc->create($user);
				if ($newId > 0) {
					print json_encode(["status" => "ok", "fk_soc" => $newId, "name" => $newSoc->name, "created" => true]);
				} else {
					// Build detailed error message
					$errorDetails = [];
					$errorDetails[] = "Main error: " . ($newSoc->error ?: 'Unknown error');

					if (!empty($newSoc->errors)) {
						$errorDetails[] = "Additional errors: " . implode(', ', $newSoc->errors);
					}

					$errorDetails[] = "Attempted data - Name: '" . ($newSoc->name ?: 'N/A') . "'";
					$errorDetails[] = "CIF/Tax ID: '" . ($cif ?: 'N/A') . "'";
					$errorDetails[] = "Country: '" . ($supplierCountry ?: ($newSoc->country_code ?: 'N/A')) . "'";
					$errorDetails[] = "Supplier code: '" . ($newSoc->code_fournisseur ?: 'N/A') . "'";

					if (!empty($db->lasterror())) {
						$errorDetails[] = "DB error: " . $db->lasterror();
					}

					print json_encode(["status" => "error", "message" => "Error creating supplier. " . implode(' | ', $errorDetails)]);
				}
			} else {
				print json_encode(["status" => "not_found"]);
			}
		}
	}


	// ============================================================
	// AI OCR - CREATE INVOICE FROM AI STRUCTURED DATA (multi-line)
	// ============================================================
} else if ($action == "newInvoiceAI") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	// Build params array from POST data — delegated to shared lib function
	$params = array(
		'fk_soc'           => GETPOST('fk_soc', 'int'),
		'ref_supplier'     => GETPOST('ref_supplier', 'alphanohtml'),
		'datef'            => GETPOST('datef', 'alphanohtml'),
		'total_ttc'        => GETPOST('total_ttc', 'alphanohtml'),
		'total_ht'         => GETPOST('total_ht', 'alphanohtml'),
		'total_tva'        => GETPOST('total_tva', 'alphanohtml'),
		'total_localtax1'  => GETPOST('total_localtax1', 'alphanohtml'),
		'total_localtax2'  => GETPOST('total_localtax2', 'alphanohtml'),
		'date_echeance'    => GETPOST('date_echeance', 'alphanohtml'),
		'notes'            => GETPOST('notes', 'restricthtml'),
		'items'            => GETPOST('items', 'restricthtml'),
		'default_tax_rate' => GETPOST('default_tax_rate', 'alpha'),
		'supplier_name'    => GETPOST('supplier_name', 'alphanohtml'),
		'supplier_tax_id'  => GETPOST('supplier_tax_id', 'alphanohtml'),
		'supplier_address' => GETPOST('supplier_address', 'alphanohtml'),
		'supplier_city'    => GETPOST('supplier_city', 'alphanohtml'),
		'supplier_zip'     => GETPOST('supplier_zip', 'alphanohtml'),
		'supplier_country' => GETPOST('supplier_country', 'alphanohtml'),
		'supplier_phone'   => GETPOST('supplier_phone', 'alphanohtml'),
		'supplier_email'   => GETPOST('supplier_email', 'alphanohtml'),
		'invoice_status'   => GETPOST('invoice_status', 'alpha'),
		'invoice_type'     => GETPOST('invoice_type', 'int'),
		'journal_code'     => GETPOST('journal_code', 'alphanohtml'),
		'create_payment'   => GETPOST('create_payment', 'alpha'),
		'payment_bank_id'  => GETPOST('payment_bank_id', 'int'),
		'payment_type_id'  => GETPOST('payment_type_id', 'int'),
		'import_key'       => 'easyocr-ai',
	);

	// Handle received file
	$receivedFile = isset($_FILES['file']) ? $_FILES['file'] : null;
	if (!empty($receivedFile) && $receivedFile['error'] === UPLOAD_ERR_OK) {
		$params['file_tmp_path'] = $receivedFile['tmp_name'];
		$params['file_name'] = $receivedFile['name'];
	}

	// Call shared invoice creation function
	$result = easyocrCreateInvoiceFromOCR($params, $user);
	print json_encode($result);


	// ============================================================
	// AI OCR - PROCESS PDF WITH AI SERVICE
	// ============================================================
} else if ($action == "aiOcr") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$aiService = new EasyOcrAI($db);
	if (!$aiService->isEnabled()) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAINotConfigured')]);
		exit;
	}

	// Accept base64 data from frontend
	$base64Data = GETPOST("base64_data", "restricthtml");
	$customInstructions = GETPOST("custom_instructions", "restricthtml");

	if (empty($base64Data)) {
		print json_encode(["status" => "error", "message" => "No PDF data provided"]);
		exit;
	}

	$result = $aiService->processBase64($base64Data, $customInstructions);

	if ($result === false) {
		print json_encode(["status" => "error", "message" => $aiService->error]);
		exit;
	}

	print json_encode(["status" => "ok", "data" => $result]);


	// ============================================================
	// AI OCR - SSE STREAM PROXY (avoids CORS, keeps apiKey server-side)
	// ============================================================
} else if ($action == "aiOcrStream") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		header('Content-Type: text/event-stream');
		echo "event: error\ndata: " . json_encode(["message" => "Sin permiso de escritura"]) . "\n\n";
		exit;
	}

	$aiService = new EasyOcrAI($db);

	if (!$aiService->isEnabled()) {
		header('Content-Type: text/event-stream');
		echo "event: error\ndata: " . json_encode(["message" => $langs->trans('EasyOcrAINotConfigured')]) . "\n\n";
		exit;
	}

	// Get base64 data â€” use $_POST directly to avoid Dolibarr sanitization on large payloads
	$base64Data = isset($_POST['base64_data']) ? $_POST['base64_data'] : '';
	$filename   = GETPOST('filename', 'alpha') ?: 'document.pdf';
	$customInstructions = GETPOST('custom_instructions', 'restricthtml');

	// If custom instructions were sent, verify the plan supports them
	if (!empty($customInstructions)) {
		try {
			$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
			$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';
			$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
			$accountData = $client->account()->me();
			$features = $accountData['data']['features'] ?? [];
			if (empty($features['custom_instructions'])) {
				dol_syslog('EasyOcr aiOcrStream: custom_instructions stripped (plan does not allow)', LOG_WARNING);
				$customInstructions = '';
			}
		} catch (\Exception $e) {
			// If we can't verify, strip instructions to be safe
			dol_syslog('EasyOcr aiOcrStream: custom_instructions stripped (plan check failed: '.$e->getMessage().')', LOG_WARNING);
			$customInstructions = '';
		}
	}

	if (empty($base64Data)) {
		header('Content-Type: text/event-stream');
		echo "event: error\ndata: " . json_encode(["message" => "No PDF data"]) . "\n\n";
		exit;
	}

	// SSE headers
	header('Content-Type: text/event-stream; charset=utf-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Connection: keep-alive');
	header('X-Accel-Buffering: no');          // nginx
	header('Content-Encoding: none');         // disable mod_deflate

	// Close session to prevent session lock blocking output
	if (function_exists('session_write_close')) {
		@session_write_close();
	}

	// Remove time limit for long OCR processing
	@set_time_limit(0);

	// Disable all output buffering layers (Dolibarr, PHP, gzip)
	@ini_set('output_buffering', 'off');
	@ini_set('zlib.output_compression', false);
	if (function_exists('apache_setenv')) {
		@apache_setenv('no-gzip', '1');
	}
	while (ob_get_level()) {
		ob_end_clean();
	}
	ob_implicit_flush(true);

	// Send initial padding to push through proxy buffers (4KB comment + retry)
	echo ":" . str_repeat(" ", 4096) . "\n";
	echo "retry: 3000\n\n";
	if (ob_get_level()) ob_flush();
	flush();

	$url    = $aiService->getBaseUrl() . '/api/v1/ocr/base64/stream';
	$apiKey = $aiService->getApiKey();

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => [
			'Content-Type: application/json',
			'X-API-Key: ' . $apiKey,
			'Accept: text/event-stream'
		],
		CURLOPT_POSTFIELDS     => json_encode(array_filter([
			'base64_data'  => $base64Data,
			'filename'     => $filename,
			'include_text' => false,
			'custom_instructions' => !empty($customInstructions) ? $customInstructions : null
		], function ($v) {
			return $v !== null;
		})),
		CURLOPT_RETURNTRANSFER => false,
		CURLOPT_TIMEOUT        => 120,
		CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
			echo $chunk;
			if (ob_get_level()) ob_flush();
			flush();
			return strlen($chunk);
		}
	]);

	$ok = curl_exec($ch);

	if (!$ok || curl_errno($ch)) {
		$errMsg = curl_error($ch) ?: 'Connection failed';
		echo "event: error\ndata: " . json_encode(["message" => $errMsg]) . "\n\n";
		if (ob_get_level()) ob_flush();
		flush();
	}

	curl_close($ch);
	exit;


	// ============================================================
	// AI CONFIG - CHECK IF AI IS AVAILABLE
	// ============================================================
} else if ($action == "getAIConfig") {

	$aiService = new EasyOcrAI($db);
	print json_encode([
		"enabled" => $aiService->isEnabled(),
		"url" => $aiService->getBaseUrl()
	]);


	// ============================================================
	// GET SUPPLIER PAYMENT INFO (mode + conditions)
	// ============================================================
} else if ($action == "getSupplierPaymentInfo") {

	$fk_soc = GETPOST("fk_soc", "int");
	if (empty($fk_soc)) {
		print json_encode(["status" => "error", "message" => "Supplier ID required"]);
		exit;
	}

	$soc = new Societe($db);
	$soc->fetch($fk_soc);

	$payment_mode_id = !empty($soc->mode_reglement_supplier_id) ? $soc->mode_reglement_supplier_id : 0;
	$payment_cond_id = !empty($soc->cond_reglement_supplier_id) ? $soc->cond_reglement_supplier_id : 0;

	// Get payment mode label
	$payment_mode_label = '';
	if ($payment_mode_id > 0) {
		$sql = "SELECT libelle as label, code FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . ((int)$payment_mode_id);
		$res = $db->query($sql);
		if ($res && $db->num_rows($res) > 0) {
			$obj = $db->fetch_object($res);
			$translated = $langs->transnoentitiesnoconv("PaymentTypeShort" . $obj->code);
			$payment_mode_label = ($translated !== "PaymentTypeShort" . $obj->code) ? $translated : $obj->label;
		}
	}

	// Get payment condition label
	$payment_cond_label = '';
	if ($payment_cond_id > 0) {
		$sql = "SELECT libelle as label FROM " . MAIN_DB_PREFIX . "c_payment_term WHERE rowid = " . ((int)$payment_cond_id);
		$res = $db->query($sql);
		if ($res && $db->num_rows($res) > 0) {
			$payment_cond_label = $db->fetch_object($res)->label;
		}
	}

	print json_encode([
		"status" => "ok",
		"payment_mode_id" => $payment_mode_id,
		"payment_mode_label" => $payment_mode_label,
		"payment_cond_id" => $payment_cond_id,
		"payment_cond_label" => $payment_cond_label
	]);


	// ============================================================
	// SEARCH PRODUCTS BY REF/LABEL
	// ============================================================
} else if ($action == "searchProducts") {

	$term = GETPOST("term", "alphanohtml");
	if (empty($term) || strlen($term) < 2) {
		print json_encode([]);
		exit;
	}

	$sql = "SELECT p.rowid, p.ref, p.label, p.price, p.tva_tx, p.localtax1_tx, p.localtax2_tx, p.type";
	$sql .= " FROM " . MAIN_DB_PREFIX . "product p";
	$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";
	$sql .= " AND p.tobuy = 1";
	$sql .= " AND (p.ref LIKE '%" . $db->escape($term) . "%' OR p.label LIKE '%" . $db->escape($term) . "%' OR p.barcode = '" . $db->escape($term) . "')";
	$sql .= " ORDER BY p.ref LIMIT 20";

	$result = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result[] = array(
				'rowid' => $obj->rowid,
				'ref' => $obj->ref,
				'label' => $obj->label,
				'price' => $obj->price,
				'tva_tx' => $obj->tva_tx,
				'localtax1_tx' => $obj->localtax1_tx,
				'localtax2_tx' => $obj->localtax2_tx,
				'type' => $obj->type
			);
		}
	}

	print json_encode($result);


	// ============================================================
	// BATCH — LIST ALL BATCHES
	// ============================================================
} else if ($action == "batchList") {

	if (!easyocrCheckRight($user, 'easyocr', 'read')) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
		exit;
	}

	require_once __DIR__ . '/../lib/easyocr_autoload.php';

	$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
	$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';

	if (empty($apiKey)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoApiKey')]);
		exit;
	}

	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);

		$params = array();
		$page = GETPOST('page', 'int');
		$perPage = GETPOST('per_page', 'int');
		$statusFilter = GETPOST('status', 'aZ09');
		$nameFilter = GETPOST('name', 'alphanohtml');
		$fromFilter = GETPOST('from', 'alphanohtml');
		$toFilter = GETPOST('to', 'alphanohtml');

		if ($page > 0) $params['page'] = $page;
		if ($perPage > 0) $params['per_page'] = $perPage;
		if (!empty($statusFilter)) $params['status'] = $statusFilter;
		if (!empty($nameFilter)) $params['name'] = $nameFilter;
		if (!empty($fromFilter)) $params['from'] = $fromFilter;
		if (!empty($toFilter)) $params['to'] = $toFilter;
		$result = $client->batch()->list($params);

		print json_encode(["status" => "ok", "data" => $result]);
	} catch (\Exception $e) {
		print json_encode(["status" => "error", "message" => $e->getMessage()]);
	}


	// ============================================================
	// BATCH — GET STATUS
	// ============================================================
} else if ($action == "batchStatus") {

	if (!easyocrCheckRight($user, 'easyocr', 'read')) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
		exit;
	}

	require_once __DIR__ . '/../lib/easyocr_autoload.php';

	$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
	$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';
	$uuid = GETPOST('uuid', 'alphanohtml');

	if (empty($apiKey)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoApiKey')]);
		exit;
	}
	if (empty($uuid)) {
		print json_encode(["status" => "error", "message" => "UUID required"]);
		exit;
	}

	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
		$result = $client->batch()->status($uuid);
		print json_encode(["status" => "ok", "data" => $result]);
	} catch (\Exception $e) {
		print json_encode(["status" => "error", "message" => $e->getMessage()]);
	}


	// ============================================================
	// BATCH — GET RESULTS
	// ============================================================
} else if ($action == "batchResults") {

	if (!easyocrCheckRight($user, 'easyocr', 'read')) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
		exit;
	}

	require_once __DIR__ . '/../lib/easyocr_autoload.php';

	$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
	$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';
	$uuid = GETPOST('uuid', 'alphanohtml');

	if (empty($apiKey)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoApiKey')]);
		exit;
	}
	if (empty($uuid)) {
		print json_encode(["status" => "error", "message" => "UUID required"]);
		exit;
	}

	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
		$result = $client->batch()->results($uuid);
		print json_encode(["status" => "ok", "data" => $result]);
	} catch (\Exception $e) {
		print json_encode(["status" => "error", "message" => $e->getMessage()]);
	}


	// ============================================================
	// BATCH — UPLOAD SINGLE FILE TO TEMP DIR
	// Bypasses PHP max_file_uploads by sending files one at a time
	// ============================================================
} else if ($action == "batchUploadFile") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
		exit;
	}

	$sessionId = GETPOST('session_id', 'alphanohtml');
	if (empty($sessionId) || !preg_match('/^batch_[0-9]+_[a-z0-9]+$/', $sessionId)) {
		print json_encode(["status" => "error", "message" => "Invalid session ID"]);
		exit;
	}

	$receivedFile = isset($_FILES['file']) ? $_FILES['file'] : null;
	if (empty($receivedFile) || $receivedFile['error'] !== UPLOAD_ERR_OK) {
		$errCode = !empty($receivedFile['error']) ? $receivedFile['error'] : 'no file';
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchUploadError', '') . ' (code: ' . $errCode . ')']);
		exit;
	}

	$allowedMimes = array(
		'application/pdf',
		'image/png',
		'image/jpeg',
		'image/jpg',
		'image/tiff',
		'image/bmp',
		'image/webp'
	);
	$fileMime = $receivedFile['type'];
	if (!in_array($fileMime, $allowedMimes)) {
		print json_encode(["status" => "error", "message" => $langs->transnoentities('EasyOcrBatchInvalidType', $receivedFile['name'])]);
		exit;
	}

	$tempDir = DOL_DATA_ROOT . '/easyocr/temp/' . $sessionId;
	if (!is_dir($tempDir)) {
		dol_mkdir($tempDir);
	}

	$destPath = $tempDir . '/' . dol_sanitizeFileName($receivedFile['name']);
	$moveResult = dol_move_uploaded_file($receivedFile['tmp_name'], $destPath, 1, 1, $receivedFile['error'], 1, 'file');
	if (!($moveResult > 0)) {
		print json_encode(["status" => "error", "message" => $langs->transnoentities('EasyOcrBatchMoveError', $receivedFile['name'])]);
		exit;
	}

	print json_encode(["status" => "ok", "file" => basename($destPath)]);


	// ============================================================
	// BATCH — CREATE FROM PREVIOUSLY UPLOADED FILES
	// ============================================================
} else if ($action == "batchCreateFromUploads") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
		exit;
	}

	require_once __DIR__ . '/../lib/easyocr_autoload.php';

	$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
	$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';

	if (empty($apiKey)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoApiKey')]);
		exit;
	}

	$sessionId = GETPOST('session_id', 'alphanohtml');
	if (empty($sessionId) || !preg_match('/^batch_[0-9]+_[a-z0-9]+$/', $sessionId)) {
		print json_encode(["status" => "error", "message" => "Invalid session ID"]);
		exit;
	}

	$tempDir = DOL_DATA_ROOT . '/easyocr/temp/' . $sessionId;
	if (!is_dir($tempDir)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoFiles')]);
		exit;
	}

	// Collect all files from temp dir
	$filePaths = array();
	$dirHandle = opendir($tempDir);
	if ($dirHandle) {
		while (($entry = readdir($dirHandle)) !== false) {
			if ($entry === '.' || $entry === '..') continue;
			$fullPath = $tempDir . '/' . $entry;
			if (is_file($fullPath)) {
				$filePaths[] = $fullPath;
			}
		}
		closedir($dirHandle);
	}

	if (empty($filePaths)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoFiles')]);
		exit;
	}

	// Build options
	$options = array();
	$batchName = GETPOST('batch_name', 'alphanohtml');
	if (!empty($batchName)) $options['name'] = $batchName;

	$includeText = GETPOST('include_extracted_text', 'int');
	if ($includeText) $options['include_extracted_text'] = true;

	$autoCorrect = GETPOST('auto_correct', 'int');
	if ($autoCorrect) $options['auto_correct'] = true;

	$webhookUrl = GETPOST('webhook_url', 'alpha');
	if (!empty($webhookUrl)) $options['webhook_url'] = $webhookUrl;

	$language = GETPOST('language', 'atohtml');
	if (!empty($language)) $options['language'] = $language;

	$customInstructions = GETPOST('custom_instructions', 'restricthtml');
	if (!empty($customInstructions)) $options['custom_instructions'] = $customInstructions;
	/* var_dump($options);
	die(); */
	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
		$result = $client->batch()->create($filePaths, $options);

		// Cleanup temp files
		foreach ($filePaths as $fp) {
			@unlink($fp);
		}
		@rmdir($tempDir);

		print json_encode(["status" => "ok", "data" => $result]);
	} catch (\EasySoft\EasyOCR\Exceptions\EasyOCRException $e) {
		// Cleanup on error too
		foreach ($filePaths as $fp) {
			@unlink($fp);
		}
		@rmdir($tempDir);
		print json_encode(["status" => "error", "message" => $e->getMessage()]);
	} catch (\Exception $e) {
		foreach ($filePaths as $fp) {
			@unlink($fp);
		}
		@rmdir($tempDir);
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchApiError') . ': ' . $e->getMessage()]);
	}


	// ============================================================
	// BATCH — CANCEL
	// ============================================================
} else if ($action == "batchCancel") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
		exit;
	}

	require_once __DIR__ . '/../lib/easyocr_autoload.php';

	$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
	$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';
	$uuid = GETPOST('uuid', 'alphanohtml');

	if (empty($apiKey)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrBatchNoApiKey')]);
		exit;
	}
	if (empty($uuid)) {
		print json_encode(["status" => "error", "message" => "UUID required"]);
		exit;
	}

	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
		$result = $client->batch()->cancel($uuid);
		print json_encode(["status" => "ok", "data" => $result]);
	} catch (\Exception $e) {
		print json_encode(["status" => "error", "message" => $e->getMessage()]);
	}

	// ============================================================
	// CHECK IF INVOICE EXISTS BY REF_SUPPLIER
	// ============================================================
} else if ($action == "checkInvoiceExists") {

	$ref_supplier = GETPOST('ref_supplier', 'alphanohtml');
	$fk_soc = GETPOST('fk_soc', 'int');

	if (empty($ref_supplier)) {
		print json_encode(["status" => "error", "message" => "Invoice reference required"]);
		exit;
	}

	$sql = "SELECT f.rowid, f.ref, f.fk_soc, s.nom as supplier_name";
	$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn as f";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid";
	$sql .= " WHERE f.ref_supplier = '" . $db->escape($ref_supplier) . "'";
	$sql .= " AND f.entity IN (" . getEntity('supplier_invoice') . ")";

	if (!empty($fk_soc)) {
		$sql .= " AND f.fk_soc = " . ((int) $fk_soc);
	}

	$sql .= " LIMIT 1";

	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		print json_encode([
			"status" => "ok",
			"exists" => true,
			"invoice_id" => $obj->rowid,
			"invoice_ref" => $obj->ref,
			"supplier_id" => $obj->fk_soc,
			"supplier_name" => $obj->supplier_name
		]);
	} else {
		print json_encode([
			"status" => "ok",
			"exists" => false
		]);
	}

	// ============================================================
	// GET SUBSCRIPTION INFO (for polling)
	// ============================================================
} else if ($action == "getSubscriptionInfo") {

	require_once __DIR__ . '/../lib/easyocr_autoload.php';

	$aiService = new EasyOcrAI($db);
	if (!$aiService->isEnabled()) {
		print json_encode(["status" => "error", "message" => "AI not enabled"]);
		exit;
	}

	$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
	$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';

	if (empty($apiKey)) {
		print json_encode(["status" => "error", "message" => "API key not configured"]);
		exit;
	}

	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
		$accountData = $client->account()->me();
		$data = $accountData['data'] ?? null;

		if (empty($data)) {
			print json_encode(["status" => "error", "message" => "No subscription data"]);
			exit;
		}

		$plan = $data['plan'] ?? [];
		$quota = $data['quota'] ?? [];
		$wallet = $data['wallet'] ?? [];

		$pagesUsed = $quota['pages_used'] ?? 0;
		$pagesLimit = $quota['pages_limit'] ?? 0;
		$pagesRemaining = $quota['pages_remaining'] ?? 0;
		$usagePercentage = $quota['usage_percentage'] ?? 0;
		$resetDate = $quota['reset_date'] ?? '';
		$planName = $plan['name'] ?? '';
		$isFree = !empty($plan['is_free']);
		$hasWallet = !empty($wallet['exists']);
		$walletBalance = $wallet['balance_pages'] ?? 0;
		$percentage = $pagesLimit > 0 ? min(round(($pagesUsed / $pagesLimit) * 100, 1), 100) : 0;

		// Determine status
		$statusClass = 'ok';
		$statusText = '';
		if ($usagePercentage >= 100) {
			$statusClass = 'danger';
			$statusText = $langs->trans('EasyOcrQuotaExceeded');
		} elseif ($usagePercentage >= 80) {
			$statusClass = 'warning';
			$statusText = $langs->trans('EasyOcrQuotaNearLimit');
		}

		print json_encode([
			"status" => "ok",
			"pages_used" => $pagesUsed,
			"pages_limit" => $pagesLimit,
			"pages_remaining" => $pagesRemaining,
			"usage_percentage" => $usagePercentage,
			"percentage" => $percentage,
			"reset_date" => $resetDate ? dol_print_date(strtotime($resetDate), 'day') : '',
			"plan_name" => $planName,
			"is_free" => $isFree,
			"has_wallet" => $hasWallet,
			"wallet_balance" => $walletBalance,
			"status_class" => $statusClass,
			"status_text" => $statusText
		]);
	} catch (\Exception $e) {
		print json_encode(["status" => "error", "message" => $e->getMessage()]);
	}
}
