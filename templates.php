<?php
/**
 * EasyOcr - Templates Management
 * 
 * @package    EasyOcr
 * @copyright  2025-2026 EasySoft Tech S.L.
 * @license    GPL-3.0+
 */

// Load Dolibarr environment
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

// Security check
if (!$user->rights->easyocr->read) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$searchName = GETPOST('search_name', 'alpha');
$searchSupplier = GETPOST('search_supplier', 'int');
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

$form = new Form($db);
$langs->load('easyocr@easyocr');

// Clear filters
if (GETPOSTISSET('button_removefilter') || GETPOSTISSET('button_removefilter_x')) {
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Actions
if (GETPOST('cancel', 'alpha')) {
    $action = '';
}

// Mass delete
if ($massaction == 'delete' && $user->rights->easyocr->delete) {
    if (!empty($toselect)) {
        $db->begin();
        $error = 0;
        foreach ($toselect as $toselectid) {
            $toselectid = (int) $toselectid;
            // Delete template details first
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template = " . $toselectid;
            if (!$db->query($sql)) {
                $error++;
            }
            // Delete template
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . $toselectid;
            if (!$db->query($sql)) {
                $error++;
            }
        }
        if (!$error) {
            $db->commit();
            setEventMessages(count($toselect) > 1 ? $langs->trans('EasyOcrTemplatesDeleted') : $langs->trans('EasyOcrTemplateDeleted'), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages($langs->trans('EasyOcrErrorDeletingTemplates'), null, 'errors');
        }
        $action = '';
    }
}

// Delete single template
if ($action == 'delete' && $confirm == 'yes' && $user->rights->easyocr->delete) {
    $id = GETPOST('id', 'int');
    $db->begin();
    $error = 0;
    // Delete details
    $sql = "DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template_details WHERE fk_template = " . ((int) $id);
    if (!$db->query($sql)) {
        $error++;
    }
    // Delete template
    $sql = "DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . ((int) $id);
    if (!$db->query($sql)) {
        $error++;
    }
    if (!$error) {
        $db->commit();
        setEventMessages($langs->trans('EasyOcrTemplateDeleted'), null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $db->rollback();
        setEventMessages($langs->trans('EasyOcrErrorDeletingTemplates'), null, 'errors');
    }
    $action = '';
}

// Update template name (inline edit)
if ($action == 'update_name' && $user->rights->easyocr->write) {
    $id = GETPOST('id', 'int');
    $newName = GETPOST('name', 'alpha');
    
    if (!empty($newName)) {
        // Check duplicate
        $sql = "SELECT COUNT(*) as num FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE name = '" . $db->escape($newName) . "' AND rowid <> " . ((int) $id);
        $resql = $db->query($sql);
        $obj_check = $db->fetch_object($resql);
        
        if ($obj_check->num > 0) {
            setEventMessages($langs->trans('EasyOcrTemplateNameExists'), null, 'warnings');
        } else {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET name = '" . $db->escape($newName) . "' WHERE rowid = " . ((int) $id);
            if ($db->query($sql)) {
                setEventMessages($langs->trans('EasyOcrTemplateUpdated'), null, 'mesgs');
            } else {
                setEventMessages($langs->trans('EasyOcrErrorUpdatingTemplate'), null, 'errors');
            }
        }
    }
    $action = '';
}

// Build SQL
$sql = "SELECT t.rowid, t.name, t.fk_soc, t.date_creation, s.nom as supplier_name";
$sql .= " FROM " . MAIN_DB_PREFIX . "easyocr_template as t";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON t.fk_soc = s.rowid";
$sql .= " WHERE 1=1";

// Filters
if ($searchName) {
    $sql .= " AND t.name LIKE '%" . $db->escape($searchName) . "%'";
}
if ($searchSupplier > 0) {
    $sql .= " AND t.fk_soc = " . ((int) $searchSupplier);
}

// Count
$sqlcount = preg_replace('/^SELECT[A-Za-z0-9\.,\s\_\*]+FROM/i', 'SELECT COUNT(*) as nb FROM', $sql);
$resql_count = $db->query($sqlcount);
$obj_count = $db->fetch_object($resql_count);
$nbtotalofrecords = $obj_count->nb;

// Sorting
$sql .= " ORDER BY t.rowid DESC";
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
$num = $db->num_rows($resql);

// Page header
$arrayofjs = array(
    '/custom/easyocr/js/eo-panel.js'
);
$arrayofcss = array(
    '/custom/easyocr/css/eo-panel.css'
);

llxHeader('', $langs->trans('EasyOcrTemplatesTitle').' - EasyOcr', '', '', 0, 0, $arrayofjs, $arrayofcss);

// Confirm dialog
if ($action == 'delete') {
    $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . GETPOST('id', 'int'), $langs->trans('EasyOcrDeleteTemplate'), $langs->trans('EasyOcrConfirmDeleteTemplate'), 'delete', '', 0, 1);
    print $formconfirm;
}

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '" name="formfilter" autocomplete="off">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<div class="eo-panel-header">';
print '<div class="eo-panel-title">';
print '<img src="'.DOL_URL_ROOT.'/custom/easyocr/img/templates.png" class="eo-icon" alt="">';
print '<h1>'.$langs->trans('EasyOcrTemplatesTitle').' <span class="opacitymedium">(' . $nbtotalofrecords . ')</span></h1>';
print '</div>';
print '</div>';

// Filters
print '<div class="eo-panel-filters">';
print '<div class="eo-filter-group">';
print '<label>'.$langs->trans('EasyOcrNameLabel').'</label>';
print '<input type="text" name="search_name" class="flat maxwidth150" value="' . dol_escape_htmltag($searchName) . '" placeholder="'.$langs->trans('EasyOcrSearchPlaceholder').'">';
print '</div>';

print '<div class="eo-filter-group">';
print '<label>'.$langs->trans('EasyOcrSupplier').':</label>';
print $form->select_company($searchSupplier, 'search_supplier', 's.fournisseur=1', $langs->trans('EasyOcrAllSuppliers'), 0, 0, array(), 0, 'flat maxwidth200');
print '</div>';

print '<div class="eo-filter-actions">';
print '<button type="submit" class="button buttongen" name="button_search"><i class="fa fa-search"></i> '.$langs->trans('EasyOcrSearch').'</button>';
print '<button type="submit" class="button buttongen" name="button_removefilter"><i class="fa fa-remove"></i> '.$langs->trans('EasyOcrClear').'</button>';
print '</div>';
print '</div>';

// Mass actions
if ($massaction == 'preDelete') {
    print '<div class="eo-mass-confirm">';
    print '<span><i class="fa fa-warning"></i> '.sprintf($langs->trans('EasyOcrConfirmDeleteTemplates'), count($toselect)).'</span>';
    print '<button type="submit" name="massaction" value="delete" class="button butActionDelete">'.$langs->trans('EasyOcrConfirm').'</button>';
    print '<button type="submit" name="cancel" value="1" class="button button-cancel">'.$langs->trans('EasyOcrCancel').'</button>';
    print '</div>';
}

$selectedfields = '';
$massactionbutton = '';
if ($user->rights->easyocr->delete) {
    $massactionbutton = '<button type="submit" class="button butActionDelete" name="massaction" value="preDelete"><i class="fa fa-trash"></i> '.$langs->trans('EasyOcrDelete').'</button>';
}

print '<div class="eo-panel-controls">';
print '<div class="eo-limit-selector">';
print $langs->trans('EasyOcrShow').': ';
print '<select name="limit" class="flat" onchange="this.form.submit()">';
foreach (array(10, 20, 50, 100) as $val) {
    print '<option value="'.$val.'"'.($limit == $val ? ' selected' : '').'>'.$val.'</option>';
}
print '</select>';
print '</div>';
print '<div class="eo-mass-actions">';
print $massactionbutton;
print '</div>';
print '</div>';

// Table
print '<div class="div-table-responsive">';
print '<table class="tagtable liste listtable">';
print '<thead>';
print '<tr class="liste_titre">';
print '<th class="eo-col-checkbox">';
$selectAll = '';
if ($num > 0) {
    print '<input type="checkbox" id="checkall" class="flat checkforselect">';
}
print '</th>';
print '<th class="liste_titre">'.$langs->trans('EasyOcrName').'</th>';
print '<th class="liste_titre">'.$langs->trans('EasyOcrSupplier').'</th>';
print '<th class="liste_titre center">'.$langs->trans('EasyOcrCreation').'</th>';
print '<th class="liste_titre center">'.$langs->trans('EasyOcrActions').'</th>';
print '</tr>';
print '</thead>';
print '<tbody>';

if ($num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        
        print '<tr class="oddeven">';
        
        // Checkbox
        print '<td class="eo-col-checkbox">';
        print '<input type="checkbox" class="flat checkforselect" name="toselect[]" value="' . $obj->rowid . '">';
        print '</td>';
        
        // Name (inline editable)
        print '<td class="tdoverflowmax200">';
        print '<span class="eo-editable" data-id="' . $obj->rowid . '" data-field="name">';
        print dol_escape_htmltag($obj->name);
        print '</span>';
        print '</td>';
        
        // Supplier
        print '<td>';
        if ($obj->fk_soc > 0) {
            $supplierLink = DOL_URL_ROOT . '/fourn/card.php?socid=' . $obj->fk_soc;
            print '<a href="' . $supplierLink . '">' . dol_escape_htmltag($obj->supplier_name) . '</a>';
        } else {
            print '<span class="opacitymedium">'.$langs->trans('EasyOcrNoSupplier').'</span>';
        }
        print '</td>';
        
        // Date
        print '<td class="center nowraponall">';
        print dol_print_date($db->jdate($obj->date_creation), 'day');
        print '</td>';
        
        // Actions
        print '<td class="center nowraponall">';
        print '<a href="' . $_SERVER['PHP_SELF'] . '?action=delete&id=' . $obj->rowid . '" class="eo-action-btn" title="'.$langs->trans('EasyOcrDelete').'">';
        print '<i class="fa fa-trash"></i>';
        print '</a>';
        print '</td>';
        
        print '</tr>';
        $i++;
    }
} else {
    print '<tr><td colspan="5" class="opacitymedium center">'.$langs->trans('EasyOcrNoTemplatesFound').'</td></tr>';
}

print '</tbody>';
print '</table>';
print '</div>';

// Pagination
if ($nbtotalofrecords > $limit) {
    print '<div class="eo-pagination">';
    print_fleche_navigation($page, $_SERVER['PHP_SELF'], '&search_name=' . urlencode($searchName) . '&search_supplier=' . $searchSupplier, ($page < ($nbtotalofrecords / $limit)), '');
    print '</div>';
}

print '</form>';

// Inline edit modal
print '<div id="eoEditModal" class="eo-modal" style="display:none;">';
print '<div class="eo-modal-content eo-modal-sm">';
print '<div class="eo-modal-header">';
print '<h3>'.$langs->trans('EasyOcrEditName').'</h3>';
print '<span class="eo-modal-close">&times;</span>';
print '</div>';
print '<form id="eoEditForm" method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_name">';
print '<input type="hidden" name="id" id="eoEditId" value="">';
print '<div class="eo-modal-body">';
print '<label class="fieldrequired">'.$langs->trans('EasyOcrNameLabel').'</label>';
print '<input type="text" name="name" id="eoEditName" class="flat minwidth300" required>';
print '</div>';
print '<div class="eo-modal-footer">';
print '<button type="submit" class="button"><i class="fa fa-save"></i> '.$langs->trans('EasyOcrSave').'</button>';
print '<button type="button" class="button button-cancel eo-modal-close">'.$langs->trans('EasyOcrCancel').'</button>';
print '</div>';
print '</form>';
print '</div>';
print '</div>';

// JS handled by eo-panel.js

llxFooter();
$db->close();