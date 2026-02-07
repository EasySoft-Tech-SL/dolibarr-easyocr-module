<?php


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include DOL_DOCUMENT_ROOT."/main.inc.php";
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";

llxHeader("", "configuración de EasyOcr");


print '


<div class="fiche">

    <table class="centpercent notopnoleftnoright table-fiche-title">
        <tbody>
            <tr class="titre">
                <td class="nobordernopadding widthpictotitle valignmiddle col-picto"><span
                        class="fas fa-tools valignmiddle widthpictotitle pictotitle" style=""></span></td>
                <td class="nobordernopadding valignmiddle col-title">
                    <div class="titre inline-block">Acerca del modulo EasyOcr </div>
                </td>
                <td class="nobordernopadding titre_right wordbreakimp right valignmiddle col-right"><a
                        href="../../admin/modules.php?mainmenu=home">Volver al listado
                        de módulos</a></td>
            </tr>
        </tbody>
    </table>


    <div class="tabs" data-role="controlgroup" data-type="horizontal">

      
        <div class="inline-block tabsElem tabsElemActive">
            <div class="tab tabactive" style="margin: 0 !important"><a id="about" class="tab inline-block valignmiddle"
                    href="#" title="Acerca de">Acerca de</a>
            </div>
        </div>
    </div>

    <div id="dragDropAreaTabBar" class="tabBar tabBarWithBottom">
        <h1>EASYOCR PARA <a target="_blank" rel="noopener noreferrer" href="https://www.dolibarr.org">DOLIBARR ERP
                CRM</a></h1>
        <h2>Características</h2>
        <p>Es una herramienta o componente de software diseñada específicamente para extraer contenido textual de archivos PDF (Portable Document Format). Esta funcionalidad es esencial en diversas aplicaciones y servicios que requieren la extracción y manipulación de texto de documentos PDF.</p>
        <h2>Licencias</h2>
        <p>Todos los derechos reservados a EasySoft Tech S.L.</p>
      
    </div>


</div>



';



llxFooter();

?>