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

$page_view = "templates_view.php";

$mainmenu = "easyocr";

$action = $_SERVER['PHP_SELF'] . '?mainmenu='.$mainmenu;

$sql = "";

if (isset($_POST['name']) && $_POST['name']) {

    $sql .= " WHERE name = '" . $_POST['name'] . "'";
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

            
            $db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template $sql");
    

            setEventMessages("Plantillas eliminadas", null);

        } else if (isset($_POST["options"]) && $_POST["options"]) {

            $options = explode(",", $_POST["options"]);

            for ($i = 0; $i < count($options); $i++) {

                $db->query("DELETE FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid=" . $options[$i]);
            }

            if (count($options) > 1) {

                setEventMessages("Plantillas eliminadas", null);
            } else {

                setEventMessages("Plantilla eliminada", null);
            }
        }
    }

    $_POST["page"] = "1";

    $_POST["all_options"] = "";

    $_POST["options"] = "";

    $_POST["confirm_action"] = "";
}


$total_facture = $db->query("SELECT count(rowid) as num FROM " . MAIN_DB_PREFIX . "easyocr_template $sql");

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



$list = $db->query("SELECT rowid, name, DATE_FORMAT(date_creation, '%Y-%m-%d') AS formatted_date FROM " . MAIN_DB_PREFIX . "easyocr_template $sql ORDER BY rowid DESC LIMIT $limit OFFSET $offset");

$num = $db->num_rows($list);


print '

<div class="titre">

    <div class="header">
        
        <img src="' . DOL_URL_ROOT . '/custom/easyocr/img/templates.png" width="40px" height="40px">

        Plantillas  <span class="opacitymedium colorblack paddingleft">(' . $total->num . ')</span>
            
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

        <label>Nombre:</label>

        <input id="name" type="text" name="name" value="' . $_POST["name"] . '"/>

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
               <th>Nombre</th>
               <th>Fecha</th>
               <th><center><input id='all-options' type='checkbox'/></center></th>
            </tr>
        ";


        for ($i = 0; $i < $num; $i++) {

            $obj = $db->fetch_object($list);

            print "<tr class='records'>";
            print "  <td onclick='edit(" . $obj->rowid . ")'>" . $obj->name . "</td>";
            print "  <td onclick='edit(" . $obj->rowid . ")'>" . $obj->formatted_date . "</td>";
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

        $("#name").val("");
        	
        search();
    }
';

print '</script>';


print '<script src="js/panel.js"></script>';


llxFooter();

?>