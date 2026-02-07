<?php


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
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


$mainmenu = "easyocr";

$go_back = 'templates.php?mainmenu=' . $mainmenu;


if (!isset($_GET["id"])) {

    print '<script>window.location="' . $go_back . '"</script>';
}


$action = $_SERVER['PHP_SELF'] . '?mainmenu=' . $mainmenu . '&id=' . $_GET["id"];


$submit = "Editar";



if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    if (!$_POST['name']) {

        $message = "<p> El campo <b>plantilla</b> es requerido</p>";

        setEventMessages($message, null, "errors");

    } else {

        $donants = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid<>" . $_GET["id"] . " AND name='" . $_POST['name'] . "'");

        if ($db->num_rows($donants) > 0) {

            setEventMessages("La plantilla ya existe", null, "warnings");
        } else {


            $db->query("UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET name='" . $_POST['name'] . "' WHERE rowid=" . $_GET["id"]);

            setEventMessages("Plantilla editada", null);
        }
    }
} else {

    $donant = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid=" . $_GET["id"]);

    if ($db->num_rows($donant) > 0) {

        $obj = $db->fetch_object($donant);

        $_POST['name'] = $obj->name;
    } else {

        print '<script>window.location="' . $go_back . '"</script>';
    }
}



print '

<div class="titre">

    <div class="header">
        
        <img src="' . DOL_URL_ROOT . '/custom/easyocr/img/templates.png" width="40px" height="40px">';


print   'Editar Plantilla';


print ' </div>


</div>

<hr>';


print '<div class="container">


    <form action="' . $action . '" method="POST">

        <input type="hidden" name="token" value="' . newToken() . '">

        <table width="100%" class="nobordernopadding">
            <tr>
                <td class="nowrap">
                    <label class="titlefieldcreate fieldrequired">Nombre</label>
                </td>
                <td class="nowrap">
                    <input type="text" name="name" value="' . $_POST['name'] . '">
                </td>
            </tr>
        </table>

        <hr>

        <div class="center">';



print      '<button type="submit" class="button btn">' . $submit . '</button>';

print      '<a href="' . $go_back . '"><button type="button" class="button btn">Anular</button></a>

        </div>
        
    
    </form>

</div>

';

llxFooter();

?>