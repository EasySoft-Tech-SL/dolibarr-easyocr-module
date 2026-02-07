<?php

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

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';

llxHeader("", "EasyOcr");

print '<link rel="stylesheet" type="text/css" href="css/styles.css" />';
print '<link rel="stylesheet" type="text/css" href="css/panel.css" />';


$page_view = "invoices_view.php";

$mainmenu = "easyocr";

$action = $_SERVER['PHP_SELF'] . '?mainmenu='.$mainmenu;

$sql = "";

if (isset($_POST['ref']) && $_POST['ref']) {

    $sql .= " AND c.ref = '" . $_POST['ref'] . "'";
}


if (isset($_POST['supplier_ref']) && $_POST['supplier_ref']) {

    $sql .= " AND c.ref_supplier = '" . $_POST['supplier_ref'] . "'";
}




function selected($value, $option)
{

    if ($value == $option) {

        return 'selected';
    } else {

        return '';
    }
}


function active($val, $pag)
{

    if ($val == $pag) {

        return "active";
    } else {

        return "";
    }
}


if (isset($_POST["confirm_action"]) && $_POST["confirm_action"]) {

    if ($_POST["confirm_action"] == "delete") {

        if (isset($_POST["all_options"]) && $_POST["all_options"] && $_POST["all_options"] == "si") {

            $list_delete = $db->query("SELECT a.rowid FROM " . MAIN_DB_PREFIX . "easyocr_invoices as a JOIN " . MAIN_DB_PREFIX . "ecm_files as b ON a.fk_file = b.rowid JOIN " . MAIN_DB_PREFIX . "facture_fourn as c ON b.src_object_id = c.rowid JOIN " . MAIN_DB_PREFIX . "societe as d ON c.fk_soc=d.rowid WHERE c.fk_statut=1 $sql");

            $num_delete = $db->num_rows($list);

            for ($i = 0; $i < $num_delete; $i++) {

                $obj_delete = $db->fetch_object($list_delete);

                $db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_invoices WHERE rowid=" . $obj_delete->rowid);
            }

            setEventMessages("Facturas eliminadas", null);

        } else if (isset($_POST["options"]) && $_POST["options"]) {

            $options = explode(",", $_POST["options"]);

            for ($i = 0; $i < count($options); $i++) {

                $db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_invoices WHERE rowid=" . $options[$i]);
            }

            if (count($options) > 1) {

                setEventMessages("Facturas eliminadas", null);
            } else {

                setEventMessages("Factura eliminada", null);
            }
        }
    }

    $_POST["page"] = "1";

    $_POST["all_options"] = "";

    $_POST["options"] = "";

    $_POST["confirm_action"] = "";
}



$total_facture = $db->query("SELECT count(a.rowid) as num FROM " . MAIN_DB_PREFIX . "easyocr_invoices as a JOIN " . MAIN_DB_PREFIX . "ecm_files as b ON a.fk_file = b.rowid JOIN " . MAIN_DB_PREFIX . "facture_fourn as c ON b.src_object_id = c.rowid JOIN " . MAIN_DB_PREFIX . "societe as d ON c.fk_soc=d.rowid WHERE c.fk_statut=1 $sql");

$total = $db->fetch_object($total_facture);

if (isset($_POST["limit"]) && $_POST["limit"]) {

    if ($_POST["limit"] != "Todos") {

        $limit = $_POST["limit"];
    } else {


        $limit = $total->num;
    }
} else {

    $_POST["limit"] = "10";

    $limit = $_POST["limit"];
}


$page = isset($_POST['page']) ? $_POST['page'] : 1;

$offset = ($page - 1) * $limit;

$list = $db->query("SELECT a.rowid, b.src_object_id as fk_facture_fourn, c.ref, c.ref_supplier, c.datef, c.fk_soc, d.nom as supplier FROM " . MAIN_DB_PREFIX . "easyocr_invoices as a JOIN " . MAIN_DB_PREFIX . "ecm_files as b ON a.fk_file = b.rowid JOIN " . MAIN_DB_PREFIX . "facture_fourn as c ON b.src_object_id = c.rowid JOIN " . MAIN_DB_PREFIX . "societe as d ON c.fk_soc=d.rowid WHERE c.fk_statut=1 $sql ORDER BY c.rowid DESC LIMIT $limit OFFSET $offset");

$num = $db->num_rows($list);


print '

<div class="titre">

    <div class="header">
        
        <img src="' . DOL_URL_ROOT . '/custom/easyocr/img/invoice.png" width="40px" height="40px">

        Facturas  <span class="opacitymedium colorblack paddingleft">(' . $total->num . ')</span>
            
    </div>

</div>


<div class="container">

    <form id="pagination" class="form-inline" action="' . $action . '" method="POST" style="margin-bottom:20px">

        <input type="hidden" name="token" value="' . newToken() . '">

        <input id="limit" type="hidden" name="limit" value="' . $_POST["limit"] . '">

        <input id="page" type="hidden" name="page" value="">

        <input id="all_options" type="hidden" name="all_options" value="">

        <input id="options" type="hidden" name="options" value="">

        <input id="confirm_action" type="hidden" name="confirm_action" value="">

        <label>Ref:</label>

        <input id="ref" type="text" name="ref" value="' . $_POST["ref"] . '"/>

        <label>Ref. proveedor:</label>

        <input id="supplier_ref" type="text" name="supplier_ref" value="' . $_POST["supplier_ref"] . '"/>

        <button type="button" onclick="search()">Buscar</button>

        <button type="button" onclick="clean()">Limpiar</button>


    </form>';



    if ($num > 0) {

        print '
    
        <select id="limit-options" class="flat input">
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="Todos">Todos</option>
        </select>



        <div id="block-actions" class="d-none">

            <select id="actions" class="flat input" name="actions">

                <option value="">-- Seleccione acción --</option>';
        
         print '<option value="Eliminar">Eliminar</option>';
        

        print '</select>


            <button id="confirm" class="btn d-none" onclick="confirm_action()">Confirmar</button>

        </div>
        
        ';

        print "<table>";

        print "
            <tr>
               <th>Ref.</th>
               <th>Ref. proveedor</th>
               <th>Fecha facturación</th>
               <th>Tercero</th>
               <th><center><input id='all-options' type='checkbox'/></center></th>
            </tr>
        ";


        for ($i = 0; $i < $num; $i++) {

            $obj = $db->fetch_object($list);

            print "<tr class='records'>";
            print "  <td><a href='".$dolibarr_main_url_root."/fourn/facture/card.php?facid=".$obj->fk_facture_fourn."&amp;save_lastsearch_values=1'>" . $obj->ref . "</a></td>";
            print "  <td>" . $obj->ref_supplier . "</td>";
            print "  <td>" . $obj->datef . "</td>";
            print "  <td><a href='".$dolibarr_main_url_root."/fourn/card.php?socid=".$obj->fk_soc."&amp;save_lastsearch_values=1'>" . $obj->supplier . "</a></td>";
            print "  <td><center><input class='options' type='checkbox' value='" . $obj->rowid . "'/></center></td>";
            print "</tr>";
        }

        print "</table>";

        // Generación de enlaces de paginación (mostrando solo algunas páginas)
        $total_pages = ceil($total->num / $limit);
        $max_shown_pages = 5; // Máximo número de páginas mostradas
        $start_page = max(1, $page - floor($max_shown_pages / 2));
        $end_page = min($total_pages, $start_page + $max_shown_pages - 1);

        if ($start_page > 1) {

            echo "<a onclick='newPage(1)' class='pg " . active(1, $page) . "'>1</a> ... ";
        }
        for ($i = $start_page; $i <= $end_page; $i++) {
            echo "<a onclick='newPage(" . $i . ")' class='pg " . active($i, $page) . "'>$i</a> ";
        }
        if ($end_page < $total_pages) {
            echo "... <a onclick='newPage(" . $total_pages . ")' class='pg " . active($total_pages, $page) . "'>$total_pages</a>";
        }

    } else {

        print "<center><h2>No se encontraron resultados.</h2></center>";
    }



print '</div>';


print '<script>';

$options = isset($_POST['options']) ? $_POST['options'] : "";

$all_options = isset($_POST['all_options']) ? $_POST['all_options'] : "no";

print '

    let todosValoresSeleccionados="' . $all_options . '";

    let valoresSeleccionadosString = "' . $options . '";

    $("#limit-options").val("' . $_POST["limit"] . '");

    function edit(id){

        window.location="'.$page_view.'?mainmenu='.$mainmenu.'&id="+id
    }

    function clean(){

        $("#ref").val("");

        $("#supplier_ref").val("");
        	
        search();
    }
';

print '</script>';


print '<script src="js/panel.js"></script>';


llxFooter();

?>