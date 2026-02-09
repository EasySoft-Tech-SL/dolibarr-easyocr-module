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
 * \file       admin/telemetry.php
 * \ingroup    easyocr
 * \brief      Telemetry & Data Protection policy page for EasyOcr AI module
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
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
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

$backtopage = GETPOST('backtopage', 'alpha');


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('EasyOcrTelemetry');
$help_url = '';

llxHeader('', $title, $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = easyocr_admin_prepare_head();
print dol_get_fiche_head($head, 'telemetry', $langs->trans("Module402020Name"), 0, 'easyocr@easyocr');

print '<div class="fichecenter">';

// =============================================
// 1. Introduction
// =============================================
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefieldcreate" style="width: 25%;">'.$langs->trans("EasyOcrTelemetryModule").'</td>';
print '<td><strong>easyOCR AI</strong> - EasySoft Tech S.L. (CIF: B16885766)</td></tr>';
print '<tr><td>'.$langs->trans("EasyOcrTelemetryPurpose").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryPurposeDesc").'</td></tr>';
print '<tr><td>'.$langs->trans("EasyOcrTelemetryContact").'</td>';
print '<td><a href="mailto:info@easysoft.es">info@easysoft.es</a></td></tr>';
print '</table>';

print '<br>';

// =============================================
// 2. AI Third-Party Services
// =============================================
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2"><span class="fas fa-robot" style="color: #8e44ad;"></span> '.$langs->trans("EasyOcrTelemetryAITitle").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefieldcreate">'.$langs->trans("EasyOcrTelemetryAIWhat").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryAIWhatDesc").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrTelemetryAISent").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryAISentDesc").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrTelemetryAINever").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryAINeverDesc").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrTelemetryAIProvider").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryAIProviderDesc").'</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// =============================================
// 3. Data we send / don't send
// =============================================
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2"><span class="fas fa-check-circle" style="color: #27ae60;"></span> '.$langs->trans("EasyOcrTelemetryDataSent").'</td>';
print '</tr>';

$dataSent = array(
	'EasyOcrTelemetryDataPDF',
	'EasyOcrTelemetryDataLang',
	'EasyOcrTelemetryDataDomain',
);
foreach ($dataSent as $key) {
	print '<tr class="oddeven">';
	print '<td class="titlefieldcreate">✅</td>';
	print '<td>'.$langs->trans($key).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

print '<br>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2"><span class="fas fa-ban" style="color: #e74c3c;"></span> '.$langs->trans("EasyOcrTelemetryDataNever").'</td>';
print '</tr>';

$dataNever = array(
	'EasyOcrTelemetryNeverClients',
	'EasyOcrTelemetryNeverInvoices',
	'EasyOcrTelemetryNeverBank',
	'EasyOcrTelemetryNeverPasswords',
	'EasyOcrTelemetryNeverPersonal',
);
foreach ($dataNever as $key) {
	print '<tr class="oddeven">';
	print '<td class="titlefieldcreate">❌</td>';
	print '<td>'.$langs->trans($key).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

print '<br>';

// =============================================
// 4. Security & Privacy
// =============================================
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2"><span class="fas fa-lock" style="color: #2c3e50;"></span> '.$langs->trans("EasyOcrTelemetrySecurity").'</td>';
print '</tr>';

$security = array(
	'EasyOcrTelemetrySecHTTPS',
	'EasyOcrTelemetrySecEU',
	'EasyOcrTelemetrySecAccess',
	'EasyOcrTelemetrySecGDPR',
	'EasyOcrTelemetrySecNoSale',
);
foreach ($security as $key) {
	print '<tr class="oddeven">';
	print '<td class="titlefieldcreate">🔒</td>';
	print '<td>'.$langs->trans($key).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

print '<br>';

// =============================================
// 5. Legal basis
// =============================================
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2"><span class="fas fa-balance-scale" style="color: #8e44ad;"></span> '.$langs->trans("EasyOcrTelemetryLegal").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefieldcreate">'.$langs->trans("EasyOcrTelemetryLegalContract").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryLegalContractDesc").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrTelemetryLegalLegitimate").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryLegalLegitimateDesc").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrTelemetryLegalGDPR").'</td>';
print '<td>'.$langs->trans("EasyOcrTelemetryLegalGDPRDesc").'</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// =============================================
// 6. User Rights
// =============================================
print '<div class="info">';
print '<strong>'.$langs->trans("EasyOcrTelemetryRightsTitle").'</strong><br><br>';
print $langs->trans("EasyOcrTelemetryRightsDesc");
print '<br><br>';
print '<strong>'.$langs->trans("EasyOcrTelemetryContact").':</strong> <a href="mailto:info@easysoft.es">info@easysoft.es</a>';
print '</div>';

print '</div>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
