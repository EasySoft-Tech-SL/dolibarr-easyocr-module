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
 * \file       admin/copying.php
 * \ingroup    easyocr
 * \brief      License agreement page for EasyOcr module
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

$title = $langs->trans('EasyOcrCopying');
$help_url = '';

llxHeader('', $title, $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = easyocr_admin_prepare_head();
print dol_get_fiche_head($head, 'copying', $langs->trans("Module402020Name"), 0, 'easyocr@easyocr');

// --- License summary ---
print '<div class="fichecenter">';

// GPL v3
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefieldcreate fieldrequired" style="width: 25%;">'.$langs->trans("EasyOcrCopyingLicense").'</td>';
print '<td><strong>GNU General Public License v3.0 (GPL-3.0-or-later)</strong></td></tr>';
print '<tr><td>'.$langs->trans("EasyOcrCopyingAuthor").'</td>';
print '<td>EasySoft Tech S.L. (CIF: B16885766)</td></tr>';
print '<tr><td>'.$langs->trans("EasyOcrCopyingContact").'</td>';
print '<td><a href="mailto:info@easysoft.es">info@easysoft.es</a></td></tr>';
print '<tr><td>'.$langs->trans("EasyOcrCopyingWeb").'</td>';
print '<td><a href="https://easyocr.easysoft.es/" target="_blank">easyocr.easysoft.es</a></td></tr>';
print '</table>';

print '<br>';

// What GPL allows
print '<div class="info">';
print '<strong>'.$langs->trans("EasyOcrCopyingGPLTitle").'</strong><br><br>';
print $langs->trans("EasyOcrCopyingGPLDesc");
print '</div>';

print '<br>';

// AI Third-party services notice
print '<div class="warning">';
print '<strong>'.$langs->trans("EasyOcrCopyingAITitle").'</strong><br><br>';
print $langs->trans("EasyOcrCopyingAIDesc");
print '</div>';

print '<br>';

// Full COPYING file
$pathoffile = dol_buildpath("/easyocr/COPYING", 0);
if (!file_exists($pathoffile)) {
	$pathoffile = dol_buildpath("/easyocr/LICENSE", 0);
}

if (file_exists($pathoffile)) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("EasyOcrCopyingFullText").'</td>';
	print '</tr>';
	print '<tr class="oddeven">';
	print '<td><pre style="white-space: pre-wrap; font-size: 11px; max-height: 500px; overflow-y: auto;">'.dol_escape_htmltag(file_get_contents($pathoffile)).'</pre></td>';
	print '</tr>';
	print '</table>';
	print '</div>';
} else {
	print '<div class="opacitymedium">';
	print $langs->trans("EasyOcrCopyingFileNotFound");
	print '</div>';
}

print '</div>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
