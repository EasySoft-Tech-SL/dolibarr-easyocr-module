<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       duplicates.php
 * \ingroup    easyocr
 * \brief      Read-only report of third parties that are probably the same company
 *
 * Older versions of the batch webhook created a new supplier when the tax id came with
 * a country prefix or misread. This page lists the likely duplicates so they can be
 * merged with Dolibarr's own "Merge" button on the third-party card. It changes nothing.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/lib/easyocr.lib.php';

$langs->loadLangs(array("easyocr@easyocr", "companies"));

// Read-only page: module read right plus the right to see third parties
if (!easyocrCheckRight($user, 'easyocr', 'read') || !easyocrCheckRight($user, 'societe', 'lire')) {
	accessforbidden();
}

$title = $langs->trans('EasyOcrDuplicatesTitle');
llxHeader('', $title, '', '', 0, 0, array(), array(), '', 'mod-easyocr');

print '<div class="eo-page-header">';
print '  <div class="eo-page-header-icon eo-page-header-icon--inv"><i class="fas fa-clone"></i></div>';
print '  <div class="eo-page-header-text">';
print '    <h1>'.dol_escape_htmltag($title).'</h1>';
print '    <p>'.dol_escape_htmltag($langs->trans('EasyOcrDuplicatesDesc')).'</p>';
print '  </div>';
print '</div>';

// Active third parties of this entity, with the fields that identify them
$rows = array();
$sql = "SELECT s.rowid, s.nom, s.siren, s.tva_intra, s.fournisseur, s.client";
$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
$sql .= " WHERE s.status = 1 AND s.entity IN (".getEntity('societe').")";
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	llxFooter();
	$db->close();
	exit;
}
while ($obj = $db->fetch_object($resql)) {
	$rows[] = array(
		'id' => (int) $obj->rowid, 'name' => $obj->nom, 'siren' => $obj->siren,
		'tva_intra' => $obj->tva_intra, 'fournisseur' => (int) $obj->fournisseur, 'client' => (int) $obj->client,
	);
}
$db->free($resql);

$groups = easyocrGroupDuplicateThirdparties($rows, easyocrOwnCountryCode());

// Supplier invoices per third party in the groups: helps choosing the one to keep
$ids = array();
foreach (array('by_tax_id', 'by_name') as $kind) {
	foreach ($groups[$kind] as $members) {
		foreach ($members as $m) {
			$ids[(int) $m['id']] = true;
		}
	}
}
$invoiceCount = array();
if (!empty($ids)) {
	$sqlc = "SELECT fk_soc, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."facture_fourn";
	$sqlc .= " WHERE fk_soc IN (".implode(',', array_keys($ids)).") AND entity IN (".getEntity('supplier_invoice').")";
	$sqlc .= " GROUP BY fk_soc";
	$resc = $db->query($sqlc);
	if ($resc) {
		while ($o = $db->fetch_object($resc)) {
			$invoiceCount[(int) $o->fk_soc] = (int) $o->nb;
		}
		$db->free($resc);
	}
}

/**
 * Print one block of groups.
 *
 * @param  string $titleKey  Translation key of the block title
 * @param  string $descKey   Translation key of the block description
 * @param  array  $list      Groups: key => members
 * @param  array  $counts    Supplier invoices per third party id
 * @return void
 */
function easyocrPrintDuplicateBlock($titleKey, $descKey, $list, $counts)
{
	global $langs, $db;

	print load_fiche_titre($langs->trans($titleKey).' ('.count($list).')', '', '');
	print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans($descKey)).'</p>';
	if (empty($list)) {
		print '<div class="opacitymedium">'.$langs->trans('EasyOcrDuplicatesNone').'</div><br>';
		return;
	}

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('EasyOcrDuplicatesGroup').'</td>';
	print '<td>'.$langs->trans('ThirdParty').'</td>';
	print '<td>'.$langs->trans('EasyOcrDuplicatesTaxId').'</td>';
	print '<td>'.$langs->trans('VATIntraShort').'</td>';
	print '<td>'.$langs->trans('Type').'</td>';
	print '<td class="right">'.$langs->trans('EasyOcrDuplicatesInvoices').'</td>';
	print '</tr>';

	$soc = new Societe($db);
	foreach ($list as $key => $members) {
		$first = true;
		foreach ($members as $m) {
			$soc->id = $m['id'];
			$soc->name = $m['name'];
			$soc->fournisseur = $m['fournisseur'];
			$soc->client = isset($m['client']) ? $m['client'] : 0;
			$types = array();
			if (!empty($m['fournisseur'])) {
				$types[] = $langs->trans('Supplier');
			}
			if (!empty($m['client'])) {
				$types[] = $langs->trans('Customer');
			}
			print '<tr class="oddeven">';
			print '<td>'.($first ? '<strong>'.dol_escape_htmltag($key).'</strong>' : '').'</td>';
			print '<td>'.$soc->getNomUrl(1).' <span class="opacitymedium">#'.((int) $m['id']).'</span></td>';
			print '<td>'.dol_escape_htmltag((string) $m['siren']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $m['tva_intra']).'</td>';
			print '<td>'.dol_escape_htmltag(implode(', ', $types)).'</td>';
			print '<td class="right">'.(isset($counts[$m['id']]) ? (int) $counts[$m['id']] : 0).'</td>';
			print '</tr>';
			$first = false;
		}
	}
	print '</table>';
	print '</div><br>';
}

easyocrPrintDuplicateBlock('EasyOcrDuplicatesByTaxId', 'EasyOcrDuplicatesByTaxIdDesc', $groups['by_tax_id'], $invoiceCount);
easyocrPrintDuplicateBlock('EasyOcrDuplicatesByName', 'EasyOcrDuplicatesByNameDesc', $groups['by_name'], $invoiceCount);

print '<div class="info">'.$langs->trans('EasyOcrDuplicatesHowToMerge').'</div>';

llxFooter();
$db->close();
