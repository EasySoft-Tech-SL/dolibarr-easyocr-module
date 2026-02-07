<?php
/**
 * EasyOcr - Invoices Management
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
$searchRef = GETPOST('search_ref', 'alpha');
$searchSupplierRef = GETPOST('search_supplier_ref', 'alpha');
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

// Clear filters
if (GETPOSTISSET('button_removefilter') || GETPOSTISSET('button_removefilter_x')) {
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Actions
if (GETPOST('cancel', 'alpha')) {
    $action = '';
}

// Mass delete (quitar marca easyocr)
if ($massaction == 'delete' && $user->rights->easyocr->delete) {
    if (!empty($toselect)) {
        $db->begin();
        $error = 0;
        foreach ($toselect as $toselectid) {
            $toselectid = (int) $toselectid;
            $sql = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET import_key = NULL WHERE rowid = " . $toselectid . " AND import_key = 'easyocr'";
            if (!$db->query($sql)) {
                $error++;
            }
        }
        if (!$error) {
            $db->commit();
            setEventMessages(count($toselect) > 1 ? "Registros eliminados" : "Registro eliminado", null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error al eliminar registros", null, 'errors');
        }
        $action = '';
    }
}

// Delete single record (quitar marca easyocr)
if ($action == 'delete' && $confirm == 'yes' && $user->rights->easyocr->delete) {
    $id = GETPOST('id', 'int');
    $sql = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn SET import_key = NULL WHERE rowid = " . ((int) $id) . " AND import_key = 'easyocr'";
    if ($db->query($sql)) {
        setEventMessages("Registro eliminado", null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        setEventMessages("Error al eliminar registro", null, 'errors');
    }
    $action = '';
}

// Build SQL
$sql = "SELECT c.rowid, c.ref, c.ref_supplier, c.datef, c.fk_soc, d.nom as supplier";
$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn as c";
$sql .= " JOIN " . MAIN_DB_PREFIX . "societe as d ON c.fk_soc = d.rowid";
$sql .= " WHERE c.import_key = 'easyocr'";

// Filters
if ($searchRef) {
    $sql .= " AND c.ref LIKE '%" . $db->escape($searchRef) . "%'";
}
if ($searchSupplierRef) {
    $sql .= " AND c.ref_supplier LIKE '%" . $db->escape($searchSupplierRef) . "%'";
}
if ($searchSupplier > 0) {
    $sql .= " AND c.fk_soc = " . ((int) $searchSupplier);
}

// Count
$sqlcount = preg_replace('/^SELECT[A-Za-z0-9\.,\s\_\*]+FROM/i', 'SELECT COUNT(*) as nb FROM', $sql);
$resql_count = $db->query($sqlcount);
$obj_count = $db->fetch_object($resql_count);
$nbtotalofrecords = $obj_count->nb;

// Sorting
$sql .= " ORDER BY c.rowid DESC";
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

llxHeader('', 'Facturas - EasyOcr', '', '', 0, 0, $arrayofjs, $arrayofcss);

// Confirm dialog
if ($action == 'delete') {
    $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . GETPOST('id', 'int'), 'Eliminar Factura', '¿Confirmar eliminación de este registro?', 'delete', '', 0, 1);
    print $formconfirm;
}

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '" name="formfilter" autocomplete="off">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<div class="eo-panel-header">';
print '<div class="eo-panel-title">';
print '<img src="'.DOL_URL_ROOT.'/custom/easyocr/img/invoice.png" class="eo-icon" alt="">';
print '<h1>Facturas <span class="opacitymedium">(' . $nbtotalofrecords . ')</span></h1>';
print '</div>';
print '</div>';

// Filters
print '<div class="eo-panel-filters">';
print '<div class="eo-filter-group">';
print '<label>Ref.:</label>';
print '<input type="text" name="search_ref" class="flat maxwidth100" value="' . dol_escape_htmltag($searchRef) . '" placeholder="SI...">';
print '</div>';

print '<div class="eo-filter-group">';
print '<label>Ref. Proveedor:</label>';
print '<input type="text" name="search_supplier_ref" class="flat maxwidth150" value="' . dol_escape_htmltag($searchSupplierRef) . '" placeholder="Buscar...">';
print '</div>';

print '<div class="eo-filter-group">';
print '<label>Tercero:</label>';
print $form->select_company($searchSupplier, 'search_supplier', 's.fournisseur=1', 'Todos', 0, 0, array(), 0, 'flat maxwidth200');
print '</div>';

print '<div class="eo-filter-actions">';
print '<button type="submit" class="button buttongen" name="button_search"><i class="fa fa-search"></i> Buscar</button>';
print '<button type="submit" class="button buttongen" name="button_removefilter"><i class="fa fa-remove"></i> Limpiar</button>';
print '</div>';
print '</div>';

// Mass actions
if ($massaction == 'preDelete') {
    print '<div class="eo-mass-confirm">';
    print '<span><i class="fa fa-warning"></i> ¿Eliminar ' . count($toselect) . ' registro(s) seleccionado(s)?</span>';
    print '<button type="submit" name="massaction" value="delete" class="button butActionDelete">Confirmar</button>';
    print '<button type="submit" name="cancel" value="1" class="button button-cancel">Cancelar</button>';
    print '</div>';
}

$massactionbutton = '';
if ($user->rights->easyocr->delete) {
    $massactionbutton = '<button type="submit" class="button butActionDelete" name="massaction" value="preDelete"><i class="fa fa-trash"></i> Eliminar</button>';
}

print '<div class="eo-panel-controls">';
print '<div class="eo-limit-selector">';
print 'Mostrar: ';
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
if ($num > 0) {
    print '<input type="checkbox" id="checkall" class="flat checkforselect">';
}
print '</th>';
print '<th class="liste_titre">Ref.</th>';
print '<th class="liste_titre">Ref. Proveedor</th>';
print '<th class="liste_titre center">Fecha</th>';
print '<th class="liste_titre">Tercero</th>';
print '<th class="liste_titre center">Acciones</th>';
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
        
        // Ref (link to invoice)
        print '<td>';
        $invoiceLink = DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $obj->rowid;
        print '<a href="' . $invoiceLink . '">' . dol_escape_htmltag($obj->ref) . '</a>';
        print '</td>';
        
        // Supplier Ref
        print '<td>';
        print dol_escape_htmltag($obj->ref_supplier);
        print '</td>';
        
        // Date
        print '<td class="center nowraponall">';
        print dol_print_date($db->jdate($obj->datef), 'day');
        print '</td>';
        
        // Supplier
        print '<td>';
        $supplierLink = DOL_URL_ROOT . '/fourn/card.php?socid=' . $obj->fk_soc;
        print '<a href="' . $supplierLink . '">' . dol_escape_htmltag($obj->supplier) . '</a>';
        print '</td>';
        
        // Actions
        print '<td class="center nowraponall">';
        print '<a href="' . $_SERVER['PHP_SELF'] . '?action=delete&id=' . $obj->rowid . '" class="eo-action-btn" title="Eliminar registro">';
        print '<i class="fa fa-trash"></i>';
        print '</a>';
        print '</td>';
        
        print '</tr>';
        $i++;
    }
} else {
    print '<tr><td colspan="6" class="opacitymedium center">No se encontraron facturas</td></tr>';
}

print '</tbody>';
print '</table>';
print '</div>';

// Pagination
if ($nbtotalofrecords > $limit) {
    print '<div class="eo-pagination">';
    $params = '&search_ref=' . urlencode($searchRef) . '&search_supplier_ref=' . urlencode($searchSupplierRef) . '&search_supplier=' . $searchSupplier;
    print_fleche_navigation($page, $_SERVER['PHP_SELF'], $params, ($page < ($nbtotalofrecords / $limit)), '');
    print '</div>';
}

print '</form>';

// JS handled by eo-panel.js

llxFooter();
$db->close();