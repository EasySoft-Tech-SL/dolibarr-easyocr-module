<?php
/**
 * EasyOcr - Template Edit Page
 * 
 * @package    EasyOcr
 * @copyright  2025-2026 EasySoft Tech S.L.
 * @license    GPL-3.0+
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; 
$tmp2 = realpath(__FILE__); 
$i = strlen($tmp) - 1; 
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--; $j--;
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

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

// Security check
if (!$user->rights->easyocr->write) {
    accessforbidden();
}

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'alpha');

if (!$id) {
    header('Location: templates.php');
    exit;
}

$form = new Form($db);
$langs->load('easyocr@easyocr');

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && GETPOST('save', 'alpha')) {
    $name = GETPOST('name', 'alpha');
    
    if (empty($name)) {
        setEventMessages($langs->trans('EasyOcrFieldRequired'), null, "errors");
    } else {
        // Check duplicate
        $sql = "SELECT COUNT(*) as num FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid <> " . ((int) $id) . " AND name = '" . $db->escape($name) . "'";
        $resql = $db->query($sql);
        $obj_check = $db->fetch_object($resql);
        
        if ($obj_check->num > 0) {
            setEventMessages($langs->trans('EasyOcrTemplateExists'), null, "warnings");
        } else {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "easyocr_template SET name = '" . $db->escape($name) . "' WHERE rowid = " . ((int) $id);
            if ($db->query($sql)) {
                setEventMessages($langs->trans('EasyOcrTemplateUpdatedOk'), null, "mesgs");
                header('Location: templates.php');
                exit;
            } else {
                setEventMessages($langs->trans('EasyOcrErrorUpdatingTpl'), null, "errors");
            }
        }
    }
}

// Load template data
$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "easyocr_template WHERE rowid = " . ((int) $id);
$resql = $db->query($sql);

if ($db->num_rows($resql) === 0) {
    header('Location: templates.php');
    exit;
}

$obj = $db->fetch_object($resql);
$templateName = $obj->name;

// Page header
$arrayofcss = array('/custom/easyocr/css/eo-panel.css');
llxHeader('', $langs->trans('EasyOcrEditTemplateTitle').' - EasyOcr', '', '', 0, 0, array(), $arrayofcss);

print '<div class="eo-panel-header">';
print '<div class="eo-panel-title">';
print '<img src="'.DOL_URL_ROOT.'/custom/easyocr/img/templates.png" class="eo-icon" alt="">';
print '<h1>'.$langs->trans('EasyOcrEditTemplateTitle').'</h1>';
print '</div>';
print '</div>';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="save" value="1">';

print '<div style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 600px; margin: 0 auto;">';

print '<div style="margin-bottom: 25px;">';
print '<label class="fieldrequired" style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">'.$langs->trans('EasyOcrTemplateNameLabel').'</label>';
print '<input type="text" name="name" value="' . dol_escape_htmltag($templateName) . '" class="flat minwidth400" required style="padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 14px; width: 100%;">';
print '</div>';

print '<div style="display: flex; justify-content: flex-end; gap: 10px; padding-top: 20px; border-top: 1px solid #e9ecef;">';
print '<a href="templates.php" class="button button-cancel" style="padding: 10px 20px; background: #6c757d; color: white; border-radius: 4px; text-decoration: none; font-weight: 600;">'.$langs->trans('EasyOcrCancel').'</a>';
print '<button type="submit" class="button" style="padding: 10px 20px; background: #966ea2; color: white; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;"><i class="fa fa-save"></i> '.$langs->trans('EasyOcrSave').'</button>';
print '</div>';

print '</div>';
print '</form>';

llxFooter();
$db->close();