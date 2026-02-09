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
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

top_httphead('application/json');

$langs->load('easyocr@easyocr');

// Security
if (!$user->rights->easyocr->read) {
	print json_encode(["status" => "error", "message" => $langs->trans('EasyOcrAccessDenied')]);
	exit;
}

// --- Helpers ---

function convertFlexibleDate($fecha)
{
	$formatosPosibles = [
		'd/m/Y', 'd/m/y', 'Y-m-d', 'm-d-Y', 'd-m-Y',
		'Y/m/d', 'd.m.Y', 'm/d/Y'
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

	if (!$user->rights->easyocr->write) {
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
	$sql_check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn";
	$sql_check .= " WHERE ref_supplier = '" . $db->escape($ref_supplier) . "'";
	$sql_check .= " AND fk_soc = " . ((int) $fk_soc);
	$sql_check .= " AND entity IN (" . getEntity('supplier_invoice') . ")";
	$resql_check = $db->query($sql_check);
	if ($resql_check && $db->num_rows($resql_check) > 0) {
		print json_encode(["status" => "repeat"]);
		exit;
	}

	// Crear factura usando el objeto nativo
	$facture = new FactureFournisseur($db);
	$facture->socid = $fk_soc;
	$facture->ref_supplier = $ref_supplier;
	$facture->date = dol_mktime(12, 0, 0,
		date('m', strtotime($datef_str)),
		date('d', strtotime($datef_str)),
		date('Y', strtotime($datef_str))
	);
	$facture->multicurrency_code = $conf->currency;
	$facture->import_key = 'easyocr';

	// Set due date if provided
	if (!empty($date_echeance_str)) {
		$date_ech = convertFlexibleDate($date_echeance_str);
		$facture->date_echeance = dol_mktime(12, 0, 0,
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

	// Añadir línea de detalle
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

	// Crear pago asociado si se solicitó
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

	print json_encode([
		"suppliers" => $result_suppliers,
		"templates" => $result_templates,
		"banks" => $result_banks,
		"payment_types" => $result_payment_types
	]);


// ============================================================
// OBTENER DETALLES DE UN TEMPLATE
// ============================================================
} else if ($action == "getDetailsTemplate") {

	$template_id = GETPOST("template_id", "int");

	$sql = "SELECT fk_soc FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . ((int) $template_id);
	$resql = $db->query($sql);
	$tpl_data = $db->fetch_object($resql);
	$fk_soc = $tpl_data ? $tpl_data->fk_soc : null;

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

	print json_encode(["details" => $result, "fk_soc" => $fk_soc]);


// ============================================================
// CREAR TEMPLATE
// ============================================================
} else if ($action == "addTemplate") {

	if (!$user->rights->easyocr->write) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$name = GETPOST("name", "alphanohtml");
	$fk_soc = GETPOST("fk_soc", "int");
	$selections = json_decode(GETPOST("selections", "restricthtml"), true);

	$db->begin();

	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template (name, fk_soc, date_creation)";
	$sql .= " VALUES ('" . $db->escape($name) . "', " . ($fk_soc > 0 ? ((int) $fk_soc) : "NULL") . ", NOW())";

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

	if (!$user->rights->easyocr->write) {
		print json_encode(["status" => "error", "message" => "Sin permiso de escritura"]);
		exit;
	}

	$template_id = GETPOST("template_id", "int");
	$fk_soc = GETPOST("fk_soc", "int");
	$selections = json_decode(GETPOST("selections", "restricthtml"), true);

	$db->begin();

	$sql = "UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET fk_soc = " . ($fk_soc > 0 ? ((int) $fk_soc) : "NULL");
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

	// Search in societe table by siren, siret, idprof1-6, tva_intra
	$sql = "SELECT s.rowid, s.nom FROM " . MAIN_DB_PREFIX . "societe s";
	$sql .= " WHERE s.fournisseur = 1";
	$sql .= " AND s.entity IN (" . getEntity('societe') . ")";
	$sql .= " AND (";
	$sql .= " REPLACE(REPLACE(REPLACE(s.siren, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.siret, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	$sql .= " OR REPLACE(REPLACE(REPLACE(s.tva_intra, ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	for ($i = 1; $i <= 6; $i++) {
		$sql .= " OR REPLACE(REPLACE(REPLACE(s.idprof" . $i . ", ' ', ''), '-', ''), '.', '') = '" . $db->escape($cif_clean) . "'";
	}
	$sql .= ")";
	$sql .= " LIMIT 1";

	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		print json_encode(["status" => "ok", "fk_soc" => $obj->rowid, "name" => $obj->nom]);
	} else {
		print json_encode(["status" => "not_found"]);
	}

}
