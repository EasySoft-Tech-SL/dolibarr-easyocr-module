<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       invoices.php
 * \ingroup    easyocr
 * \brief      List page for invoices created by EasyOcr
 */

// Removing the mark changes data: its token is checked whatever MAIN_SECURITY_CSRF_WITH_TOKEN
// says (below level 2, Dolibarr does not check GET actions and a forged link would work).
if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], array('delete', 'confirm_delete'), true) && !defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once __DIR__.'/lib/easyocr.lib.php';

// Every import_key EasyOcr stamps on the supplier invoices it creates: manual OCR,
// AI viewer and batch webhook. The list, the filters and the "remove mark" actions
// all use this same list, so none of them leaves a kind of invoice out.
$easyocrImportKeys = array('easyocr', 'easyocr-ai', 'easyocr-wh');
$easyocrImportKeysSql = "'".implode("','", $easyocrImportKeys)."'";

// Load translation files
$langs->loadLangs(array("easyocr@easyocr", "bills", "other"));

// Get parameters
$action     = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$confirm    = GETPOST('confirm', 'alpha');
$cancel     = GETPOST('cancel', 'alpha');
$toselect   = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'easyocrinvoiceslist';
$optioncss  = GETPOST('optioncss', 'aZ');

// Search filters
$search_ref = GETPOST('search_ref', 'alpha');
$search_supplier_ref = GETPOST('search_supplier_ref', 'alpha');
$search_supplier = GETPOST('search_supplier', 'int');
$search_import_key = GETPOST('search_import_key', 'alpha');
// Status filter: '0' draft (= waiting for review), '1' validated unpaid, '2' paid, '3' abandoned.
// A string, because '0' is a real value and empty('0') would drop it.
$search_status = (string) GETPOST('search_status', 'alpha');
if (!in_array($search_status, array('0', '1', '2', '3'), true)) {
	$search_status = '';
}
if (!in_array($search_import_key, $easyocrImportKeys, true)) {
	$search_import_key = '';
}

// Pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

if (!$sortfield) {
	$sortfield = "c.rowid";
}
if (!$sortorder) {
	$sortorder = "DESC";
}

// Initialize objects
$form = new Form($db);

// Security check
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	accessforbidden();
}
$permissiontoread = easyocrCheckRight($user, 'easyocr', 'read');
$permissiontodelete = easyocrCheckRight($user, 'easyocr', 'delete');


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
	$action = 'list';
	$massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

// Purge search criteria
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_ref = '';
	$search_supplier_ref = '';
	$search_supplier = '';
	$search_import_key = '';
	$search_status = '';
	$toselect = array();
}
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
	|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
	$massaction = '';
}

// Filters kept across pages, sorting and the confirmation of the actions
$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($optioncss != '') {
	$param .= '&optioncss='.urlencode($optioncss);
}
if ($search_ref != '') {
	$param .= '&search_ref='.urlencode($search_ref);
}
if ($search_supplier_ref != '') {
	$param .= '&search_supplier_ref='.urlencode($search_supplier_ref);
}
if ($search_supplier > 0) {
	$param .= '&search_supplier='.((int) $search_supplier);
}
if ($search_import_key != '') {
	$param .= '&search_import_key='.urlencode($search_import_key);
}
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
}

/**
 * Remove the EasyOcr mark of one supplier invoice.
 *
 * @param  DoliDB $db      Database handler
 * @param  int    $id      Supplier invoice id
 * @param  string $keysSql EasyOcr import keys, quoted and comma separated
 * @return int             Rows changed (0: not an EasyOcr invoice of this entity), -1 on SQL error
 */
function easyocrInvoicesRemoveMark($db, $id, $keysSql)
{
	$sql = "UPDATE ".MAIN_DB_PREFIX."facture_fourn SET import_key = NULL";
	$sql .= " WHERE rowid = ".((int) $id)." AND import_key IN (".$keysSql.")";
	$sql .= " AND entity IN (".getEntity('facture_fourn').")";
	$resql = $db->query($sql);
	if (!$resql) {
		return -1;
	}

	return (int) $db->affected_rows($resql);
}

// Mass delete (remove easyocr mark)
if ($massaction == 'delete' && $permissiontodelete) {
	if (!empty($toselect)) {
		$db->begin();
		$error = 0;
		$changed = 0;
		foreach ($toselect as $toselectid) {
			$res = easyocrInvoicesRemoveMark($db, (int) $toselectid, $easyocrImportKeysSql);
			if ($res < 0) {
				$error++;
			} else {
				$changed += $res;
			}
		}
		if (!$error && $changed > 0) {
			$db->commit();
			setEventMessages($langs->trans('EasyOcrRecordsDeleted'), null, 'mesgs');
		} else {
			$db->rollback();
			setEventMessages($langs->trans($error ? 'EasyOcrErrorDeletingRecords' : 'EasyOcrRemoveMarkNothingChanged'), null, 'errors');
		}
		$massaction = '';
		$action = '';
	}
}

// Delete single record (remove easyocr mark). Reached through formconfirm, which sends the token.
if ($action == 'confirm_delete' && $confirm == 'yes' && $permissiontodelete) {
	$res = easyocrInvoicesRemoveMark($db, GETPOST('id', 'int'), $easyocrImportKeysSql);
	if ($res > 0) {
		setEventMessages($langs->trans('EasyOcrRecordDeleted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].($param !== '' ? '?'.ltrim($param, '&') : ''));
		exit;
	}
	setEventMessages($langs->trans($res < 0 ? 'EasyOcrErrorDeletingRecords' : 'EasyOcrRemoveMarkNothingChanged'), null, 'errors');
	$action = '';
}


/*
 * View
 */

$title = $langs->trans("EasyOcrInvoicesTitle");
$help_url = '';
$morejs = array();
$morecss = array();

// Build and execute select
$sql = "SELECT c.rowid, c.ref, c.ref_supplier, c.datef, c.datec, c.total_ht, c.total_ttc, c.fk_soc, c.import_key, c.paye, c.fk_statut, c.type,";
$sql .= " d.nom as supplier_name";
$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as c";
$sql .= " JOIN ".MAIN_DB_PREFIX."societe as d ON c.fk_soc = d.rowid";
$sql .= " WHERE c.import_key IN (".$easyocrImportKeysSql.")";
$sql .= " AND c.entity IN (".getEntity('facture_fourn').")";
if ($search_status !== '') {
	$sql .= " AND c.fk_statut = ".((int) $search_status);
}

// Filters
if ($search_ref) {
	$sql .= natural_search("c.ref", $search_ref);
}
if ($search_supplier_ref) {
	$sql .= natural_search("c.ref_supplier", $search_supplier_ref);
}
if ($search_supplier > 0) {
	$sql .= " AND c.fk_soc = ".((int) $search_supplier);
}
if ($search_import_key !== '') {
	$sql .= " AND c.import_key = '".$db->escape($search_import_key)."'";
}

// Count total nb of records
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/^SELECT[\s\S]*FROM/Ui', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
	$resql = $db->query($sqlforcount);
	if ($resql) {
		$objforcount = $db->fetch_object($resql);
		$nbtotalofrecords = $objforcount->nbtotalofrecords;
	} else {
		dol_print_error($db);
	}

	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
	$db->free($resql);
}

// Complete request and execute it with limit
$sql .= $db->order($sortfield, $sortorder);
if ($limit) {
	$sql .= $db->plimit($limit + 1, $offset);
}

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);


// Output page
llxHeader('', $title, $help_url, '', 0, 0, $morejs, $morecss, '', 'bodyforlist');

$arrayofselected = is_array($toselect) ? $toselect : array();

// Status labels come from Dolibarr itself, so they match the supplier invoice card
$facturestatic = new FactureFournisseur($db);
$statusOptions = array(
	'0' => $langs->trans('EasyOcrInvoicesPendingReview'),
	'1' => $facturestatic->LibStatut(0, 1, 1),
	'2' => $facturestatic->LibStatut(1, 2, 1),
	'3' => $facturestatic->LibStatut(0, 3, 1),
);

// List of mass actions available
$arrayofmassactions = array();
if ($permissiontodelete) {
	$arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("EasyOcrRemoveMark");
}
if (GETPOST('nomassaction', 'int') || in_array($massaction, array('presend', 'predelete'))) {
	$arrayofmassactions = array();
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

// ─── Page header (consistent EasyOcr style) ─────────────────────────────
print '<div class="eo-page-header">';
print '  <div class="eo-page-header-icon eo-page-header-icon--inv"><i class="fas fa-file-invoice-dollar"></i></div>';
print '  <div class="eo-page-header-text">';
print '    <h1>' . dol_escape_htmltag($title) . '</h1>';
print '    <p>' . dol_escape_htmltag($langs->trans('EasyOcrIndexDescInvoices')) . '</p>';
print '  </div>';
print '</div>';

// Confirm dialog
if ($action == 'delete') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.((int) GETPOST('id', 'int')).$param,
		$langs->trans('EasyOcrDeleteInvoice'),
		$langs->trans('EasyOcrConfirmDeleteInvoice'),
		'confirm_delete',
		'',
		0,
		1
	);
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
}
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';

$selectedfields = $form->showCheckAddButtons('checkforselect', 1);

print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, '', 0, '', '', $limit, 0, 0, 1);

// Add code for pre mass action
$topicmail = "";
$modelmail = "";
$trackid = 'easyocrinv';
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">'."\n";

// --- Fields title search ---
print '<tr class="liste_titre_filter">';

// Checkbox left
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print '<td class="liste_titre center maxwidthsearch">';
	$searchpicto = $form->showFilterButtons('left');
	print $searchpicto;
	print '</td>';
}

// Ref
print '<td class="liste_titre">';
print '<input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'">';
print '</td>';

// Supplier Ref
print '<td class="liste_titre">';
print '<input type="text" class="flat maxwidth150" name="search_supplier_ref" value="'.dol_escape_htmltag($search_supplier_ref).'">';
print '</td>';

// Date
print '<td class="liste_titre"></td>';

// Date Creation
print '<td class="liste_titre"></td>';

// Supplier
print '<td class="liste_titre">';
print $form->select_company($search_supplier, 'search_supplier', easyocrSupplierFilter(), $langs->trans('EasyOcrAllSuppliers'), 0, 0, array(), 0, 'flat maxwidth200');
print '</td>';

// Total HT
print '<td class="liste_titre"></td>';

// Total TTC
print '<td class="liste_titre"></td>';

// Status
print '<td class="liste_titre center">';
print '<select name="search_status" class="flat maxwidth150">';
print '<option value="">'.dol_escape_htmltag($langs->trans('EasyOcrAllSuppliers')).'</option>';
foreach ($statusOptions as $code => $label) {
	print '<option value="'.$code.'"'.($search_status === (string) $code ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';
print '</td>';

// Origin
print '<td class="liste_titre">';
print '<select name="search_import_key" class="flat maxwidth100">';
print '<option value="">'.dol_escape_htmltag($langs->trans('EasyOcrAllSuppliers')).'</option>';
print '<option value="easyocr"'.($search_import_key == 'easyocr' ? ' selected' : '').'>OCR</option>';
print '<option value="easyocr-ai"'.($search_import_key == 'easyocr-ai' ? ' selected' : '').'>IA OCR</option>';
print '<option value="easyocr-wh"'.($search_import_key == 'easyocr-wh' ? ' selected' : '').'>Webhook</option>';
print '</select>';
print '</td>';

// Action column (right)
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print '<td class="liste_titre center maxwidthsearch">';
	$searchpicto = $form->showFilterButtons();
	print $searchpicto;
	print '</td>';
}
print '</tr>'."\n";

$totalarray = array();
$totalarray['nbfield'] = 0;

// --- Fields title label ---
print '<tr class="liste_titre">';

if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
	$totalarray['nbfield']++;
}

print_liste_field_titre($langs->trans("EasyOcrRef"), $_SERVER['PHP_SELF'], 'c.ref', '', $param, '', $sortfield, $sortorder);
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrSupplierRef"), $_SERVER['PHP_SELF'], 'c.ref_supplier', '', $param, '', $sortfield, $sortorder);
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrDate"), $_SERVER['PHP_SELF'], 'c.datef', '', $param, '', $sortfield, $sortorder, 'center ');
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrCreation"), $_SERVER['PHP_SELF'], 'c.datec', '', $param, '', $sortfield, $sortorder, 'center ');
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrThirdParty"), $_SERVER['PHP_SELF'], 'd.nom', '', $param, '', $sortfield, $sortorder);
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("AmountHT"), $_SERVER['PHP_SELF'], 'c.total_ht', '', $param, '', $sortfield, $sortorder, 'right ');
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("AmountTTC"), $_SERVER['PHP_SELF'], 'c.total_ttc', '', $param, '', $sortfield, $sortorder, 'right ');
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("Status"), $_SERVER['PHP_SELF'], 'c.fk_statut', '', $param, '', $sortfield, $sortorder, 'center ');
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrOrigin"), $_SERVER['PHP_SELF'], 'c.import_key', '', $param, '', $sortfield, $sortorder, 'center ');
$totalarray['nbfield']++;

if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
	$totalarray['nbfield']++;
}
print '</tr>'."\n";


// --- Loop on records ---
$i = 0;
$savnbfield = $totalarray['nbfield'];
$totalarray = array();
$totalarray['nbfield'] = 0;
$totalarray['val'] = array();
$totalarray['val']['c.total_ht'] = 0;
$totalarray['val']['c.total_ttc'] = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break;
	}

	print '<tr class="oddeven">';

	// Checkbox left
	if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print '<td class="nowrap center">';
		if ($massactionbutton || $massaction) {
			$selected = 0;
			if (in_array($obj->rowid, $arrayofselected)) {
				$selected = 1;
			}
			print '<input id="cb'.$obj->rowid.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$obj->rowid.'"'.($selected ? ' checked="checked"' : '').'>';
		}
		print '</td>';
		if (!$i) {
			$totalarray['nbfield']++;
		}
	}

	// Ref
	print '<td class="nowraponall">';
	print '<a href="'.DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$obj->rowid.'">'.img_picto('', 'supplier_invoice', 'class="pictofixedwidth"').dol_escape_htmltag($obj->ref).'</a>';
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Supplier Ref
	print '<td class="tdoverflowmax200">';
	print dol_escape_htmltag($obj->ref_supplier);
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Date
	print '<td class="center nowraponall">';
	print dol_print_date($db->jdate($obj->datef), 'day');
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Date Creation
	print '<td class="center nowraponall">';
	print dol_print_date($db->jdate($obj->datec), 'dayhour');
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Supplier
	print '<td class="tdoverflowmax200">';
	print '<a href="'.DOL_URL_ROOT.'/fourn/card.php?socid='.$obj->fk_soc.'">'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag($obj->supplier_name).'</a>';
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Total HT
	print '<td class="right nowraponall">';
	print price($obj->total_ht);
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
		$totalarray['pos'][$totalarray['nbfield']] = 'c.total_ht';
	}
	$totalarray['val']['c.total_ht'] += $obj->total_ht;

	// Total TTC
	print '<td class="right nowraponall">';
	print price($obj->total_ttc);
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
		$totalarray['pos'][$totalarray['nbfield']] = 'c.total_ttc';
	}
	$totalarray['val']['c.total_ttc'] += $obj->total_ttc;

	// Status, as Dolibarr shows it on the supplier invoice card
	$facturestatic->id = (int) $obj->rowid;
	$facturestatic->paye = (int) $obj->paye;
	$facturestatic->statut = (int) $obj->fk_statut;
	$facturestatic->status = (int) $obj->fk_statut;
	$facturestatic->type = (int) $obj->type;
	print '<td class="center nowraponall eo-inv-status" data-status="'.((int) $obj->fk_statut).'">';
	print $facturestatic->getLibStatut(5);
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Origin (import_key)
	print '<td class="center nowraponall">';
	if ($obj->import_key == 'easyocr-ai') {
		print '<span class="badge badge-status4 badge-status">IA OCR</span>';
	} elseif ($obj->import_key == 'easyocr-wh') {
		print '<span class="badge badge-status1 badge-status">Webhook</span>';
	} else {
		print '<span class="badge badge-status0 badge-status">OCR</span>';
	}
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Action column (right)
	if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print '<td class="nowrap center">';
		// View
		print '<a class="marginleftonly marginrightonly" href="'.DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$obj->rowid.'" title="'.$langs->trans('View').'">'.img_picto($langs->trans('View'), 'eye').'</a>';
		// Remove mark
		if ($permissiontodelete) {
			print '<a class="marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=delete&token='.newToken().'&id='.$obj->rowid.$param.'" title="'.$langs->trans('EasyOcrRemoveMark').'">'.img_picto($langs->trans('EasyOcrRemoveMark'), 'unlink').'</a>';
		}
		// Checkbox
		if ($massactionbutton || $massaction) {
			$selected = 0;
			if (in_array($obj->rowid, $arrayofselected)) {
				$selected = 1;
			}
			print ' <input id="cb'.$obj->rowid.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$obj->rowid.'"'.($selected ? ' checked="checked"' : '').'>';
		}
		print '</td>';
		if (!$i) {
			$totalarray['nbfield']++;
		}
	}

	print '</tr>'."\n";

	$i++;
}

// Show total line
if (isset($totalarray['pos'])) {
	print '<tr class="liste_total">';
	$i = 0;
	while ($i < $savnbfield) {
		$i++;
		if (!empty($totalarray['pos'][$i])) {
			print '<td class="right nowraponall">'.price($totalarray['val'][$totalarray['pos'][$i]]).'</td>';
		} else {
			if ($i == 1) {
				print '<td>'.$langs->trans("Total").'</td>';
			} else {
				print '<td></td>';
			}
		}
	}
	print '</tr>';
}

// If no record found
if ($num == 0) {
	$colspan = $totalarray['nbfield'] ? $totalarray['nbfield'] : $savnbfield;
	if (!$colspan) {
		$colspan = 10;
	}
	print '<tr><td colspan="'.$colspan.'"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

$db->free($resql);

print '</table>'."\n";
print '</div>'."\n";

print '</form>'."\n";

// End of page
llxFooter();
$db->close();
