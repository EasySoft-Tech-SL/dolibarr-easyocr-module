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
 * \file       scan-expense.php
 * \ingroup    easyocr
 * \brief      Mobile-first / PWA view for an employee to scan an expense receipt.
 *
 * AI-only feature: without an active plan with credits, the scanner is locked.
 * Flow: photo (camera) -> AI OCR (reuses action=aiOcr) -> confirm -> create the
 * target object (expense report or supplier invoice) via action=newExpenseAI.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/lib/easyocr.lib.php';
require_once __DIR__ . '/lib/easyocr_ai.class.php';
require_once __DIR__ . '/lib/easyocr_autoload.php';

// Security check — reuse the write permission
if (!easyocrCheckRight($user, 'easyocr', 'write')) {
	accessforbidden();
}

$langs->loadLangs(array('easyocr@easyocr', 'trips'));

// ─── AI-only gate: resolve operational truth from the panel (/me) ─────────
$aiService = new EasyOcrAI($db);
$aiEnabled = $aiService->isEnabled();
$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';

$canScan = false;
$blockMessage = '';
if (!$aiEnabled || empty($apiKey)) {
	$blockMessage = $langs->trans('EasyOcrAINotConfigured');
} else {
	try {
		$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, array('base_url' => $apiUrl));
		$me = $client->account()->me();
		$sd = isset($me['data']) ? $me['data'] : array();
		$opStatus = isset($sd['status']) ? $sd['status'] : array();
		$quota = isset($sd['quota']) ? $sd['quota'] : array();
		$pagesRemaining = isset($quota['pages_remaining']) ? $quota['pages_remaining'] : 0;
		$canProcess = isset($opStatus['can_process']) ? (bool) $opStatus['can_process'] : ($pagesRemaining > 0);
		$canScan = $canProcess;
		if (!$canProcess) {
			$blockMessage = !empty($opStatus['block_message']) ? $opStatus['block_message'] : $langs->transnoentities('EasyOcrBlockGeneric');
		}
	} catch (\Exception $e) {
		// Fail-open on transient error — the backend re-gates on submit (defense in depth)
		$canScan = true;
		dol_syslog('EasyOCR scan-expense: /me check failed, fail-open: ' . $e->getMessage(), LOG_WARNING);
	}
}

// ─── Config for the client ───────────────────────────────────────────────
$expenseTarget = !empty($conf->global->EASYOCR_EXPENSE_TARGET) ? $conf->global->EASYOCR_EXPENSE_TARGET : 'expensereport';
$allowValidate = !empty($conf->global->EASYOCR_EXPENSE_ALLOW_VALIDATE);
$projetEnabled = !empty($conf->projet->enabled);
$isExpenseReport = ($expenseTarget == 'expensereport' && !empty($conf->expensereport->enabled));

// Expense category options (only relevant for the expense-report target)
$catOptions = '';
if ($isExpenseReport) {
	$sqlc = "SELECT id, code, label FROM " . MAIN_DB_PREFIX . "c_type_fees WHERE active = 1 ORDER BY label";
	$resc = $db->query($sqlc);
	if ($resc) {
		while ($o = $db->fetch_object($resc)) {
			$lbl = $langs->trans($o->code);
			if ($lbl == $o->code) $lbl = $langs->trans($o->label); // EX_* store a lang key in 'label'
			if ($lbl == $o->label && $o->label === '') $lbl = $o->code;
			$catOptions .= '<option value="' . ((int) $o->id) . '">' . dol_escape_htmltag($lbl) . '</option>';
		}
	}
}

// Project selector (native, searchable) — only if the Projects module is on
$projectSelectHtml = '';
if ($projetEnabled) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/html.formprojet.class.php';
	$formproject = new FormProjets($db);
	// nooutput=1 (12th arg) to get the HTML string; show empty; discard closed
	$projectSelectHtml = $formproject->select_projects(-1, '', 'project_id', 16, 0, 1, 1, 0, 0, 0, '', 1);
}

// ─── Page output ──────────────────────────────────────────────────────────
// App-like shell for mobile/PWA: hide Dolibarr top & left menus so the scanner
// fills the screen like a native app (honored by llxHeader via $conf flags).
$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

$form = new Form($db);

// PWA head: manifest + iOS/Android metas (injected via llxHeader's first arg)
$manifestUrl = dol_buildpath('/easyocr/manifest.json.php', 1);
$iconUrl = dol_buildpath('/easyocr/img/easyocr.png', 1);
$moreHead  = '<link rel="manifest" href="' . $manifestUrl . '">' . "\n";
$moreHead .= '<meta name="theme-color" content="#0f7b5a">' . "\n";
$moreHead .= '<meta name="mobile-web-app-capable" content="yes">' . "\n";
$moreHead .= '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
$moreHead .= '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
$moreHead .= '<meta name="apple-mobile-web-app-title" content="' . dol_escape_htmltag($langs->trans('EasyOcrExpenseScanShort')) . '">' . "\n";
$moreHead .= '<link rel="apple-touch-icon" href="' . $iconUrl . '">' . "\n";

// Pass RELATIVE paths — llxHeader resolves them once (avoids double prefix on subfolder installs)
$arrayofjs = array('/easyocr/js/scan-expense.js');
$arrayofcss = array('/easyocr/css/easyocr.css', '/easyocr/css/scan-expense.css');

llxHeader($moreHead, $langs->trans('EasyOcrExpenseScanTitle'), '', '', 0, 0, $arrayofjs, $arrayofcss);

print '<div class="eo-scan-wrap">';

if (!$canScan) {
	// ──── LOCKED STATE (AI-only) ────
	print '<div class="eo-scan-locked">';
	print '<div class="eo-scan-locked-icon">' . img_picto('', 'warning', 'class="pictofixedwidth"') . '</div>';
	print '<h2>' . $langs->trans('EasyOcrExpenseLockedTitle') . '</h2>';
	print '<p>' . dol_escape_htmltag($blockMessage) . '</p>';
	if (!empty($user->admin)) {
		print '<a class="eo-btn eo-btn-block" href="' . dol_buildpath('/easyocr/admin/plan.php', 1) . '">' . $langs->trans('EasyOcrViewFullPlan') . '</a>';
		print '<a class="eo-btn eo-btn-ghost eo-btn-block" href="' . dol_buildpath('/easyocr/admin/setup.php', 1) . '">' . $langs->trans('EasyOcrSetup') . '</a>';
	} else {
		print '<p class="opacitymedium">' . $langs->trans('EasyOcrExpenseContactAdmin') . '</p>';
	}
	print '</div>';
} else {
	// ──── SCANNER ────

	// Screen 1: capture — scanner-style layout (framing target + actions)
	print '<div class="eo-scan-screen eo-cap" id="eo-scan-capture">';
	print '<div class="eo-cap-head">';
	print '<h1 class="eo-cap-title">' . $langs->trans('EasyOcrExpenseScanTitle') . '</h1>';
	print '<p class="eo-cap-sub">' . $langs->trans('EasyOcrExpenseScanIntro') . '</p>';
	print '</div>';
	// Scanner framing target (visual)
	print '<div class="eo-cap-target">';
	print '<span class="eo-c eo-c1"></span><span class="eo-c eo-c2"></span><span class="eo-c eo-c3"></span><span class="eo-c eo-c4"></span>';
	print '<span class="eo-cap-target-ico fas fa-receipt"></span>';
	print '<span class="eo-cap-target-hint">' . $langs->trans('EasyOcrExpenseCamHint') . '</span>';
	print '</div>';
	print '<div class="eo-cap-actions">';
	print '<button type="button" class="eo-btn eo-btn-primary eo-btn-block eo-btn-lg" id="eo-scan-shoot">';
	print '<span class="fas fa-camera"></span> ' . $langs->trans('EasyOcrExpenseScanButton');
	print '</button>';
	print '<input type="file" accept="image/*" capture="environment" id="eo-scan-file" style="display:none">';
	// Gallery picker (no capture attribute → opens gallery/files instead of the camera)
	print '<button type="button" class="eo-btn eo-btn-ghost eo-btn-block eo-scan-gallery-btn" id="eo-scan-gallery-btn">';
	print '<span class="fas fa-images"></span> ' . $langs->trans('EasyOcrExpenseGallery');
	print '</button>';
	print '<input type="file" accept="image/*" id="eo-scan-gallery" style="display:none">';
	// PWA install button — shown by JS only when the browser fires beforeinstallprompt
	print '<button type="button" class="eo-btn eo-btn-ghost eo-btn-block eo-scan-install-btn" id="eo-scan-install" style="display:none">';
	print '<span class="fas fa-download"></span> ' . $langs->trans('EasyOcrExpenseInstallApp');
	print '</button>';
	print '</div>';
	print '</div>';

	// In-app camera with framing guide (getUserMedia). Falls back to the file input.
	print '<div class="eo-cam" id="eo-scan-cam" style="display:none">';
	print '<video id="eo-cam-video" playsinline autoplay muted></video>';
	print '<div class="eo-cam-mask"><div class="eo-cam-frame"></div></div>';
	print '<div class="eo-cam-hint">' . $langs->trans('EasyOcrExpenseCamHint') . '</div>';
	print '<div class="eo-cam-bar">';
	print '<button type="button" class="eo-cam-cancel" id="eo-cam-cancel" aria-label="' . dol_escape_htmltag($langs->trans('EasyOcrExpenseRetake')) . '"><span class="fas fa-times"></span></button>';
	print '<button type="button" class="eo-cam-shutter" id="eo-cam-shoot" aria-label="' . dol_escape_htmltag($langs->trans('EasyOcrExpenseScanButton')) . '"></button>';
	print '<button type="button" class="eo-cam-cancel" id="eo-cam-gallery" aria-label="' . dol_escape_htmltag($langs->trans('EasyOcrExpenseGallery')) . '"><span class="fas fa-images"></span></button>';
	print '</div>';
	print '<canvas id="eo-cam-canvas" style="display:none"></canvas>';
	print '</div>';

	// Loading overlay
	print '<div class="eo-scan-loading" id="eo-scan-loading" style="display:none">';
	print '<div class="eo-scan-spinner"></div>';
	print '<p id="eo-scan-loading-text">' . $langs->trans('EasyOcrExpenseAnalyzing') . '</p>';
	print '</div>';

	// Screen 2: confirmation (hidden until OCR completes)
	print '<form class="eo-scan-screen" id="eo-scan-confirm" style="display:none" onsubmit="return false;">';
	print '<img id="eo-scan-preview" class="eo-scan-preview" alt="">';
	print '<h2>' . $langs->trans('EasyOcrExpenseConfirmTitle') . '</h2>';

	print '<label class="eo-field"><span>' . $langs->trans('EasyOcrExpenseMerchant') . '</span>';
	print '<input type="text" id="eo-exp-merchant" autocomplete="off"></label>';

	print '<div class="eo-field-row">';
	print '<label class="eo-field"><span>' . $langs->trans('EasyOcrExpenseTotalWithVat') . '</span>';
	print '<input type="text" inputmode="decimal" id="eo-exp-total"></label>';
	print '<label class="eo-field eo-field-sm"><span>' . $langs->trans('EasyOcrExpenseVatRate') . ' %</span>';
	print '<input type="text" inputmode="decimal" id="eo-exp-vat"></label>';
	print '</div>';

	// Read-only breakdown, recomputed live from total + VAT rate
	print '<div class="eo-perf"></div>';
	print '<div class="eo-breakdown" id="eo-exp-breakdown">';
	print '<div><span>' . $langs->trans('EasyOcrExpenseBase') . '</span><strong id="eo-exp-base">—</strong></div>';
	print '<div><span>' . $langs->trans('EasyOcrExpenseVatAmount') . '</span><strong id="eo-exp-ivaamt">—</strong></div>';
	print '<div class="eo-breakdown-total"><span>' . $langs->trans('EasyOcrExpenseTotal') . '</span><strong id="eo-exp-totalamt">—</strong></div>';
	print '</div>';

	print '<label class="eo-field"><span>' . $langs->trans('EasyOcrExpenseDate') . '</span>';
	print '<input type="date" id="eo-exp-date"></label>';

	if ($isExpenseReport && $catOptions !== '') {
		print '<label class="eo-field"><span>' . $langs->trans('EasyOcrExpenseCategory') . '</span>';
		print '<select id="eo-exp-category"><option value="0">' . $langs->trans('EasyOcrExpenseCategoryNone') . '</option>' . $catOptions . '</select></label>';
	}

	if ($projetEnabled && $projectSelectHtml !== '') {
		print '<label class="eo-field"><span>' . $langs->trans('EasyOcrExpenseProject') . '</span></label>';
		print '<div class="eo-field eo-field-select">' . $projectSelectHtml . '</div>';
	}

	if ($allowValidate) {
		print '<label class="eo-check"><input type="checkbox" id="eo-exp-validate"> <span>' . $langs->trans('EasyOcrExpenseValidateNow') . '</span></label>';
	}

	// Detected line items (read-only, informational — the expense is a single line)
	print '<div class="eo-lines" id="eo-exp-lines-wrap" style="display:none">';
	print '<div class="eo-lines-title">' . $langs->trans('EasyOcrExpenseLines') . ' (<span id="eo-exp-lines-count">0</span>)</div>';
	print '<ul id="eo-exp-lines"></ul>';
	print '</div>';

	print '<div class="eo-scan-actions">';
	print '<button type="button" class="eo-btn eo-btn-ghost" id="eo-scan-retake">' . $langs->trans('EasyOcrExpenseRetake') . '</button>';
	print '<button type="submit" class="eo-btn eo-btn-primary" id="eo-scan-submit">' . $langs->trans('EasyOcrExpenseSubmit') . '</button>';
	print '</div>';
	print '</form>';

	// Success screen
	print '<div class="eo-scan-screen eo-scan-success" id="eo-scan-success" style="display:none">';
	print '<div class="eo-scan-success-icon"><span class="fas fa-check-circle"></span></div>';
	print '<h2 id="eo-scan-success-title">' . $langs->trans('EasyOcrExpenseSentTitle') . '</h2>';
	print '<p id="eo-scan-success-ref" class="opacitymedium"></p>';
	print '<button type="button" class="eo-btn eo-btn-primary eo-btn-block" id="eo-scan-again">' . $langs->trans('EasyOcrExpenseScanAnother') . '</button>';
	print '</div>';

	// Full-screen image zoom overlay (tap the preview to open, tap to close)
	print '<div class="eo-zoom" id="eo-scan-zoom" style="display:none"><img id="eo-scan-zoom-img" alt=""></div>';

	// JS config
	$cfg = array(
		'ajaxUrl'       => dol_buildpath('/easyocr/ajax/ajax_easyocr.php', 1),
		'token'         => newToken(),
		'target'        => $expenseTarget,
		'isExpense'     => $isExpenseReport,
		'allowValidate' => $allowValidate,
		// transnoentities: these go into JS (textContent), so NO HTML entities
		// (trans() would turn "…" into &hellip; and show it literally).
		'i18n'          => array(
			'analyzing'  => $langs->transnoentities('EasyOcrExpenseAnalyzing'),
			'sending'    => $langs->transnoentities('EasyOcrExpenseSending'),
			'errorOcr'   => $langs->transnoentities('EasyOcrExpenseErrorOcr'),
			'errorSave'  => $langs->transnoentities('EasyOcrExpenseErrorSave'),
			'noPhoto'    => $langs->transnoentities('EasyOcrExpensePhotoRequired'),
			'draftOk'    => $langs->transnoentities('EasyOcrExpenseSentDraft'),
			'validatedOk' => $langs->transnoentities('EasyOcrExpenseSentValidated'),
		),
	);
	print '<script type="text/javascript">window.EO_SCAN_CFG = ' . json_encode($cfg) . ';</script>';
}

print '</div>'; // .eo-scan-wrap

llxFooter();
$db->close();
