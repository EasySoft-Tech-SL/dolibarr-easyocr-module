<?php
/* Copyright (C) 2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                    Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       inbox.php
 * \ingroup    easyocr
 * \brief      Received documents: PDFs handed over by other modules (EasyScan, ...).
 *
 * Nothing on this page calls the AI service. From here the user decides what to do with
 * each document: open it in the viewer, send several in a batch (both at the normal cost,
 * and only when he presses the button there) or discard it.
 *
 * Changing data: only confirm_delete, POST with token through $form->formconfirm().
 */

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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/lib/easyocr.lib.php';

$langs->loadLangs(array('easyocr@easyocr'));

if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	accessforbidden();
}
$permWrite = easyocrCheckRight($user, 'easyocr', 'write');

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = (int) GETPOST('id', 'int');
$showAll = (int) GETPOST('all', 'int');
$self = dol_buildpath('/easyocr/inbox.php', 1);

/*
 * Actions
 */
if ($action === 'confirm_delete' && $confirm === 'yes' && $permWrite) {
	$row = easyocrInboxFetch($id);
	if ($row && easyocrInboxDelete($row)) {
		setEventMessages($langs->trans('EasyOcrInboxDeleted', dol_escape_htmltag($row->label)), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('EasyOcrInboxErrDelete'), null, 'errors');
	}
	header('Location: '.$self.($showAll ? '?all=1' : ''));
	exit;
}

/*
 * View
 */
$form = new Form($db);
$title = $langs->trans('EasyOcrInboxTitle');
llxHeader('', $title, '', '', 0, 0, array(), array('/easyocr/css/easyocr.css'), '', 'mod-easyocr page-inbox');

print '<div class="eo-page-header">';
print '  <div class="eo-page-header-icon eo-page-header-icon--inbox"><i class="fas fa-inbox"></i></div>';
print '  <div class="eo-page-header-text">';
print '    <h1>'.dol_escape_htmltag($title).'</h1>';
print '    <p>'.dol_escape_htmltag($langs->transnoentitiesnoconv('EasyOcrInboxSubtitle')).'</p>';
print '  </div>';
print '</div>';

if ($action === 'delete' && $permWrite) {
	$row = easyocrInboxFetch($id);
	if ($row) {
		print $form->formconfirm(
			$self.'?id='.((int) $row->rowid).($showAll ? '&all=1' : ''),
			$langs->trans('EasyOcrInboxDeleteTitle'),
			$langs->trans('EasyOcrInboxDeleteQuestion', dol_escape_htmltag($row->label)),
			'confirm_delete',
			'',
			0,
			0
		);
	}
}

$rows = easyocrInboxList(!$showAll);

// Filter
print '<div class="eo-inbox-toolbar">';
if ($showAll) {
	print '<a class="butAction butActionSmall" href="'.dol_escape_htmltag($self).'">'.$langs->trans('EasyOcrInboxShowPending').'</a>';
} else {
	print '<a class="butAction butActionSmall" href="'.dol_escape_htmltag($self.'?all=1').'">'.$langs->trans('EasyOcrInboxShowAll').'</a>';
}
print '</div>';

// Selection goes to batch.php by GET: nothing is sent until the user presses «Process» there.
print '<form method="GET" action="'.dol_escape_htmltag(dol_buildpath('/easyocr/batch.php', 1)).'" id="eo-inbox-form">';
print '<input type="hidden" name="tab" value="new">';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent eo-inbox">';
print '<tr class="liste_titre">';
if ($permWrite) {
	print '<th class="center width25"><input type="checkbox" id="eo-inbox-all" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('EasyOcrInboxSelectAll')).'"></th>';
}
print '<th>'.$langs->trans('EasyOcrInboxColDocument').'</th>';
print '<th>'.$langs->trans('EasyOcrInboxColOrigin').'</th>';
print '<th>'.$langs->trans('EasyOcrInboxColInvoice').'</th>';
print '<th>'.$langs->trans('EasyOcrInboxColReceived').'</th>';
print '<th class="right">'.$langs->trans('EasyOcrInboxColSize').'</th>';
print '<th class="center">'.$langs->trans('Status').'</th>';
print '<th class="right">'.$langs->trans('EasyOcrInboxColActions').'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="'.($permWrite ? 8 : 7).'" class="opacitymedium center eo-inbox-empty">'.$langs->trans('EasyOcrInboxEmpty').'</td></tr>';
}

$fileUrl = dol_buildpath('/easyocr/ajax/inbox_file.php', 1);
$invoiceCard = DOL_URL_ROOT.'/fourn/facture/card.php?facid=';
$nbPending = 0;
foreach ($rows as $r) {
	$pending = ((int) $r->status === EASYOCR_INBOX_PENDING);
	if ($pending) {
		$nbPending++;
	}
	$path = easyocrInboxFilePath($r);
	$exists = ($path !== '' && is_file($path));
	$author = trim($r->firstname.' '.$r->lastname);
	if ($author === '') {
		$author = (string) $r->login;
	}

	print '<tr class="oddeven'.((int) $r->rowid === $id ? ' eo-inbox-row--focus' : '').'" id="eo-inbox-'.((int) $r->rowid).'">';
	if ($permWrite) {
		print '<td class="center">';
		if ($pending && $exists) {
			print '<input type="checkbox" class="eo-inbox-check" name="inbox[]" value="'.((int) $r->rowid).'"'.((int) $r->rowid === $id ? ' checked' : '').'>';
		}
		print '</td>';
	}

	// Document
	print '<td class="tdoverflowmax300">';
	print '<span class="fas fa-file-pdf pictofixedwidth" style="color:#c62828"></span>';
	if ($exists) {
		print '<a href="'.dol_escape_htmltag($fileUrl.'?id='.((int) $r->rowid)).'" target="_blank" rel="noopener" class="eo-inbox-name">'.dol_escape_htmltag($r->label).'</a>';
	} else {
		print '<span class="eo-inbox-name">'.dol_escape_htmltag($r->label).'</span> <span class="badge badge-status8">'.$langs->trans('EasyOcrInboxFileMissing').'</span>';
	}
	// The same document already went through the AI service: say so before paying again.
	if ($pending && !empty($r->file_hash)) {
		$known = easyocrLookupProcessedFile($r->file_hash);
		if ($known !== null) {
			print '<br><span class="small" style="color:#b8860b"><span class="fas fa-exclamation-circle pictofixedwidth"></span>'.dol_escape_htmltag($langs->transnoentities('EasyOcrFileAlreadyProcessed', dol_print_date($known['date_creation'], 'dayhour'))).'</span>';
		}
	}
	print '</td>';

	print '<td><span class="badge badge-status1 eo-inbox-origin">'.dol_escape_htmltag(easyocrInboxOriginLabel($r->origin)).'</span></td>';

	// Supplier invoice the document came with
	print '<td>';
	if (!empty($r->fk_facture_fourn) && !empty($r->invoice_ref)) {
		print '<a href="'.dol_escape_htmltag($invoiceCard.((int) $r->fk_facture_fourn)).'">'.dol_escape_htmltag($r->invoice_ref).'</a>';
	} else {
		print '<span class="opacitymedium">-</span>';
	}
	print '</td>';

	print '<td class="nowraponall">'.dol_print_date($db->jdate($r->datec), 'dayhour').'<br><span class="opacitymedium small">'.dol_escape_htmltag($author).'</span></td>';
	print '<td class="right nowraponall">'.dol_print_size((int) $r->file_size).'</td>';

	// Status
	print '<td class="center nowraponall">';
	if ($pending) {
		print '<span class="badge badge-status1">'.$langs->trans('EasyOcrInboxStatusPending').'</span>';
	} elseif ((int) $r->status === EASYOCR_INBOX_BATCHED) {
		print '<span class="badge badge-status4" title="'.dol_escape_htmltag((string) $r->batch_ref).'">'.$langs->trans('EasyOcrInboxStatusBatched').'</span>';
	} else {
		print '<span class="badge badge-status6">'.$langs->trans('EasyOcrInboxStatusProcessed').'</span>';
		if (!empty($r->fk_invoice_created) && !empty($r->created_ref)) {
			print '<br><a class="small" href="'.dol_escape_htmltag($invoiceCard.((int) $r->fk_invoice_created)).'">'.dol_escape_htmltag($r->created_ref).'</a>';
		}
	}
	print '</td>';

	// Actions: compact icon links (the row already carries a lot of columns)
	print '<td class="right nowraponall eo-inbox-actions">';
	if ($exists) {
		$lbl = dol_escape_htmltag($langs->transnoentitiesnoconv('EasyOcrInboxDownload'));
		print '<a class="eo-inbox-act eo-inbox-act--download" href="'.dol_escape_htmltag($fileUrl.'?id='.((int) $r->rowid).'&download=1').'" title="'.$lbl.'" aria-label="'.$lbl.'"><span class="fas fa-download"></span></a>';
	}
	if ($permWrite && $pending && $exists) {
		$lbl = dol_escape_htmltag($langs->transnoentitiesnoconv('EasyOcrInboxOpenViewer'));
		print '<a class="eo-inbox-act eo-inbox-act--viewer" href="'.dol_escape_htmltag(dol_buildpath('/easyocr/extract.php', 1).'?inbox='.((int) $r->rowid)).'" title="'.$lbl.'" aria-label="'.$lbl.'"><span class="fas fa-search-plus"></span></a>';
	}
	if ($permWrite) {
		$lbl = dol_escape_htmltag($langs->transnoentitiesnoconv('EasyOcrInboxDiscard'));
		print '<a class="eo-inbox-act eo-inbox-act--discard" href="'.dol_escape_htmltag($self.'?action=delete&id='.((int) $r->rowid).($showAll ? '&all=1' : '')).'" title="'.$lbl.'" aria-label="'.$lbl.'"><span class="fas fa-trash-alt"></span></a>';
	}
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

if ($permWrite && $nbPending > 0) {
	print '<div class="eo-inbox-footer">';
	print '<button type="submit" class="butAction" id="eo-inbox-batch">'.$langs->trans('EasyOcrInboxSendToBatch').'</button>';
	print '</div>';
	print '<p class="opacitymedium small">'.img_picto('', 'info', 'class="pictofixedwidth"').$langs->trans('EasyOcrInboxCostHint').'</p>';
}
print '</form>';
?>
<script>
(function () {
	var all = document.getElementById('eo-inbox-all');
	var form = document.getElementById('eo-inbox-form');
	var btn = document.getElementById('eo-inbox-batch');
	function checks() { return form ? form.querySelectorAll('.eo-inbox-check') : []; }
	function sync() {
		if (!btn) return;
		var n = 0;
		Array.prototype.forEach.call(checks(), function (c) { if (c.checked) n++; });
		btn.disabled = (n === 0);
		btn.classList.toggle('butActionRefused', n === 0);
	}
	if (all) {
		all.addEventListener('change', function () {
			Array.prototype.forEach.call(checks(), function (c) { c.checked = all.checked; });
			sync();
		});
	}
	Array.prototype.forEach.call(checks(), function (c) { c.addEventListener('change', sync); });
	sync();
})();
</script>
<?php
llxFooter();
$db->close();
