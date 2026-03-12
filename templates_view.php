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
 * \file       templates_view.php
 * \ingroup    easyocr
 * \brief      View/Edit page for an EasyOcr template
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
require_once __DIR__.'/lib/easyocr.lib.php';

// Load translation files
$langs->loadLangs(array("easyocr@easyocr", "other"));

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (!$id) {
	header('Location: templates.php');
	exit;
}

$form = new Form($db);

// AI enabled?
$aiEnabled = !empty($conf->global->EASYOCR_AI_ENABLED);

// Security check
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	accessforbidden();
}
$permissiontoadd = easyocrCheckRight($user, 'easyocr', 'write');
$permissiontodelete = easyocrCheckRight($user, 'easyocr', 'delete');


/*
 * Actions
 */

// Update template
if ($action == 'update' && $permissiontoadd) {
	$name = GETPOST('name', 'alpha');
	$custom_instructions = GETPOST('custom_instructions', 'restricthtml');

	if (empty($name)) {
		setEventMessages($langs->trans('EasyOcrFieldRequired'), null, 'errors');
		$action = 'edit';
	} else {
		// Check duplicate name
		$sql = "SELECT COUNT(*) as num FROM ".MAIN_DB_PREFIX."easyocr_template WHERE rowid <> ".((int) $id)." AND name = '".$db->escape($name)."'";
		$resql = $db->query($sql);
		$obj_check = $db->fetch_object($resql);

		if ($obj_check->num > 0) {
			setEventMessages($langs->trans('EasyOcrTemplateExists'), null, 'warnings');
			$action = 'edit';
		} else {
			$fk_soc = GETPOST('fk_soc', 'int');

			$sql = "UPDATE ".MAIN_DB_PREFIX."easyocr_template SET";
			$sql .= " name = '".$db->escape($name)."'";
			$sql .= ", fk_soc = ".($fk_soc > 0 ? ((int) $fk_soc) : "NULL");
			$sql .= ", custom_instructions = ".(!empty($custom_instructions) ? "'".$db->escape($custom_instructions)."'" : "NULL");
			$sql .= " WHERE rowid = ".((int) $id);
			if ($db->query($sql)) {
				setEventMessages($langs->trans('EasyOcrTemplateUpdatedOk'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
				exit;
			} else {
				setEventMessages($langs->trans('EasyOcrErrorUpdatingTpl'), null, 'errors');
				$action = 'edit';
			}
		}
	}
}

// Delete template
if ($action == 'confirm_delete' && $confirm == 'yes' && $permissiontodelete) {
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
		header('Location: templates.php');
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

// Load template data
$sql = "SELECT t.rowid, t.name, t.fk_soc, t.scale, t.custom_instructions, t.date_creation,";
$sql .= " s.nom as supplier_name";
$sql .= " FROM ".MAIN_DB_PREFIX."easyocr_template as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON t.fk_soc = s.rowid";
$sql .= " WHERE t.rowid = ".((int) $id);
$resql = $db->query($sql);

if ($db->num_rows($resql) === 0) {
	header('Location: templates.php');
	exit;
}

$obj = $db->fetch_object($resql);

// Count template fields
$sqlFields = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."easyocr_template_details WHERE fk_template = ".((int) $id);
$resqlFields = $db->query($sqlFields);
$objFields = $db->fetch_object($resqlFields);
$nbFields = $objFields->nb;

// Load field details
$templateDetails = array();
$sqlDetails = "SELECT label, page_index, pos_x, pos_y, sel_w, sel_h FROM ".MAIN_DB_PREFIX."easyocr_template_details WHERE fk_template = ".((int) $id)." ORDER BY page_index, label";
$resqlDetails = $db->query($sqlDetails);
if ($resqlDetails) {
	while ($objd = $db->fetch_object($resqlDetails)) {
		$templateDetails[] = $objd;
	}
	$db->free($resqlDetails);
}

$title = $langs->trans("EasyOcrEditTemplateTitle").' - '.dol_escape_htmltag($obj->name);
$help_url = '';

llxHeader('', $title, $help_url);

// Confirm delete
if ($action == 'delete') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('EasyOcrDeleteTemplate'),
		$langs->trans('EasyOcrConfirmDeleteTemplate'),
		'confirm_delete',
		'',
		0,
		1
	);
}

// Linkback
print '<div class="tabBar">';

$linkback = '<a href="'.dol_buildpath('/easyocr/templates.php', 1).'">'.$langs->trans("BackToList").'</a>';

// Title with nav
print load_fiche_titre($langs->trans('EasyOcrEditTemplateTitle'), $linkback, 'generic');

// --- View mode ---
if ($action != 'edit') {

	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';

	print '<table class="border centpercent tableforfield">';

	// Name
	print '<tr><td class="titlefield">'.$langs->trans("EasyOcrName").'</td>';
	print '<td>'.dol_escape_htmltag($obj->name).'</td></tr>';

	// Supplier
	print '<tr><td>'.$langs->trans("EasyOcrSupplier").'</td>';
	print '<td>';
	if ($obj->fk_soc > 0) {
		print '<a href="'.DOL_URL_ROOT.'/fourn/card.php?socid='.$obj->fk_soc.'">'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag($obj->supplier_name).'</a>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('EasyOcrNoSupplier').'</span>';
	}
	print '</td></tr>';

	// Scale
	print '<tr><td>'.$langs->trans("EasyOcrScale").'</td>';
	print '<td>'.dol_escape_htmltag($obj->scale).'</td></tr>';

	// Number of fields
	print '<tr><td>'.$langs->trans("EasyOcrNumFields").'</td>';
	print '<td>';
	if ($nbFields > 0) {
		print '<span class="badge badge-status4 badge-status">'.$nbFields.'</span>';
	} else {
		print '<span class="opacitymedium">0</span>';
	}
	print '</td></tr>';

	// Custom Instructions (only if AI enabled)
	if ($aiEnabled) {
		print '<tr><td>'.$langs->trans("EasyOcrCustomInstructions").'</td>';
		print '<td>';
		if (!empty($obj->custom_instructions)) {
			print dol_nl2br(dol_escape_htmltag($obj->custom_instructions));
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td></tr>';
	}

	// Date
	print '<tr><td>'.$langs->trans("EasyOcrCreation").'</td>';
	print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td></tr>';

	print '</table>';

	print '</div>'; // fichehalfleft
	print '</div>'; // fichecenter

	print '<div class="clearboth"></div>';

	// Action buttons
	print '<div class="tabsAction">';
	if ($permissiontoadd) {
		print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit&token='.newToken().'">'.$langs->trans('Modify').'</a>';
	}
	if ($permissiontodelete) {
		print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete&token='.newToken().'">'.$langs->trans('Delete').'</a>';
	}
	print '</div>';

	// --- Template fields detail table ---
	if (!empty($templateDetails)) {
		print '<br>';
		print load_fiche_titre($langs->trans('EasyOcrTemplateFields'), '', '');

		print '<div class="div-table-responsive">';
		print '<table class="tagtable nobottomiftotal liste">';
		print '<tr class="liste_titre">';
		print '<th class="liste_titre">'.$langs->trans("EasyOcrFieldLabel").'</th>';
		print '<th class="liste_titre center">'.$langs->trans("EasyOcrPage").'</th>';
		print '<th class="liste_titre center">X</th>';
		print '<th class="liste_titre center">Y</th>';
		print '<th class="liste_titre center">'.$langs->trans("EasyOcrWidth").'</th>';
		print '<th class="liste_titre center">'.$langs->trans("EasyOcrHeight").'</th>';
		print '</tr>';

		foreach ($templateDetails as $detail) {
			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag($detail->label).'</td>';
			print '<td class="center">'.$detail->page_index.'</td>';
			print '<td class="center">'.round($detail->pos_x, 2).'</td>';
			print '<td class="center">'.round($detail->pos_y, 2).'</td>';
			print '<td class="center">'.round($detail->sel_w, 2).'</td>';
			print '<td class="center">'.round($detail->sel_h, 2).'</td>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';
	}

} else {
	// --- Edit mode ---

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.$id.'">';

	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';

	print '<table class="border centpercent tableforfield">';

	// Name
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans("EasyOcrName").'</td>';
	print '<td><input type="text" name="name" class="flat minwidth300" value="'.dol_escape_htmltag(GETPOSTISSET('name') ? GETPOST('name', 'alpha') : $obj->name).'" required></td></tr>';

	// Supplier (editable)
	print '<tr><td>'.$langs->trans("EasyOcrSupplier").'</td>';
	print '<td>';
	$selectedSoc = GETPOSTISSET('fk_soc') ? GETPOST('fk_soc', 'int') : $obj->fk_soc;
	print $form->select_company($selectedSoc, 'fk_soc', 's.fournisseur = 1', 1, 0, 0, array(), 0, 'minwidth300');
	print '</td></tr>';

	// Scale (read-only)
	print '<tr><td>'.$langs->trans("EasyOcrScale").'</td>';
	print '<td>'.dol_escape_htmltag($obj->scale).'</td></tr>';

	// Num fields (read-only)
	print '<tr><td>'.$langs->trans("EasyOcrNumFields").'</td>';
	print '<td>';
	if ($nbFields > 0) {
		print '<span class="badge badge-status4 badge-status">'.$nbFields.'</span>';
	} else {
		print '<span class="opacitymedium">0</span>';
	}
	print '</td></tr>';

	// Custom Instructions (editable, only if AI enabled)
	if ($aiEnabled) {
		print '<tr><td>'.$langs->trans("EasyOcrCustomInstructions").'</td>';
		print '<td>';
		$ciValue = GETPOSTISSET('custom_instructions') ? GETPOST('custom_instructions', 'restricthtml') : $obj->custom_instructions;
		print '<textarea name="custom_instructions" class="flat minwidth400" rows="5" placeholder="'.$langs->trans('EasyOcrCustomInstructionsPlaceholder').'">'.dol_escape_htmltag($ciValue).'</textarea>';
		print '<br><span class="opacitymedium small">'.$langs->trans('EasyOcrCustomInstructionsHint').'</span>';
		print '</td></tr>';
	}

	// Date (read-only)
	print '<tr><td>'.$langs->trans("EasyOcrCreation").'</td>';
	print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td></tr>';

	print '</table>';

	print '</div>'; // fichecenter

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
	print ' &nbsp; ';
	print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans('Cancel').'">';
	print '</div>';

	print '</form>';
}

print '</div>'; // tabBar

// End of page
llxFooter();
$db->close();
