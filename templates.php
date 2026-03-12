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
 * \file       templates.php
 * \ingroup    easyocr
 * \brief      List page for EasyOcr templates
 */

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
require_once __DIR__.'/lib/easyocr.lib.php';

// Load translation files
$langs->loadLangs(array("easyocr@easyocr", "other"));

// Get parameters
$action     = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$confirm    = GETPOST('confirm', 'alpha');
$cancel     = GETPOST('cancel', 'alpha');
$toselect   = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'easyocrtemplateslist';
$optioncss  = GETPOST('optioncss', 'aZ');

// Search filters
$search_name = GETPOST('search_name', 'alpha');
$search_supplier = GETPOST('search_supplier', 'int');
$search_custom_instructions = GETPOST('search_custom_instructions', 'alpha');

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
	$sortfield = "t.rowid";
}
if (!$sortorder) {
	$sortorder = "DESC";
}

// Initialize objects
$form = new Form($db);

// AI enabled?
$aiEnabled = !empty($conf->global->EASYOCR_AI_ENABLED);

// Security check
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	accessforbidden();
}
$permissiontoread = easyocrCheckRight($user, 'easyocr', 'read');
$permissiontoadd = easyocrCheckRight($user, 'easyocr', 'write');
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
	$search_name = '';
	$search_supplier = '';
	$search_custom_instructions = '';
	$toselect = array();
}
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
	|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
	$massaction = '';
}

// Mass delete
if ($massaction == 'delete' && $permissiontodelete) {
	if (!empty($toselect)) {
		$db->begin();
		$error = 0;
		foreach ($toselect as $toselectid) {
			$toselectid = (int) $toselectid;
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_template_details WHERE fk_template = ".$toselectid;
			if (!$db->query($sql)) {
				$error++;
			}
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_template WHERE rowid = ".$toselectid;
			if (!$db->query($sql)) {
				$error++;
			}
		}
		if (!$error) {
			$db->commit();
			setEventMessages($langs->trans('EasyOcrTemplatesDeleted'), null, 'mesgs');
		} else {
			$db->rollback();
			setEventMessages($langs->trans('EasyOcrErrorDeletingTemplates'), null, 'errors');
		}
		$massaction = '';
		$action = '';
	}
}

// Delete single template
if ($action == 'confirm_delete' && $confirm == 'yes' && $permissiontodelete) {
	$id = GETPOST('id', 'int');
	$db->begin();
	$error = 0;
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_template_details WHERE fk_template = ".((int) $id);
	if (!$db->query($sql)) {
		$error++;
	}
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_template WHERE rowid = ".((int) $id);
	if (!$db->query($sql)) {
		$error++;
	}
	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans('EasyOcrTemplateDeleted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	} else {
		$db->rollback();
		setEventMessages($langs->trans('EasyOcrErrorDeletingTemplates'), null, 'errors');
	}
	$action = '';
}


/*
 * View
 */

$title = $langs->trans("EasyOcrTemplatesTitle");
$help_url = '';
$morejs = array();
$morecss = array();

// Build and execute select
$sql = "SELECT t.rowid, t.name, t.fk_soc, t.custom_instructions, t.date_creation,";
$sql .= " s.nom as supplier_name";
$sql .= " FROM ".MAIN_DB_PREFIX."easyocr_template as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON t.fk_soc = s.rowid";
$sql .= " WHERE 1 = 1";

// Filters
if ($search_name) {
	$sql .= natural_search("t.name", $search_name);
}
if ($search_supplier > 0) {
	$sql .= " AND t.fk_soc = ".((int) $search_supplier);
}
if ($search_custom_instructions) {
	$sql .= natural_search("t.custom_instructions", $search_custom_instructions);
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
if ($search_name != '') {
	$param .= '&search_name='.urlencode($search_name);
}
if ($search_supplier > 0) {
	$param .= '&search_supplier='.((int) $search_supplier);
}
if ($search_custom_instructions != '') {
	$param .= '&search_custom_instructions='.urlencode($search_custom_instructions);
}

// List of mass actions available
$arrayofmassactions = array();
if ($permissiontodelete) {
	$arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("Delete");
}
if (GETPOST('nomassaction', 'int') || in_array($massaction, array('presend', 'predelete'))) {
	$arrayofmassactions = array();
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

// ─── Page header (consistent EasyOcr style) ─────────────────────────────
print '<div class="eo-page-header">';
print '  <div class="eo-page-header-icon eo-page-header-icon--tpl"><i class="fas fa-th-large"></i></div>';
print '  <div class="eo-page-header-text">';
print '    <h1>' . dol_escape_htmltag($title) . '</h1>';
print '    <p>' . dol_escape_htmltag($langs->trans('EasyOcrIndexDescTemplates')) . '</p>';
print '  </div>';
print '</div>';

// Confirm dialog
if ($action == 'delete') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.GETPOST('id', 'int'),
		$langs->trans('EasyOcrDeleteTemplate'),
		$langs->trans('EasyOcrConfirmDeleteTemplate'),
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

// Add code for pre mass action (confirmation or email presend form)
$topicmail = "";
$modelmail = "";
$trackid = 'easyocr';
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

// Name
print '<td class="liste_titre">';
print '<input type="text" class="flat maxwidth150" name="search_name" value="'.dol_escape_htmltag($search_name).'">';
print '</td>';

// Supplier
print '<td class="liste_titre">';
print $form->select_company($search_supplier, 'search_supplier', 's.fournisseur=1', $langs->trans('EasyOcrAllSuppliers'), 0, 0, array(), 0, 'flat maxwidth200');
print '</td>';

// Custom Instructions
if ($aiEnabled) {
	print '<td class="liste_titre">';
	print '<input type="text" class="flat maxwidth150" name="search_custom_instructions" value="'.dol_escape_htmltag($search_custom_instructions).'">';
	print '</td>';
}

// Num fields
print '<td class="liste_titre"></td>';

// Date
print '<td class="liste_titre"></td>';

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

print_liste_field_titre($langs->trans("EasyOcrName"), $_SERVER['PHP_SELF'], 't.name', '', $param, '', $sortfield, $sortorder);
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrSupplier"), $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
$totalarray['nbfield']++;

if ($aiEnabled) {
	print_liste_field_titre($langs->trans("EasyOcrCustomInstructions"), $_SERVER['PHP_SELF'], 't.custom_instructions', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}

print_liste_field_titre($langs->trans("EasyOcrNumFields"), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center ');
$totalarray['nbfield']++;

print_liste_field_titre($langs->trans("EasyOcrCreation"), $_SERVER['PHP_SELF'], 't.date_creation', '', $param, '', $sortfield, $sortorder, 'center ');
$totalarray['nbfield']++;

if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
	$totalarray['nbfield']++;
}
print '</tr>'."\n";


// Pre-fetch field counts per template
$fieldCounts = array();
$sqlFields = "SELECT fk_template, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."easyocr_template_details GROUP BY fk_template";
$resqlFields = $db->query($sqlFields);
if ($resqlFields) {
	while ($objf = $db->fetch_object($resqlFields)) {
		$fieldCounts[$objf->fk_template] = $objf->nb;
	}
	$db->free($resqlFields);
}


// --- Loop on records ---
$i = 0;
$savnbfield = $totalarray['nbfield'];
$totalarray = array();
$totalarray['nbfield'] = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break;
	}

	$nbFields = isset($fieldCounts[$obj->rowid]) ? $fieldCounts[$obj->rowid] : 0;

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

	// Name
	print '<td class="tdoverflowmax200">';
	print '<a href="'.dol_buildpath('/easyocr/templates_view.php', 1).'?id='.$obj->rowid.'">'.img_picto('', 'generic', 'class="pictofixedwidth"').dol_escape_htmltag($obj->name).'</a>';
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Supplier
	print '<td class="tdoverflowmax200">';
	if ($obj->fk_soc > 0) {
		print '<a href="'.DOL_URL_ROOT.'/fourn/card.php?socid='.$obj->fk_soc.'">'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag($obj->supplier_name).'</a>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('EasyOcrNoSupplier').'</span>';
	}
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Custom Instructions
	if ($aiEnabled) {
		print '<td class="tdoverflowmax250 small">';
		if (!empty($obj->custom_instructions)) {
			print '<span class="classfortooltip" title="'.dol_escape_htmltag($obj->custom_instructions).'">'.img_picto('', 'technic', 'class="pictofixedwidth"').dol_trunc(dol_escape_htmltag($obj->custom_instructions), 60).'</span>';
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';
		if (!$i) {
			$totalarray['nbfield']++;
		}
	}

	// Num fields
	print '<td class="center">';
	if ($nbFields > 0) {
		print '<span class="badge badge-status4 badge-status">'.$nbFields.'</span>';
	} else {
		print '<span class="opacitymedium">0</span>';
	}
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Date
	print '<td class="center nowraponall">';
	print dol_print_date($db->jdate($obj->date_creation), 'day');
	print '</td>';
	if (!$i) {
		$totalarray['nbfield']++;
	}

	// Action column (right)
	if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
		print '<td class="nowrap center">';
		// Edit
		if ($permissiontoadd) {
			print '<a class="editfielda marginleftonly marginrightonly" href="'.dol_buildpath('/easyocr/templates_view.php', 1).'?id='.$obj->rowid.'" title="'.$langs->trans('Modify').'">'.img_picto($langs->trans('Modify'), 'edit').'</a>';
		}
		// Delete
		if ($permissiontodelete) {
			print '<a class="marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=delete&token='.newToken().'&id='.$obj->rowid.$param.'" title="'.$langs->trans('Delete').'">'.img_picto($langs->trans('Delete'), 'delete').'</a>';
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

// If no record found
if ($num == 0) {
	$colspan = $totalarray['nbfield'] ? $totalarray['nbfield'] : $savnbfield;
	if (!$colspan) {
		$colspan = 6 + ($aiEnabled ? 1 : 0);
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
