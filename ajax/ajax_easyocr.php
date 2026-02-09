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

top_httphead('application/json');

$langs->load('easyocr@easyocr');

// Security
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
	exit;
}

// --- Helpers ---

function convertFlexibleDate($fecha)
{
	$formatosPosibles = [
		'd/m/Y',
		'd/m/y',
		'Y-m-d',
		'm-d-Y',
		'd-m-Y',
		'Y/m/d',
		'd.m.Y',
		'm/d/Y'
	];
	foreach ($formatosPosibles as $formato) {
		$fechaObj = DateTime::createFromFormat($formato, $fecha);
		if ($fechaObj) {
			$anio = $fechaObj->format('y');
			if (strlen($fecha) <= 8 && $anio < 100) {
				$sigloActual = (int) date('Y') - (int) date('y');
				$anioCompleto = $sigloActual + (int) $anio;
				$fechaObj->setDate($anioCompleto, $fechaObj->format('m'), $fechaObj->format('d'));
			}
			return $fechaObj->format('Y-m-d');
		}
	}
	return date('Y-m-d');
}

function convertToNumber($numeroFormateado)
{
	$numero = trim($numeroFormateado);
	if (empty($numero)) {
		return 0;
	}
	$numero = preg_replace('/[^\d.,-]/', '', $numero);
	$puntos = substr_count($numero, '.');
	$comas = substr_count($numero, ',');

	if ($puntos == 0 && $comas == 0) {
		return floatval($numero);
	}
	if ($puntos == 0 && $comas == 1) {
		return floatval(str_replace(',', '.', $numero));
	}
	if ($comas == 0 && $puntos == 1) {
		return floatval($numero);
	}

	$ultimoPunto = strrpos($numero, '.');
	$ultimaComa = strrpos($numero, ',');
	if ($ultimaComa > $ultimoPunto) {
		$numero = str_replace('.', '', $numero);
		$numero = str_replace(',', '.', $numero);
	} else {
		$numero = str_replace(',', '', $numero);
	}
	return floatval($numero);
}

function calculateIVA($montoTotal, $montoIVA)
{
	if (empty($montoTotal) || $montoTotal == 0) {
		return 0;
	}
	return round(($montoIVA / $montoTotal) * 100, 3);
}


// --- Actions ---

$action = isset($_POST["action"]) ? $_POST["action"] : '';

// ============================================================
// CREAR FACTURA DE PROVEEDOR
// ============================================================
if ($action == "newInvoice") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$fk_soc = GETPOST("fk_soc", "int");
	$ref_supplier = GETPOST("ref_supplier", "alphanohtml");
	$datef_str = convertFlexibleDate(GETPOST("datef", "alphanohtml"));
	$total_ttc = convertToNumber(GETPOST("total_ttc", "alphanohtml"));
	$total_ht = convertToNumber(GETPOST("total_ht", "alphanohtml"));

	// Use IVA from OCR if provided, otherwise calculate
	$total_tva_ocr = GETPOST("total_tva", "alphanohtml");
	if (!empty($total_tva_ocr)) {
		$total_tva = convertToNumber($total_tva_ocr);
	} else {
		$total_tva = $total_ttc - $total_ht;
	}

	// Optional: description from OCR
	$ocr_description = GETPOST("description", "restricthtml");

	// Optional: due date from OCR
	$date_echeance_str = GETPOST("date_echeance", "alphanohtml");

	// Verificar duplicado por ref_supplier + proveedor
	$sql_check = "SELECT rowid, ref FROM " . MAIN_DB_PREFIX . "facture_fourn";
	$sql_check .= " WHERE ref_supplier = '" . $db->escape($ref_supplier) . "'";
	$sql_check .= " AND fk_soc = " . ((int) $fk_soc);
	$sql_check .= " AND entity IN (" . getEntity('supplier_invoice') . ")";
	$resql_check = $db->query($sql_check);
	if ($resql_check && $db->num_rows($resql_check) > 0) {
		$existingObj = $db->fetch_object($resql_check);
		print json_encode([
			"status" => "repeat",
			"existing_id" => $existingObj->rowid,
			"existing_ref" => $existingObj->ref,
			"existing_ref_supplier" => $ref_supplier
		]);
		exit;
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
		$date_ech = convertFlexibleDate($date_echeance_str);
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

	// AÃ±adir lÃ­nea de detalle
	$line_desc = !empty($ocr_description) ? $ocr_description : $langs->trans('EasyOcrInvoiceLineDesc');
	$tva_tx = calculateIVA($total_ht, $total_tva);
	$result = $facture->addline(
		$line_desc,   // description
		$total_ht,    // pu (precio unitario HT)
		$tva_tx,      // txtva
		0,            // txlocaltax1
		0,            // txlocaltax2
		1             // qty
	);

	if ($result <= 0) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorAddingLine') . ': ' . $facture->error]);
		exit;
	}

	// Validar la factura para generar la referencia definitiva
	$result = $facture->validate($user);
	if ($result <= 0) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorValidating') . ': ' . $facture->error]);
		exit;
	}

	// Re-fetch para obtener la ref generada
	$facture->fetch($newId);
	$ref = $facture->ref;

	// Subir archivo PDF
	if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {

		$ref = dol_sanitizeFileName($facture->ref);
		$reldir = 'fournisseur/facture/' . get_exdir($newId, 2, 0, 0, $facture, 'invoice_supplier') . $ref;
		$upload_dir = DOL_DATA_ROOT . '/' . $reldir;

		if (!dol_is_dir($upload_dir)) {
			dol_mkdir($upload_dir);
		}

		$fileName = dol_sanitizeFileName(basename($_FILES['file']['name']));
		$destFileName = $ref . '-' . $fileName;
		$destFullPath = $upload_dir . '/' . $destFileName;

		if (move_uploaded_file($_FILES['file']['tmp_name'], $destFullPath)) {
			// Registrar en ECM usando la clase nativa
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

	// Crear pago asociado si se solicitÃ³
	$create_payment = GETPOST('create_payment', 'alpha');
	if ($create_payment == '1' && GETPOST('payment_bank_id', 'int') > 0) {

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

	print json_encode(["status" => "ok", "id" => $newId, "ref" => $ref]);


	// ============================================================
	// OBTENER DATOS PARA EL FORMULARIO (proveedores, templates, bancos, pagos)
	// ============================================================
} else if ($action == "getDetails") {

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
	$sql = "SELECT t.rowid, t.name, t.fk_soc, s.nom as supplier_name";
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
				'supplier_name' => $obj->supplier_name
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
} else if ($action == "getDetailsTemplate") {

	$template_id = GETPOST("template_id", "int");

	$sql = "SELECT fk_soc, scale FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . ((int) $template_id);
	$resql = $db->query($sql);
	$tpl_data = $db->fetch_object($resql);
	$fk_soc = $tpl_data ? $tpl_data->fk_soc : null;
	$tpl_scale = $tpl_data && $tpl_data->scale ? floatval($tpl_data->scale) : 1.5;

	$sql = "SELECT objectNum, startX, startY, width, height, color, label";
	$sql .= " FROM " . MAIN_DB_PREFIX . "easyocr_template_details";
	$sql .= " WHERE fk_template = " . ((int) $template_id);
	$resql = $db->query($sql);
	$result = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result[] = array(
				'objectNum' => $obj->objectNum,
				'startX' => $obj->startX,
				'startY' => $obj->startY,
				'width' => $obj->width,
				'height' => $obj->height,
				'color' => $obj->color,
				'label' => $obj->label
			);
		}
	}

	print json_encode(["details" => $result, "fk_soc" => $fk_soc, "scale" => $tpl_scale]);


	// ============================================================
	// CREAR TEMPLATE
	// ============================================================
} else if ($action == "addTemplate") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$name = GETPOST("name", "alphanohtml");
	$fk_soc = GETPOST("fk_soc", "int");
	$tpl_scale = GETPOST("scale", "alpha");
	$tpl_scale = $tpl_scale ? floatval($tpl_scale) : 1.5;
	$selections = json_decode(GETPOST("selections", "restricthtml"), true);

	$db->begin();

	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (name, fk_soc, scale, date_creation)";
	$sql .= " VALUES ('" . $db->escape($name) . "', " . ($fk_soc > 0 ? ((int) $fk_soc) : "NULL") . ", " . $tpl_scale . ", NOW())";

	if (!$db->query($sql)) {
		$db->rollback();
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrErrorCreatingTemplate')]);
		exit;
	}

	$template_id = $db->last_insert_id(MAIN_DB_PREFIX . "easyocr_template");

	if (is_array($selections)) {
		foreach ($selections as $item) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details";
			$sql .= " (fk_template, objectNum, startX, startY, width, height, color, label)";
			$sql .= " VALUES (";
			$sql .= ((int) $template_id) . ", ";
			$sql .= ((int) $item['objectNum']) . ", ";
			$sql .= floatval($item['startX']) . ", ";
			$sql .= floatval($item['startY']) . ", ";
			$sql .= floatval($item['width']) . ", ";
			$sql .= floatval($item['height']) . ", ";
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
} else if ($action == "editTemplate") {

	if (!easyocrCheckRight($user, 'easyocr', 'write')) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$template_id = GETPOST("template_id", "int");
	$fk_soc = GETPOST("fk_soc", "int");
	$tpl_scale = GETPOST("scale", "alpha");
	$tpl_scale = $tpl_scale ? floatval($tpl_scale) : 1.5;
	$selections = json_decode(GETPOST("selections", "restricthtml"), true);

	$db->begin();

	$sql = "UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET fk_soc = " . ($fk_soc > 0 ? ((int) $fk_soc) : "NULL") . ", scale = " . $tpl_scale;
	$sql .= " WHERE rowid = " . ((int) $template_id);
	$db->query($sql);

	// Borrar detalles anteriores y reinsertar
	$sql = "DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template = " . ((int) $template_id);
	$db->query($sql);

	if (is_array($selections)) {
		foreach ($selections as $item) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details";
			$sql .= " (fk_template, objectNum, startX, startY, width, height, color, label)";
			$sql .= " VALUES (";
			$sql .= ((int) $template_id) . ", ";
			$sql .= ((int) $item['objectNum']) . ", ";
			$sql .= floatval($item['startX']) . ", ";
			$sql .= floatval($item['startY']) . ", ";
			$sql .= floatval($item['width']) . ", ";
			$sql .= floatval($item['height']) . ", ";
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

	// Search in societe table by siren, siret, ape, idprof4-6, tva_intra
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
	$sql .= " LIMIT 1";

	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		print json_encode(["status" => "ok", "fk_soc" => $obj->rowid, "name" => $obj->nom, "created" => false]);
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
				// Store in siren (CIF/NIF field in Spain/France, Tax ID elsewhere)
				$newSoc->siren = $cif;

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

	$fk_soc = GETPOST("fk_soc", "int");
	$ref_supplier = GETPOST("ref_supplier", "alphanohtml");
	$datef_str = convertFlexibleDate(GETPOST("datef", "alphanohtml"));
	$total_ttc_str = GETPOST("total_ttc", "alphanohtml");
	$total_ht_str = GETPOST("total_ht", "alphanohtml");
	$total_tva_str = GETPOST("total_tva", "alphanohtml");
	$date_echeance_str = GETPOST("date_echeance", "alphanohtml");
	$notes = GETPOST("notes", "restricthtml");
	$items_json = GETPOST("items", "restricthtml");

	// Default tax rate from document totals (fallback for lines with empty taxes)
	$default_tax_rate = floatval(GETPOST("default_tax_rate", "alpha"));

	// Supplier data from AI modal
	$supplier_name    = GETPOST('supplier_name', 'alphanohtml');
	$supplier_tax_id  = GETPOST('supplier_tax_id', 'alphanohtml');
	$supplier_address = GETPOST('supplier_address', 'alphanohtml');
	$supplier_city    = GETPOST('supplier_city', 'alphanohtml');
	$supplier_zip     = GETPOST('supplier_zip', 'alphanohtml');
	$supplier_country = GETPOST('supplier_country', 'alphanohtml');
	$supplier_phone   = GETPOST('supplier_phone', 'alphanohtml');
	$supplier_email   = GETPOST('supplier_email', 'alphanohtml');

	$supplier_created = false;
	$supplier_created_name = '';

	// ---- Resolve supplier if fk_soc not provided ----
	if (empty($fk_soc) && !empty($supplier_tax_id)) {
		$cif_clean = preg_replace('/[\s\-\.]/', '', trim($supplier_tax_id));

		// 1) Search as supplier
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

		// 2) Search as non-supplier (client) and upgrade
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
				
				// Update only fournisseur flag and code using SQL (preserves country and other fields)
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
			$newSoc = new Societe($db);
			$newSoc->name        = $supplier_name;
			$newSoc->client      = 0;
			$newSoc->fournisseur = 1;
			$newSoc->status      = 1;
			$newSoc->siren       = $supplier_tax_id;

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

			$createdId = $newSoc->create($user);
			if ($createdId > 0) {
				$fk_soc = $createdId;
				$supplier_created = true;
				$supplier_created_name = $newSoc->name;
			} else {
				// Build detailed error message
				$errorDetails = [];
				$errorDetails[] = "Main error: " . ($newSoc->error ?: 'Unknown error');

				if (!empty($newSoc->errors)) {
					$errorDetails[] = "Additional errors: " . implode(', ', $newSoc->errors);
				}

				$errorDetails[] = "Attempted data - Name: '" . ($newSoc->name ?: 'N/A') . "'";
				$errorDetails[] = "CIF/Tax ID: '" . ($supplier_tax_id ?: 'N/A') . "'";
				$errorDetails[] = "Country: '" . ($supplier_country ?: ($newSoc->country_code ?: 'N/A')) . "'";
				$errorDetails[] = "Supplier code: '" . ($newSoc->code_fournisseur ?: 'N/A') . "'";

				if (!empty($db->lasterror())) {
					$errorDetails[] = "DB error: " . $db->lasterror();
				}

				print json_encode(["status" => "error", "message" => "Error creating supplier. " . implode(' | ', $errorDetails)]);
				exit;
			}
		}
	}

	// Still no supplier? Error
	if (empty($fk_soc)) {
		print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAISupplierRequired')]);
		exit;
	}

	$total_ht = convertToNumber($total_ht_str);
	$total_ttc = convertToNumber($total_ttc_str);
	$total_tva = !empty($total_tva_str) ? convertToNumber($total_tva_str) : ($total_ttc - $total_ht);

	// Parse items
	$items = !empty($items_json) ? json_decode($items_json, true) : array();
	if (!is_array($items)) $items = array();

	// Additional params
	$invoice_status = GETPOST('invoice_status', 'alpha'); // 'draft' or 'validated'
	$invoice_type = GETPOST('invoice_type', 'int');       // 0=standard, 2=credit_note
	$journal_code = GETPOST('journal_code', 'alphanohtml');

	// Duplicate check — return existing invoice details
	$sql_check = "SELECT rowid, ref FROM " . MAIN_DB_PREFIX . "facture_fourn";
	$sql_check .= " WHERE ref_supplier = '" . $db->escape($ref_supplier) . "'";
	$sql_check .= " AND fk_soc = " . ((int) $fk_soc);
	$sql_check .= " AND entity IN (" . getEntity('supplier_invoice') . ")";
	$resql_check = $db->query($sql_check);
	if ($resql_check && $db->num_rows($resql_check) > 0) {
		$existingObj = $db->fetch_object($resql_check);
		print json_encode([
			"status" => "repeat",
			"existing_id" => $existingObj->rowid,
			"existing_ref" => $existingObj->ref,
			"existing_ref_supplier" => $ref_supplier
		]);
		exit;
	}

	// Auto-fill payment mode/conditions from supplier
	$supplier_payment_mode = 0;
	$supplier_payment_cond = 0;
	if (!empty($fk_soc)) {
		$socTmp = new Societe($db);
		$socTmp->fetch($fk_soc);
		if (!empty($socTmp->mode_reglement_supplier_id)) {
			$supplier_payment_mode = $socTmp->mode_reglement_supplier_id;
		}
		if (!empty($socTmp->cond_reglement_supplier_id)) {
			$supplier_payment_cond = $socTmp->cond_reglement_supplier_id;
		}
	}

	// Create invoice
	$facture = new FactureFournisseur($db);
	$facture->socid = $fk_soc;
	$facture->ref_supplier = $ref_supplier;
	$facture->type = (!empty($invoice_type) && in_array((int)$invoice_type, [0, 2, 3, 5])) ? (int)$invoice_type : 0;
	$facture->date = dol_mktime(
		12,
		0,
		0,
		date('m', strtotime($datef_str)),
		date('d', strtotime($datef_str)),
		date('Y', strtotime($datef_str))
	);
	$facture->multicurrency_code = $conf->currency;
	$facture->special_code = 0;
	$facture->import_key = 'easyocr-ai';

	// Set payment mode/conditions from supplier
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
		$date_ech = convertFlexibleDate($date_echeance_str);
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

	$sql = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET import_key = 'easyocr-ai'";
	if (!empty($journal_code)) {
		$sql .= ", fk_account = (SELECT rowid FROM " . MAIN_DB_PREFIX . "accounting_journal WHERE code = '" . $db->escape($journal_code) . "' AND entity = " . ((int)$conf->entity) . " LIMIT 1)";
	}
	$sql .= " WHERE rowid = " . ((int) $newId);
	$db->query($sql);

	// Add lines — full tax support (IVA/TVA, RE, IRPF) + product matching
	$lineErrors = array();
	if (!empty($items)) {
		$lineIndex = 0;
		foreach ($items as $item) {
			$lineIndex++;
			$desc = !empty($item['description']) ? $item['description'] : 'Línea';
			$qty = !empty($item['quantity']) ? floatval($item['quantity']) : 1;
			$unit_price = isset($item['unit_price']) && $item['unit_price'] !== '' ? convertToNumber($item['unit_price']) : 0;
			$discount = !empty($item['discount_percent']) ? floatval($item['discount_percent']) : 0;
			$itemType = isset($item['item_type']) ? strtolower(trim($item['item_type'])) : '';

			// Tax handling — parse taxes array from new API format
			$tva_rate = 0;
			$localtax1_rate = 0; // RE (Recargo de Equivalencia)
			$localtax2_rate = 0; // IRPF (retención)

			if (!empty($item['taxes']) && is_array($item['taxes'])) {
				foreach ($item['taxes'] as $tax) {
					$taxType = strtolower($tax['tax_type'] ?? '');
					$taxRate = floatval($tax['tax_rate'] ?? 0);
					if (in_array($taxType, ['tva', 'iva', 'vat'])) {
						$tva_rate = $taxRate;
					} elseif ($taxType === 're') {
						$localtax1_rate = $taxRate;
					} elseif ($taxType === 'irpf') {
						$localtax2_rate = -abs($taxRate); // IRPF is negative (withholding)
					}
				}
			}

			// Fallback: if taxes array didn't yield a rate, check flat fields
			if ($tva_rate == 0 && !empty($item['tax_rate'])) {
				$tva_rate = floatval($item['tax_rate']);
			}
			if ($localtax1_rate == 0 && !empty($item['re_rate'])) {
				$localtax1_rate = floatval($item['re_rate']);
			}
			if ($localtax2_rate == 0 && !empty($item['irpf_rate'])) {
				$localtax2_rate = -abs(floatval($item['irpf_rate']));
			}

			// Final fallback: use document's default tax rate if line has no IVA
			if ($tva_rate == 0 && $default_tax_rate > 0) {
				$tva_rate = $default_tax_rate;
				dol_syslog("EasyOCR: Line #$lineIndex using default_tax_rate=$default_tax_rate (line had empty taxes)", LOG_DEBUG);
			}

			// Calculate unit_price from net_amount or total if missing
			if ($unit_price == 0 && !empty($item['net_amount'])) {
				$net = convertToNumber($item['net_amount']);
				$unit_price = $net / ($qty > 0 ? $qty : 1);
				if ($discount > 0) {
					$unit_price = $unit_price / (1 - $discount / 100);
				}
			}
			if ($unit_price == 0 && !empty($item['total'])) {
				$lineTotal = convertToNumber($item['total']);
				$lineTaxAmt = 0;
				if (!empty($item['taxes']) && is_array($item['taxes'])) {
					foreach ($item['taxes'] as $tax) {
						$lineTaxAmt += floatval($tax['tax_amount'] ?? 0);
					}
				} elseif (!empty($item['tax_amount'])) {
					$lineTaxAmt = convertToNumber($item['tax_amount']);
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
					$prodId = $newProduct->create($user);
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

			dol_syslog("EasyOCR addline #$lineIndex: desc=$desc, pu=$unit_price, tva=$tva_rate, ltx1=$localtax1_rate, ltx2=$localtax2_rate, qty=$qty, fk_prod=$fk_product, disc=$discount, type=$line_type, itemType=$itemType", LOG_DEBUG);

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
		$tva_tx = calculateIVA($total_ht, $total_tva);
		$facture->addline(
			$langs->trans('EasyOcrInvoiceLineDesc'),
			$total_ht,       // pu
			$tva_tx,         // txtva
			0,               // txlocaltax1
			0,               // txlocaltax2
			1,               // qty
			0,               // fk_product
			0,               // remise_percent
			'',              // date_start
			'',              // date_end
			0,               // ventil
			'',              // info_bits
			'HT',            // price_base_type
			0                // type
		);
	}

	// Update invoice totals with OCR values (before validation) using raw query
	// This ensures totals match the original document even if line calculations differ slightly
	$ocr_total_ht = $total_ht;
	$ocr_total_tva = $total_tva;
	$ocr_total_ttc = $total_ttc;
	$ocr_localtax1 = convertToNumber(GETPOST('total_localtax1', 'alphanohtml')); // RE (Recargo Equivalencia)
	$ocr_localtax2 = convertToNumber(GETPOST('total_localtax2', 'alphanohtml')); // IRPF (Retención) - negative

	$sql_totals = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET ";
	$sql_totals .= " total_ht = " . ((float) $ocr_total_ht);
	$sql_totals .= ", tva = " . ((float) $ocr_total_tva);
	$sql_totals .= ", total_ttc = " . ((float) $ocr_total_ttc);
	if ($ocr_localtax1 != 0) {
		$sql_totals .= ", localtax1 = " . ((float) $ocr_localtax1);
	}
	if ($ocr_localtax2 != 0) {
		// IRPF is stored as negative (withholding reduces total)
		$sql_totals .= ", localtax2 = " . ((float) -abs($ocr_localtax2));
	}
	$sql_totals .= " WHERE rowid = " . ((int) $newId);
	$db->query($sql_totals);

	dol_syslog("EasyOCR AI: Updated invoice totals - HT: $ocr_total_ht, TVA: $ocr_total_tva, TTC: $ocr_total_ttc, LTX1: $ocr_localtax1, LTX2: $ocr_localtax2", LOG_DEBUG);

	// Validate or leave as draft
	$ref = '(PROV' . $newId . ')';
	if ($invoice_status !== 'draft') {
		$result = $facture->validate($user);
		if ($result <= 0) {
			$errMsg = $langs->trans('EasyOcrErrorValidating') . ': ' . $facture->error;
			if (!empty($lineErrors)) {
				$errMsg .= ' | Line errors: ' . implode('; ', $lineErrors);
			}
			print json_encode(["status" => "error", "message" => $errMsg]);
			exit;
		}
		$facture->fetch($newId);
		$ref = $facture->ref;
	} else {
		// Even for drafts, refetch to get calculated totals
		$facture->fetch($newId);
	}

	// Upload PDF
	if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
		$ref_clean = dol_sanitizeFileName($ref);
		$reldir = 'fournisseur/facture/' . get_exdir($newId, 2, 0, 0, $facture, 'invoice_supplier') . $ref_clean;
		$upload_dir = DOL_DATA_ROOT . '/' . $reldir;

		if (!dol_is_dir($upload_dir)) {
			dol_mkdir($upload_dir);
		}

		$fileName = dol_sanitizeFileName(basename($_FILES['file']['name']));
		$destFileName = $ref_clean . '-' . $fileName;
		$destFullPath = $upload_dir . '/' . $destFileName;

		if (move_uploaded_file($_FILES['file']['tmp_name'], $destFullPath)) {
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

	// Payment — use the invoice's calculated total (not the modal's header total)
	$create_payment = GETPOST('create_payment', 'alpha');
	if ($create_payment == '1' && GETPOST('payment_bank_id', 'int') > 0) {
		// Only create payment if invoice is validated
		if ($invoice_status !== 'draft') {
			$payment_bank_id = GETPOST('payment_bank_id', 'int');
			$payment_type_id = GETPOST('payment_type_id', 'int') > 0 ? GETPOST('payment_type_id', 'int') : 6;

			// Use the invoice's real total_ttc to avoid overpayment
			$paymentAmount = $facture->total_ttc;

			$paiement = new PaiementFourn($db);
			$paiement->datepaye = $facture->date;
			$paiement->amounts = array($newId => $paymentAmount);
			$paiement->multicurrency_amounts = array($newId => $paymentAmount);
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
	}

	print json_encode([
		"status" => "ok",
		"id" => $newId,
		"ref" => $ref,
		"supplier_created" => $supplier_created,
		"supplier_name" => $supplier_created_name,
		"is_draft" => ($invoice_status === 'draft'),
		"line_errors" => $lineErrors
	]);


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

	if (empty($base64Data)) {
		print json_encode(["status" => "error", "message" => "No PDF data provided"]);
		exit;
	}

	$result = $aiService->processBase64($base64Data);

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

	if (empty($base64Data)) {
		header('Content-Type: text/event-stream');
		echo "event: error\ndata: " . json_encode(["message" => "No PDF data"]) . "\n\n";
		exit;
	}

	// SSE headers
	header('Content-Type: text/event-stream');
	header('Cache-Control: no-cache');
	header('Connection: keep-alive');
	header('X-Accel-Buffering: no');          // nginx
	header('Content-Encoding: none');         // disable mod_deflate

	// Disable all output buffering
	@ini_set('output_buffering', 'off');
	@ini_set('zlib.output_compression', false);
	while (ob_get_level()) {
		ob_end_clean();
	}
	ob_implicit_flush(true);

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
		CURLOPT_POSTFIELDS     => json_encode([
			'base64_data'  => $base64Data,
			'filename'     => $filename,
			'include_text' => false
		]),
		CURLOPT_RETURNTRANSFER => false,
		CURLOPT_TIMEOUT        => 120,
		CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
			echo $chunk;
			flush();
			return strlen($chunk);
		}
	]);

	$ok = curl_exec($ch);

	if (!$ok || curl_errno($ch)) {
		$errMsg = curl_error($ch) ?: 'Connection failed';
		echo "event: error\ndata: " . json_encode(["message" => $errMsg]) . "\n\n";
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

}
