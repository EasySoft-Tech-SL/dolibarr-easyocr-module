<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       webhook_logs.php
 * \ingroup    easyocr
 * \brief      Vista de los registros de webhooks recibidos (llx_easyocr_webhook_log)
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/lib/easyocr.lib.php';

$langs->loadLangs(array("easyocr@easyocr", "other"));

// ─── Helpers (page-local) ────────────────────────────────────────────────
if (!function_exists('easyocr_format_status_badge')) {
	function easyocr_format_status_badge($status)
	{
		if ($status === 'ok')     return '<span class="badge badge-status4 badge-status">ok</span>';
		if ($status === 'repeat') return '<span class="badge badge-status1 badge-status">repeat</span>';
		if ($status === 'error')  return '<span class="badge badge-status8 badge-status">error</span>';
		if (empty($status))       return '<span class="opacitymedium">—</span>';
		return '<span class="badge badge-status0 badge-status">'.dol_escape_htmltag($status).'</span>';
	}
}
if (!function_exists('easyocr_truncate_id')) {
	function easyocr_truncate_id($id, $len = 12)
	{
		if (empty($id) || strlen($id) <= $len) return (string) $id;
		return substr($id, 0, $len) . '…';
	}
}

// Parameters
$action     = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'list';
$confirm    = GETPOST('confirm', 'alpha');
$id         = GETPOST('id', 'int');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'easyocrwebhooklogs';
$optioncss  = GETPOST('optioncss', 'aZ');

// Search filters
$search_event    = GETPOST('search_event', 'alphanohtml');
$search_status   = GETPOST('search_status', 'alphanohtml');
$search_filename = GETPOST('search_filename', 'alphanohtml');
$search_batch    = GETPOST('search_batch', 'alphanohtml');
$search_datec_start = dol_mktime(0, 0, 0, GETPOST('search_datec_startmonth', 'int'), GETPOST('search_datec_startday', 'int'), GETPOST('search_datec_startyear', 'int'));
$search_datec_end   = dol_mktime(23, 59, 59, GETPOST('search_datec_endmonth', 'int'), GETPOST('search_datec_endday', 'int'), GETPOST('search_datec_endyear', 'int'));

// Pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;

if (!$sortfield) {
	$sortfield = "rowid";
}
if (!$sortorder) {
	$sortorder = "DESC";
}

$form = new Form($db);

// Security check — read access only; admins also see the full payload
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	accessforbidden();
}
$permissiontoread   = easyocrCheckRight($user, 'easyocr', 'read');
$permissiontodelete = easyocrCheckRight($user, 'easyocr', 'delete');
$canSeePayload = $user->admin || $permissiontodelete; // payload may contain supplier data, NIF, totals


/*
 * Actions
 */

// Reset filters
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_event    = '';
	$search_status   = '';
	$search_filename = '';
	$search_batch    = '';
	$search_datec_start = '';
	$search_datec_end   = '';
}

// Delete one
if ($action == 'confirm_delete' && $confirm == 'yes' && $permissiontodelete && $id > 0) {
	$sqlDel = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_webhook_log";
	$sqlDel .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
	if ($db->query($sqlDel)) {
		setEventMessages($langs->trans('EasyOcrWebhookLogDeleted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	$action = 'list';
}

// Purge logs older than 30 days
if ($action == 'confirm_purgeold' && $confirm == 'yes' && $permissiontodelete) {
	$sqlPurge = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_webhook_log";
	$sqlPurge .= " WHERE entity = ".((int) $conf->entity);
	$sqlPurge .= " AND datec < (NOW() - INTERVAL 30 DAY)";
	if ($db->query($sqlPurge)) {
		$nbDeleted = $db->affected_rows($db->query("SELECT 0")); // affected_rows is unreliable post-DDL; just inform generic
		setEventMessages($langs->trans('EasyOcrWebhookLogPurgedOld'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	$action = 'list';
}


/*
 * View
 */

$title = $langs->trans('EasyOcrWebhookLogsTitle');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, array(), array(), '', 'bodyforlist mod-easyocr');

// Page header (consistent EasyOcr style)
print '<div class="eo-page-header">';
print '  <div class="eo-page-header-icon eo-page-header-icon--inv"><i class="fas fa-satellite-dish"></i></div>';
print '  <div class="eo-page-header-text">';
print '    <h1>'.dol_escape_htmltag($title).'</h1>';
print '    <p>'.dol_escape_htmltag($langs->trans('EasyOcrWebhookLogsDesc')).'</p>';
print '  </div>';
print '</div>';


// ─── Detail view ─────────────────────────────────────────────────────────
if ($action == 'view' && $id > 0) {

	$sqlOne = "SELECT rowid, entity, batch_id, event, document_id, document_filename, document_status,";
	$sqlOne .= " batch_status, batch_progress, invoice_id, invoice_ref, supplier_id,";
	$sqlOne .= " processing_status, processing_message, payload, datec";
	$sqlOne .= " FROM ".MAIN_DB_PREFIX."easyocr_webhook_log";
	$sqlOne .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
	$resOne = $db->query($sqlOne);

	if (!$resOne || $db->num_rows($resOne) < 1) {
		print '<div class="warning">'.$langs->trans('EasyOcrWebhookLogNotFound').'</div>';
		print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'">&larr; '.$langs->trans('Back').'</a>';
		llxFooter();
		$db->close();
		exit;
	}

	$row = $db->fetch_object($resOne);

	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'">&larr; '.$langs->trans('Back').'</a>';
	if ($permissiontodelete) {
		print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=delete&id='.((int) $row->rowid).'&token='.newToken().'">'.$langs->trans('Delete').'</a>';
	}
	print '</div>';

	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';

	print '<tr><td class="titlefield">'.$langs->trans('EasyOcrWebhookLogId').'</td>';
	print '<td>#'.((int) $row->rowid).'</td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogReceivedAt').'</td>';
	print '<td>'.dol_print_date($db->jdate($row->datec), 'dayhour').'</td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogEvent').'</td>';
	print '<td><code>'.dol_escape_htmltag($row->event).'</code></td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogBatchId').'</td>';
	print '<td><code class="small">'.dol_escape_htmltag($row->batch_id).'</code></td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogDocumentId').'</td>';
	print '<td><code class="small">'.dol_escape_htmltag($row->document_id).'</code></td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogFilename').'</td>';
	print '<td>'.dol_escape_htmltag($row->document_filename).'</td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogDocStatus').'</td>';
	print '<td>'.dol_escape_htmltag($row->document_status).'</td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogProcStatus').'</td>';
	print '<td>'.easyocr_format_status_badge($row->processing_status).'</td></tr>';

	print '<tr><td>'.$langs->trans('EasyOcrWebhookLogProcMessage').'</td>';
	print '<td>'.dol_escape_htmltag($row->processing_message).'</td></tr>';

	if ($row->invoice_id > 0) {
		print '<tr><td>'.$langs->trans('EasyOcrWebhookLogInvoice').'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/fourn/facture/card.php?facid='.((int) $row->invoice_id).'">';
		print img_picto('', 'supplier_invoice', 'class="pictofixedwidth"');
		print dol_escape_htmltag($row->invoice_ref ? $row->invoice_ref : '#'.$row->invoice_id);
		print '</a></td></tr>';
	}

	if ($row->supplier_id > 0) {
		print '<tr><td>'.$langs->trans('EasyOcrWebhookLogSupplier').'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/fourn/card.php?socid='.((int) $row->supplier_id).'">';
		print img_picto('', 'company', 'class="pictofixedwidth"');
		print '#'.((int) $row->supplier_id);
		print '</a></td></tr>';
	}

	print '</table>';

	// Payload viewer (admin / delete-perm only — may contain supplier data, NIFs, totals)
	if ($canSeePayload && !empty($row->payload)) {
		$decoded = json_decode($row->payload, true);
		$pretty  = ($decoded !== null) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $row->payload;
		print '<br>';
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>'.$langs->trans('EasyOcrWebhookLogPayload').'</td></tr>';
		print '<tr class="oddeven"><td>';
		// Inside <pre> use htmlspecialchars to keep real newlines.
		print '<pre style="white-space: pre-wrap; word-break: break-word; font-size: 11px; max-height: 480px; overflow-y: auto; background:#f8f9fa; padding:12px; border:1px solid #e0e4e8; border-radius:4px;">';
		print htmlspecialchars($pretty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		print '</pre>';
		print '</td></tr>';
		print '</table>';
		print '</div>';
	}

	print '</div>';

	llxFooter();
	$db->close();
	exit;
}


// ─── List view ───────────────────────────────────────────────────────────

// Confirm purge old
if ($action == 'purgeold') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'],
		$langs->trans('EasyOcrWebhookLogPurgeOldTitle'),
		$langs->trans('EasyOcrWebhookLogConfirmPurgeOld'),
		'confirm_purgeold',
		'',
		0,
		1
	);
}

// Confirm single delete (from list view)
if ($action == 'delete' && $id > 0) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.((int) $id),
		$langs->trans('EasyOcrWebhookLogDeleteTitle'),
		$langs->trans('EasyOcrWebhookLogConfirmDelete'),
		'confirm_delete',
		'',
		0,
		1
	);
}

// Build SELECT
$sql = "SELECT rowid, batch_id, event, document_filename, document_status,";
$sql .= " invoice_id, invoice_ref, supplier_id, processing_status, processing_message, datec";
$sql .= " FROM ".MAIN_DB_PREFIX."easyocr_webhook_log";
$sql .= " WHERE entity = ".((int) $conf->entity);

if ($search_event) {
	$sql .= natural_search("event", $search_event);
}
if ($search_status) {
	$sql .= natural_search("processing_status", $search_status);
}
if ($search_filename) {
	$sql .= natural_search("document_filename", $search_filename);
}
if ($search_batch) {
	$sql .= natural_search("batch_id", $search_batch);
}
if ($search_datec_start) {
	$sql .= " AND datec >= '".$db->idate($search_datec_start)."'";
}
if ($search_datec_end) {
	$sql .= " AND datec <= '".$db->idate($search_datec_end)."'";
}

// Count total
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/^SELECT[\s\S]*FROM/Ui', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
	$resCnt = $db->query($sqlforcount);
	if ($resCnt) {
		$objCnt = $db->fetch_object($resCnt);
		$nbtotalofrecords = $objCnt->nbtotalofrecords;
		$db->free($resCnt);
	}
	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
}

$sql .= $db->order($sortfield, $sortorder);
if ($limit) {
	$sql .= $db->plimit($limit + 1, $offset);
}

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);


// Build URL params for filter persistence
$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_event != '')    $param .= '&search_event='.urlencode($search_event);
if ($search_status != '')   $param .= '&search_status='.urlencode($search_status);
if ($search_filename != '') $param .= '&search_filename='.urlencode($search_filename);
if ($search_batch != '')    $param .= '&search_batch='.urlencode($search_batch);

// Top action button (purge old logs)
if ($permissiontodelete) {
	print '<div class="tabsAction">';
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=purgeold&token='.newToken().'">'.img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans('EasyOcrWebhookLogPurgeOldBtn').'</a>';
	print '</div>';
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';
print '<input type="hidden" name="contextpage" value="'.dol_escape_htmltag($contextpage).'">';

print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filter row
print '<tr class="liste_titre_filter">';

// datec range
print '<td class="liste_titre center">';
print '<div class="nowrap">'.$form->selectDate($search_datec_start, 'search_datec_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')).'</div>';
print '<div class="nowrap">'.$form->selectDate($search_datec_end,   'search_datec_end',   0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</div>';
print '</td>';

// event
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_event" value="'.dol_escape_htmltag($search_event).'" placeholder="batch.document.completed"></td>';

// batch_id
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_batch" value="'.dol_escape_htmltag($search_batch).'"></td>';

// filename
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_filename" value="'.dol_escape_htmltag($search_filename).'"></td>';

// processing_status
print '<td class="liste_titre center">';
print '<select name="search_status" class="flat">';
print '<option value=""'.($search_status === '' ? ' selected' : '').'>—</option>';
foreach (array('ok', 'repeat', 'error') as $s) {
	print '<option value="'.$s.'"'.($search_status === $s ? ' selected' : '').'>'.$s.'</option>';
}
print '</select>';
print '</td>';

// invoice
print '<td class="liste_titre"></td>';

// message
print '<td class="liste_titre"></td>';

// actions
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Header row
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('EasyOcrWebhookLogReceivedAt'), $_SERVER['PHP_SELF'], 'datec', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($langs->trans('EasyOcrWebhookLogEvent'),      $_SERVER['PHP_SELF'], 'event', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('EasyOcrWebhookLogBatchId'),    $_SERVER['PHP_SELF'], 'batch_id', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('EasyOcrWebhookLogFilename'),   $_SERVER['PHP_SELF'], 'document_filename', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('EasyOcrWebhookLogProcStatus'), $_SERVER['PHP_SELF'], 'processing_status', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($langs->trans('EasyOcrWebhookLogInvoice'),    $_SERVER['PHP_SELF'], 'invoice_ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('EasyOcrWebhookLogProcMessage'), $_SERVER['PHP_SELF'], 'processing_message', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

// Rows
$i = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) break;

	print '<tr class="oddeven">';

	// datec
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->datec), 'dayhour').'</td>';

	// event
	print '<td class="tdoverflowmax200"><code class="small">'.dol_escape_htmltag($obj->event).'</code></td>';

	// batch_id (truncated for visibility)
	print '<td class="tdoverflowmax150" title="'.dol_escape_htmltag($obj->batch_id).'"><code class="small">'.dol_escape_htmltag(easyocr_truncate_id($obj->batch_id, 12)).'</code></td>';

	// filename
	print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($obj->document_filename).'">'.dol_escape_htmltag($obj->document_filename).'</td>';

	// processing_status
	print '<td class="center nowraponall">'.easyocr_format_status_badge($obj->processing_status).'</td>';

	// invoice
	print '<td class="tdoverflowmax150">';
	if ($obj->invoice_id > 0) {
		print '<a href="'.DOL_URL_ROOT.'/fourn/facture/card.php?facid='.((int) $obj->invoice_id).'">'.dol_escape_htmltag($obj->invoice_ref ? $obj->invoice_ref : '#'.$obj->invoice_id).'</a>';
	} else {
		print '<span class="opacitymedium">—</span>';
	}
	print '</td>';

	// processing_message
	print '<td class="tdoverflowmax300" title="'.dol_escape_htmltag($obj->processing_message).'">'.dol_escape_htmltag($obj->processing_message).'</td>';

	// actions
	print '<td class="nowrap center">';
	print '<a class="marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=view&id='.((int) $obj->rowid).'" title="'.$langs->trans('View').'">'.img_picto($langs->trans('View'), 'eye').'</a>';
	if ($permissiontodelete) {
		print '<a class="marginleftonly marginrightonly" href="'.$_SERVER['PHP_SELF'].'?action=delete&id='.((int) $obj->rowid).'&token='.newToken().'" title="'.$langs->trans('Delete').'">'.img_picto($langs->trans('Delete'), 'delete').'</a>';
	}
	print '</td>';

	print '</tr>';
	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

$db->free($resql);

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
