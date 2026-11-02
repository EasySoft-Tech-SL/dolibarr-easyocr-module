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

/**
 * Tax and name keys of one third party, with the same criteria as
 * easyocrGroupDuplicateThirdparties(): a row can only end up in a group through one of
 * these keys, so a row whose keys are all unique cannot be in any group.
 *
 * @param  object $obj Row with nom, siren, tva_intra, fournisseur
 * @return int[]       crc32 of each key (tax keys prefixed T, name key prefixed N)
 */
function easyocrDuplicateCandidateKeys($obj)
{
	$keys = array();
	foreach (array('siren', 'tva_intra') as $col) {
		$v = trim((string) $obj->$col);
		if ($v !== '' && strlen(easyocrNormalizeTaxId($v)) >= 5) {
			$keys[] = crc32('T'.easyocrTaxIdKey($v));
		}
	}
	if (!empty($obj->fournisseur)) {
		$core = easyocrSupplierNameCore((string) $obj->nom);
		if (strlen($core) >= 3) {
			$keys[] = crc32('N'.$core);
		}
	}

	return array_unique($keys);
}

/**
 * Walk the active third parties of this entity in chunks, without holding them all.
 *
 * @param  DoliDB   $db       Database handler
 * @param  callable $callback Called with each row object
 * @return bool               False on SQL error
 */
function easyocrDuplicateWalk($db, $callback)
{
	$last = 0;
	do {
		$sql = "SELECT s.rowid, s.nom, s.siren, s.tva_intra, s.fournisseur, s.client";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " WHERE s.status = 1 AND s.entity IN (".getEntity('societe').") AND s.rowid > ".((int) $last);
		$sql .= " ORDER BY s.rowid ASC";
		$sql .= $db->plimit(2000, 0);
		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		$n = 0;
		while ($obj = $db->fetch_object($resql)) {
			$n++;
			$last = (int) $obj->rowid;
			call_user_func($callback, $obj);
		}
		$db->free($resql);
	} while ($n === 2000);

	return true;
}

/**
 * Third parties that may belong to a duplicate group, read in two passes.
 *
 * With tens of thousands of third parties, loading them all into an array (and the
 * grouping copying them again) exhausts memory_limit. First pass: count how many third
 * parties share each key (an integer hash per key, a few bytes each). Second pass: keep
 * only the rows with a key seen more than once. The grouping then runs on that short
 * list and gives exactly the same groups as on the whole table (a hash collision only
 * keeps one row too many, which the grouping discards).
 *
 * @param  DoliDB $db Database handler
 * @return array|false Rows: id, name, siren, tva_intra, fournisseur, client
 */
function easyocrDuplicateCandidates($db)
{
	$count = array();
	$ok = easyocrDuplicateWalk($db, function ($obj) use (&$count) {
		foreach (easyocrDuplicateCandidateKeys($obj) as $k) {
			$count[$k] = isset($count[$k]) ? 2 : 1;
		}
	});
	if (!$ok) {
		return false;
	}

	$rows = array();
	$ok = easyocrDuplicateWalk($db, function ($obj) use (&$count, &$rows) {
		foreach (easyocrDuplicateCandidateKeys($obj) as $k) {
			if ($count[$k] > 1) {
				$rows[] = array(
					'id' => (int) $obj->rowid, 'name' => $obj->nom, 'siren' => $obj->siren,
					'tva_intra' => $obj->tva_intra, 'fournisseur' => (int) $obj->fournisseur, 'client' => (int) $obj->client,
				);
				return;
			}
		}
	});

	return $ok ? $rows : false;
}

/**
 * Groups of the report: those of easyocrGroupDuplicateThirdparties() on the candidates,
 * without the tax-id groups made only of non-suppliers (EasyOcr only creates suppliers;
 * customers sharing a tax id among themselves are not its business).
 *
 * @param  DoliDB $db Database handler
 * @return array|false array('by_tax_id' => ..., 'by_name' => ...)
 */
function easyocrDuplicateGroups($db)
{
	$rows = easyocrDuplicateCandidates($db);
	if ($rows === false) {
		return false;
	}
	$groups = easyocrGroupDuplicateThirdparties($rows, easyocrOwnCountryCode());
	unset($rows);

	foreach ($groups['by_tax_id'] as $key => $members) {
		$hasSupplier = false;
		foreach ($members as $m) {
			if (!empty($m['fournisseur'])) {
				$hasSupplier = true;
				break;
			}
		}
		if (!$hasSupplier) {
			unset($groups['by_tax_id'][$key]);
		}
	}

	return $groups;
}

/**
 * Member of a group suggested as the one to keep: most supplier invoices, then
 * supplier before non-supplier, then the oldest (lowest id).
 *
 * @param  array $members Group members
 * @param  array $counts  Supplier invoices per third party id
 * @return int            Id of the suggested member
 */
function easyocrDuplicateSuggested($members, $counts)
{
	$best = 0;
	$bestScore = null;
	foreach ($members as $m) {
		$id = (int) $m['id'];
		$score = array(isset($counts[$id]) ? (int) $counts[$id] : 0, empty($m['fournisseur']) ? 0 : 1, -$id);
		if ($bestScore === null || $score > $bestScore) {
			$bestScore = $score;
			$best = $id;
		}
	}

	return $best;
}

/**
 * Print one block of groups.
 *
 * @param  string $titleKey  Translation key of the block title
 * @param  string $descKey   Translation key of the block description
 * @param  array  $list      Groups: key => members
 * @param  array  $counts    Supplier invoices per third party id
 * @param  bool   $showGroup Show the group key column (redundant when the key is the tax id itself)
 * @param  bool   $canMerge  Show the merge buttons
 * @return void
 */
function easyocrPrintDuplicateBlock($titleKey, $descKey, $list, $counts, $showGroup, $canMerge)
{
	global $langs, $db;

	print load_fiche_titre($langs->trans($titleKey).' ('.count($list).')', '', '');
	print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans($descKey)).'</p>';
	if (empty($list)) {
		print '<div class="opacitymedium">'.$langs->trans('EasyOcrDuplicatesNone').'</div><br>';
		return;
	}

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent eo-dup-table">';
	print '<tr class="liste_titre">';
	if ($showGroup) {
		print '<td>'.$langs->trans('EasyOcrDuplicatesGroup').'</td>';
	}
	print '<td>'.$langs->trans('ThirdParty').'</td>';
	print '<td>'.$langs->trans('EasyOcrDuplicatesTaxId').'</td>';
	print '<td>'.$langs->trans('VATIntraShort').'</td>';
	print '<td>'.$langs->trans('Type').'</td>';
	print '<td class="right">'.$langs->trans('EasyOcrDuplicatesInvoices').'</td>';
	if ($canMerge) {
		print '<td class="center"></td>';
	}
	print '</tr>';

	$soc = new Societe($db);
	foreach ($list as $key => $members) {
		$first = true;
		$suggested = easyocrDuplicateSuggested($members, $counts);
		foreach ($members as $m) {
			$id = (int) $m['id'];
			$soc->id = $id;
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
			$nb = isset($counts[$id]) ? (int) $counts[$id] : 0;
			// First row of each group carries a top border: groups stay apart without the key column
			print '<tr class="oddeven eo-dup-row" data-group="'.dol_escape_htmltag((string) $key).'" data-socid="'.$id.'"'.($first ? ' style="border-top: 2px solid #b0bec5;"' : '').'>';
			if ($showGroup) {
				print '<td>'.($first ? '<strong>'.dol_escape_htmltag($key).'</strong>' : '').'</td>';
			}
			print '<td>'.$soc->getNomUrl(1).' <span class="opacitymedium">#'.$id.'</span>';
			if ($id === $suggested) {
				print ' <span class="badge badge-status4 badge-status eo-dup-suggested" title="'.dol_escape_htmltag($langs->trans('EasyOcrDuplicatesSuggestedHelp')).'">'.$langs->trans('EasyOcrDuplicatesSuggested').'</span>';
			}
			print '</td>';
			print '<td>'.dol_escape_htmltag((string) $m['siren']).'</td>';
			print '<td>'.dol_escape_htmltag((string) $m['tva_intra']).'</td>';
			print '<td>'.dol_escape_htmltag(implode(', ', $types));
			if (empty($m['fournisseur'])) {
				print ' <span class="badge badge-status0 badge-status eo-dup-notsupplier">'.$langs->trans('EasyOcrDuplicatesNotSupplier').'</span>';
			}
			print '</td>';
			print '<td class="right eo-dup-nb">'.$nb.'</td>';
			if ($canMerge) {
				$url = DOL_URL_ROOT.'/societe/card.php?socid='.$id.'&action=merge&token='.newToken();
				print '<td class="center nowraponall">';
				print '<a class="'.($id === $suggested ? 'butAction' : 'butActionDelete').' smallpaddingimp eo-dup-merge" href="'.$url.'" title="'.dol_escape_htmltag($langs->trans('EasyOcrDuplicatesMergeHelp')).'">';
				print $langs->trans('EasyOcrDuplicatesMergeHere').'</a>';
				print '</td>';
			}
			print '</tr>';
			$first = false;
		}
	}
	print '</table>';
	print '</div><br>';
}

$groups = easyocrDuplicateGroups($db);
if ($groups === false) {
	dol_print_error($db);
	llxFooter();
	$db->close();
	exit;
}

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
foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
	$sqlc = "SELECT fk_soc, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."facture_fourn";
	$sqlc .= " WHERE fk_soc IN (".implode(',', $chunk).") AND entity IN (".getEntity('supplier_invoice').")";
	$sqlc .= " GROUP BY fk_soc";
	$resc = $db->query($sqlc);
	if ($resc) {
		while ($o = $db->fetch_object($resc)) {
			$invoiceCount[(int) $o->fk_soc] = (int) $o->nb;
		}
		$db->free($resc);
	}
}

// Dolibarr's own merge (societe/card.php?action=merge) asks there for the third party
// to absorb; it shows its button to whoever can create and delete third parties.
$canMerge = easyocrCheckRight($user, 'societe', 'creer') && easyocrCheckRight($user, 'societe', 'supprimer');

// By tax id the group key is the tax id itself, already in its own columns
easyocrPrintDuplicateBlock('EasyOcrDuplicatesByTaxId', 'EasyOcrDuplicatesByTaxIdDesc', $groups['by_tax_id'], $invoiceCount, false, $canMerge);
easyocrPrintDuplicateBlock('EasyOcrDuplicatesByName', 'EasyOcrDuplicatesByNameDesc', $groups['by_name'], $invoiceCount, true, $canMerge);

print '<div class="info">'.$langs->trans($canMerge ? 'EasyOcrDuplicatesHowToMergeHere' : 'EasyOcrDuplicatesHowToMerge').'</div>';

llxFooter();
$db->close();
