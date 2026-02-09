<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       admin/setup.php
 * \ingroup    easyocr
 * \brief      EasyOcr module setup page
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
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

global $db, $langs, $user, $conf;

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/easyocr.lib.php';

// Translations
$langs->loadLangs(array('errors', 'admin', 'easyocr@easyocr'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	// AI OCR settings
	$res = dolibarr_set_const($db, 'EASYOCR_AI_ENABLED', GETPOST('EASYOCR_AI_ENABLED', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_AI_URL', GETPOST('EASYOCR_AI_URL', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_AI_APIKEY', GETPOST('EASYOCR_AI_APIKEY', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_AI_TIMEOUT', GETPOST('EASYOCR_AI_TIMEOUT', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('EasyOcrSetup');
$help_url = '';

llxHeader('', $title, $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = easyocr_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('EasyOcrSetup'), -1, 'easyocr@easyocr');

// --- General info ---
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans("EasyOcrConfigurationOptions").'</td>';
print '<td class="center">'.$langs->trans("Status").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrModuleDescription").'</td>';
print '<td class="center">';
print $langs->trans("EasyOcrModuleActiveInfo");
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// --- AI OCR Configuration form ---
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("EasyOcrAIConfiguration").'</td>';
print '</tr>';

// Enable AI
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAIEnabled").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_AI_ENABLED', !empty($conf->global->EASYOCR_AI_ENABLED) ? $conf->global->EASYOCR_AI_ENABLED : 0, 1);
print '</td>';
print '</tr>';

// AI URL
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAIUrl").'</td>';
print '<td>';
print '<input type="text" name="EASYOCR_AI_URL" class="minwidth400" value="'.dol_escape_htmltag(!empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'http://127.0.0.1:8000').'">';
print '</td>';
print '</tr>';

// API Key
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAIApiKey").'</td>';
print '<td>';
print '<input type="password" name="EASYOCR_AI_APIKEY" class="minwidth400" value="'.dol_escape_htmltag(!empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '').'" autocomplete="off">';
print '</td>';
print '</tr>';

// Timeout
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAITimeout").'</td>';
print '<td>';
print '<input type="number" name="EASYOCR_AI_TIMEOUT" class="width100" min="10" max="600" value="'.dol_escape_htmltag(!empty($conf->global->EASYOCR_AI_TIMEOUT) ? $conf->global->EASYOCR_AI_TIMEOUT : '120').'">';
print ' <span class="opacitymedium">'.$langs->trans("Seconds").'</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

print '<br>';

// Information box
print '<div class="info">';
print '<strong>'.$langs->trans("EasyOcrSetupInfo").'</strong><br>';
print $langs->trans("EasyOcrSetupInfoDesc");
print '</div>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
