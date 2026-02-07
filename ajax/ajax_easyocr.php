<?php

/**
 * EasyOcr - AJAX Handler
 * Copyright (C) 2024 EasySoft Tech S.L.
 *
 * @author      Alberto Luque Rivas <aluquerivasdev@gmail.com>
 * @link        https://www.easysoft.es
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
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}

// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {$i--;
    $j--;}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}

if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}

// Try main.inc.php using relative path
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

top_httphead('application/json');


function separateNumber($numero)
{
    if ($numero < 10) {
        $numeroStr = '0' . $numero;
    } else {
        $numeroStr = strval($numero);
    }
    
    // Tomar los últimos 2 dígitos y INVERTIR el orden
    $ultimosDosDigitos = substr($numeroStr, -2);
    $primerDigito = substr($ultimosDosDigitos, 0, 1);
    $segundoDigito = substr($ultimosDosDigitos, 1, 1);
    
    // INVERTIR: segundo primero, primer segundo
    return $segundoDigito . '/' . $primerDigito;
}

function calculateIVA($montoTotal, $montoIVA)
{
	if (empty($montoTotal) || $montoTotal == 0) {
		return '0.00';
	}
	$porcentajeIVA = ($montoIVA / $montoTotal) * 100;
	return number_format($porcentajeIVA, 2);
}

function generateRef($invoiceNumber)
{
	$year = date("y");
	$month = date("m");
	$formattedInvoiceNumber = str_pad($invoiceNumber, 4, "0", STR_PAD_LEFT);
	$ref = "SI" . $year . $month . "-" . $formattedInvoiceNumber;
	return $ref;
}

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
    
    // Eliminar símbolos de moneda y caracteres no numéricos (excepto dígitos, puntos, comas y signo negativo)
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



$action = $_POST["action"];


if ($action == "newInvoice") {

	$fk_soc = $_POST["fk_soc"];
	$ref_supplier = $_POST["ref_supplier"];
	$datef = convertFlexibleDate($_POST["datef"]);
	$total_ttc = convertToNumber($_POST["total_ttc"]);
	$total_ht = convertToNumber($_POST["total_ht"]);
	$total_tva = $total_ttc - $total_ht;

	$facture = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier='" . $ref_supplier . "'");
	$number_facture = $db->num_rows($facture);

	if ($number_facture > 0) {
		print json_encode(["status" => "repeat"]);
	} else {

		$db->query("INSERT INTO " . MAIN_DB_PREFIX . "facture_fourn
	(
		ref,
		ref_supplier,
		fk_soc,
		datec,
		datef,
		date_valid,
		tms,
		total_ht,
		total_tva,
		total_ttc,
		fk_statut,
		fk_user_author,
		fk_user_valid,
		multicurrency_code,
		multicurrency_total_ht,
		multicurrency_total_tva,
		multicurrency_total_ttc,
		import_key
	)
	VALUES
	(
		'xxxx',
		'" . $ref_supplier . "',
		" . $fk_soc . ",
		CURRENT_TIMESTAMP,
		'" . $datef . "',
		CURRENT_DATE,
		CURRENT_TIMESTAMP,
		" . $total_ht . ",
		" . $total_tva . ",
		" . $total_ttc . ",
		1,
		" . $user->id . ",
		" . $user->id . ",
		'EUR',
		" . $total_ht . ",
		" . $total_tva . ",
		" . $total_ttc . ",
		'easyocr'
	)");

		$insert = $db->query("SELECT LAST_INSERT_ID() as id");
		$last_record = $db->fetch_object($insert);
		$newId = $last_record->id;

		$ref = generateRef($newId);
		$db->query("UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET ref='" . $ref . "' WHERE rowid=" . $newId);

		$iva = calculateIVA($total_ht, $total_tva);

		$db->query("INSERT INTO " . MAIN_DB_PREFIX . "facture_fourn_det(
		fk_facture_fourn,
		description,
		pu_ht,
		pu_ttc,
		qty,
		tva_tx,
		total_ht,
		tva,
		total_ttc,
		rang,
		multicurrency_code,
		multicurrency_subprice,
		multicurrency_total_ht,
		multicurrency_total_tva,
		multicurrency_total_ttc
	)
	VALUES
	(
		" . $newId . ",
		'mira el detalle en los archivos adjuntos',
		" . $total_ht . ",
		" . $total_ttc . ",
		1,
		" . $iva . ",
		" . $total_ht . ",
		" . $total_tva . ",
		" . $total_ttc . ",
		1,
		'EUR',
		" . $total_ht . ",
		" . $total_ht . ",
		" . $total_tva . ",
		" . $total_ttc . "
	)");


		if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {

			$filepath = 'fournisseur/facture/' . separateNumber($newId) . '/' . $ref;
			$uploadDir = DOL_DATA_ROOT . '/' . $filepath . '/';
			$fileName = basename($_FILES['file']['name']);
			$uploadFilePath = $uploadDir . $ref . '-' . $fileName;

			if (!dol_is_dir($uploadDir)) {
				dol_mkdir($uploadDir);
			}

			if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadFilePath)) {

				$db->query("INSERT INTO " . MAIN_DB_PREFIX . "ecm_files(
				ref,
				label,
				filepath,
				filename,
				src_object_type,
				src_object_id,
				fullpath_orig,
				position,
				gen_or_uploaded,
				date_c,
				tms,
				fk_user_c
			)
			VALUES
			(
				'" . md5($ref) . "',
				'" . md5($fileName) . "',
				'" . $filepath . "',
				'" . $ref . "-" . $fileName . "',
				'facture_fourn',
				" . $newId . ",
				'" . $fileName . "',
				1,
				'uploaded',
				CURRENT_TIMESTAMP,
				CURRENT_TIMESTAMP,
				" . $user->id . "
			)");
			}
		}

		// Crear pago asociado si se solicitó
		$create_payment = isset($_POST['create_payment']) ? $_POST['create_payment'] : '0';
		if ($create_payment == '1' && !empty($_POST['payment_bank_id'])) {
			require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
			require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

			$payment_bank_id = intval($_POST['payment_bank_id']);
			$payment_type_id = !empty($_POST['payment_type_id']) ? intval($_POST['payment_type_id']) : 6; // 6 = VIR (transferencia)

			$paiement = new PaiementFourn($db);
			$paiement->datepaye = dol_mktime(12, 0, 0, date('m', strtotime($datef)), date('d', strtotime($datef)), date('Y', strtotime($datef)));
			$paiement->amounts = array($newId => $total_ttc);
			$paiement->multicurrency_amounts = array($newId => $total_ttc);
			$paiement->multicurrency_code = array($newId => 'EUR');
			$paiement->multicurrency_tx = array($newId => 1);
			$paiement->paiementid = $payment_type_id;
			$paiement->num_payment = $ref_supplier;
			$paiement->note_private = 'Pago generado automáticamente por EasyOcr';
			$paiement->fk_account = $payment_bank_id;

			$paiement_id = $paiement->create($user, 1);
			if ($paiement_id > 0) {
				$paiement->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', $payment_bank_id, '', '');
			}
		}

		print json_encode(["status" => "ok", "id" => $newId]);
	}
	

} else if ($action == "getDetails") {

	$get_suppliers = $db->query("SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE fournisseur = 1");
	$num_suppliers = $db->num_rows($get_suppliers);
	$result_suppliers = array();

	for ($i = 0; $i < $num_suppliers; $i++) {
		$result_suppliers[] = $get_suppliers->fetch_assoc();
	}

	$get_templates = $db->query("SELECT t.rowid, t.name, t.fk_soc, s.nom as supplier_name FROM " . MAIN_DB_PREFIX . "easyocr_template t LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON t.fk_soc = s.rowid");
	$num_templates = $db->num_rows($get_templates);
	$result_templates = array();

	for ($i = 0; $i < $num_templates; $i++) {
		$result_templates[] = $get_templates->fetch_assoc();
	}

	// Obtener cuentas bancarias activas
	$get_banks = $db->query("SELECT rowid, label, number, currency_code FROM " . MAIN_DB_PREFIX . "bank_account WHERE clos = 0 AND entity = " . $conf->entity . " ORDER BY label");
	$num_banks = $db->num_rows($get_banks);
	$result_banks = array();

	for ($i = 0; $i < $num_banks; $i++) {
		$result_banks[] = $get_banks->fetch_assoc();
	}

	// Obtener tipos de pago con traducciones correctas de Dolibarr
	$get_payment_types = $db->query("SELECT id, code, libelle as label FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 ORDER BY libelle");
	$num_payment_types = $db->num_rows($get_payment_types);
	$result_payment_types = array();

	// Procesar y traducir los métodos de pago usando el sistema de Dolibarr (igual que html.form.class.php línea 4212)
	for ($i = 0; $i < $num_payment_types; $i++) {
		$row = $get_payment_types->fetch_assoc();
		// Usar la traducción de Dolibarr: PaymentTypeShort + código (ej: PaymentTypeShortCHQ)
		$translated_label = $langs->transnoentitiesnoconv("PaymentTypeShort" . $row['code']);
		// Si la traducción no existe o es igual a la clave, usar el libelle original
		if ($translated_label === "PaymentTypeShort" . $row['code']) {
			$translated_label = ($row['label'] != '-' ? $row['label'] : '');
		}
		$result_payment_types[] = array(
			'id' => $row['id'],
			'code' => $row['code'],
			'label' => $translated_label
		);
	}

	print json_encode(["suppliers" => $result_suppliers, "templates" => $result_templates, "banks" => $result_banks, "payment_types" => $result_payment_types]);

} else if ($action == "getDetailsTemplate") {

	$template_id = $_POST["template_id"];

	$tpl = $db->query("SELECT fk_soc FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid=" . $template_id);
	$tpl_data = $db->fetch_object($tpl);
	$fk_soc = $tpl_data ? $tpl_data->fk_soc : null;

	$details = $db->query("SELECT objectNum, startX, startY, width, height, color, label FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template=" . $template_id);
	$num = $db->num_rows($details);
	$result = array();

	for ($i = 0; $i < $num; $i++) {
		$result[] = $details->fetch_assoc();
	}

	print json_encode(["details" => $result, "fk_soc" => $fk_soc]);

} else if ($action == "addTemplate") {

	$name = $_POST["name"];
	$fk_soc = !empty($_POST["fk_soc"]) ? intval($_POST["fk_soc"]) : "NULL";
	$selections = json_decode($_POST["selections"], true);

	$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template(
		name,
		fk_soc
	)
	VALUES
	(
		'" . $name . "',
		" . $fk_soc . "
	)");

	$template = $db->query("SELECT LAST_INSERT_ID() as id");
	$template_new = $db->fetch_object($template);

	if (is_array($selections)) {
		foreach ($selections as $item) {
			$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details(
				fk_template,
				objectNum,
				startX,
				startY,
				width,
				height,
				color,
				label
			)
			VALUES
			(
				" . $template_new->id . ",
				" . $item['objectNum'] . ",
				" . $item['startX'] . ",
				" . $item['startY'] . ",
				" . $item['width'] . ",
				" . $item['height'] . ",
				'" . $item['color'] . "',
				'" . $item['label'] . "'
			)");
		}
	}

	print json_encode(["status" => "ok"]);

} else if ($action == "editTemplate") {

	$template_id = $_POST["template_id"];
	$fk_soc = !empty($_POST["fk_soc"]) ? intval($_POST["fk_soc"]) : "NULL";
	$selections = json_decode($_POST["selections"], true);

	$db->query("UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET fk_soc=" . $fk_soc . " WHERE rowid=" . $template_id);
	$db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template=" . $template_id);

	if (is_array($selections)) {
		foreach ($selections as $item) {
			$db->query("INSERT INTO " . MAIN_DB_PREFIX . "easyocr_template_details(
				fk_template,
				objectNum,
				startX,
				startY,
				width,
				height,
				color,
				label
			)
			VALUES
			(
				" . $template_id . ",
				" . $item['objectNum'] . ",
				" . $item['startX'] . ",
				" . $item['startY'] . ",
				" . $item['width'] . ",
				" . $item['height'] . ",
				'" . $item['color'] . "',
				'" . $item['label'] . "'
			)");
		}
	}

	print json_encode(["status" => "ok"]);
}
