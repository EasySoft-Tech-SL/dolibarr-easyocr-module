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
 * \file       batch.php
 * \ingroup    easyocr
 * \brief      Batch processing page — send multiple files to the EasyOCR API
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
require_once __DIR__ . '/lib/easyocr.lib.php';
require_once __DIR__ . '/lib/easyocr_ai.class.php';
require_once __DIR__ . '/lib/easyocr_autoload.php';

// Security check
if (!easyocrCheckRight($user, 'easyocr', 'write')) {
  accessforbidden();
}

$langs->load('easyocr@easyocr');

// ─── Check AI configuration & subscription ───────────────────────────────
$aiService = new EasyOcrAI($db);
$aiEnabled = $aiService->isEnabled();
$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';

$subscriptionData = null;
$subscriptionError = '';
$canBatch = false;
$pagesRemaining = 0;
$walletBalance = 0;
$plan = array();
$quota = array();
$wallet = array();
$features = array();
$limits = array();

if (!$aiEnabled) {
  $subscriptionError = $langs->trans('EasyOcrAINotConfigured');
} elseif (empty($apiKey)) {
  $subscriptionError = $langs->trans('EasyOcrBatchNoApiKey');
} else {
  try {
    $client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
    $accountData = $client->account()->me();
    $subscriptionData = $accountData['data'] ?? null;
    /* var_dump($subscriptionData); */

    if (!empty($subscriptionData)) {
      $plan = $subscriptionData['plan'] ?? array();
      $quota = $subscriptionData['quota'] ?? array();
      $wallet = $subscriptionData['wallet'] ?? array();
      $features = $subscriptionData['features'] ?? array();
      $limits = $subscriptionData['limits'] ?? array();

      $pagesRemaining = $quota['pages_remaining'] ?? 0;
      $pagesRemainingPlan = $quota['pages_remaining_from_plan'] ?? 0;
      $pagesRemainingWallet = $quota['pages_remaining_from_wallet'] ?? 0;
      $walletBalance = $wallet['balance_pages'] ?? 0;
      $batchEnabled = !empty($features['batch_processing']);
      $maxBatchFiles = $limits['max_batch_size'] ?? 0;
      $maxFileSizeMb = $limits['max_file_size_mb'] ?? 10;

      // Can batch if: feature enabled AND pages remaining (combined plan + wallet)
      $canBatch = $batchEnabled && $pagesRemaining > 0;

      if (!$batchEnabled) {
        $subscriptionError = $langs->trans('EasyOcrBatchNotAvailable');
      } elseif ($pagesRemaining <= 0) {
        $subscriptionError = $langs->trans('EasyOcrBatchNoQuota');
      }
    }
  } catch (\Exception $e) {
    $subscriptionError = $langs->trans('EasyOcrBatchApiError') . ': ' . $e->getMessage();
  }
}

// Batch submission is now handled entirely via AJAX (batchUploadFile + batchCreateFromUploads)
// to bypass PHP max_file_uploads limit. See ajax_easyocr.php actions.
$action = GETPOST('action', 'aZ09');

// ─── Page output ──────────────────────────────────────────────────────────
$form = new Form($db);

$arrayofjs = array(
  dol_buildpath('/easyocr/js/scripts.js.php', 1),
);
$arrayofcss = array(
  dol_buildpath('/easyocr/css/easyocr.css', 1),
);

// Batch submission is now 100% AJAX — no server-side POST messages needed

llxHeader('', $langs->trans('EasyOcrBatchTitle'), '', '', 0, 0, $arrayofjs, $arrayofcss);

// ─── Subscription summary & usage ────────────────────────────────────────
$usagePercent = 0;
$pagesUsed = isset($quota['pages_used']) ? $quota['pages_used'] : 0;
$pagesLimit = isset($quota['pages_limit']) ? $quota['pages_limit'] : 0;
if ($pagesLimit > 0) {
  $usagePercent = min(round(($pagesUsed / $pagesLimit) * 100, 1), 100);
}
$planName = isset($plan['name']) ? $plan['name'] : 'N/A';
$isFree = !empty($plan['is_free']);
$hasAutoCorrect = !empty($features['auto_correct']);
$hasIncludeText = !empty($features['include_text']);
$hasWebhooks = !empty($features['webhooks']);
$hasBatch = !empty($features['batch_processing']);
$hasCustomInstructions = !empty($features['custom_instructions']);
$eoBatchTemplates = array(); // filled later for the 'new' tab

// ─── Title bar (native Dolibarr style) ───────────────────────────────────
$helpurl = '';
$title = $langs->trans('EasyOcrBatchTitle');
$subtitle = $langs->trans('EasyOcrBatchSubtitle');

// ─── Page header (consistent EasyOcr style) ─────────────────────────────
print '<div class="eo-page-header">';
print '  <div class="eo-page-header-icon eo-page-header-icon--batch"><i class="fas fa-layer-group"></i></div>';
print '  <div class="eo-page-header-text">';
print '    <h1>' . dol_escape_htmltag($title) . '</h1>';
print '    <p>' . dol_escape_htmltag($subtitle) . '</p>';
print '  </div>';
print '</div>';

// ─── Tabs ────────────────────────────────────────────────────────────────
$activeTab = GETPOST('tab', 'aZ09');
$fromMenu = GETPOST('frommenu', 'int');
if (empty($activeTab)) $activeTab = 'new';

// Map history to list (same tab)
if ($activeTab === 'history') $activeTab = 'list';

$head = array();
$h = 0;
$head[$h][0] = $_SERVER['PHP_SELF'] . '?tab=new';
$head[$h][1] = img_picto('', 'object_calendarday', 'class="pictofixedwidth"') . $langs->trans('EasyOcrBatchTabNew');
$head[$h][2] = 'new';
$h++;
$head[$h][0] = $_SERVER['PHP_SELF'] . '?tab=list';
$head[$h][1] = img_picto('', 'list', 'class="pictofixedwidth"') . $langs->trans('EasyOcrBatchTabList');
$head[$h][2] = 'list';
$h++;

// Only show tabs if not coming from menu
if (!$fromMenu) {
  dol_fiche_head($head, $activeTab, '', -1, '');
}

if (!$canBatch) {
  // ──── LOCKED STATE ────
  print '<div class="warning">';
  print img_picto('', 'warning', 'class="pictofixedwidth"');
  print '<strong>' . $langs->trans('EasyOcrBatchLocked') . '</strong><br>';
  print dol_escape_htmltag($subscriptionError);
  print '<br><br>';
  print '<a class="butAction" href="' . dol_buildpath('/easyocr/admin/plan.php', 1) . '">' . $langs->trans('EasyOcrViewFullPlan') . '</a> ';
  print '<a class="butAction" href="' . dol_buildpath('/easyocr/admin/setup.php', 1) . '">' . $langs->trans('EasyOcrSetup') . '</a>';
  print '</div>';
} else {
  // ──── MAIN CONTENT ────

  if ($activeTab == 'new') {

    // Load templates that have custom_instructions (supplier selector)
    if ($hasCustomInstructions) {
      $sqlTpl = "SELECT t.rowid, t.name, t.fk_soc, t.custom_instructions, s.nom AS supplier_name";
      $sqlTpl .= " FROM " . MAIN_DB_PREFIX . "easyocr_template t";
      $sqlTpl .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON t.fk_soc = s.rowid";
      $sqlTpl .= " WHERE t.custom_instructions IS NOT NULL AND t.custom_instructions <> ''";
      $sqlTpl .= " ORDER BY s.nom ASC, t.name ASC";
      $resTpl = $db->query($sqlTpl);
      if ($resTpl) {
        while ($objTpl = $db->fetch_object($resTpl)) {
          $eoBatchTemplates[] = array(
            'rowid' => (int)$objTpl->rowid,
            'name' => $objTpl->name,
            'fk_soc' => $objTpl->fk_soc,
            'supplier_name' => $objTpl->supplier_name,
            'custom_instructions' => $objTpl->custom_instructions,
          );
        }
      }
    }

    // ── Quota summary cards across top ──
    print '<div class="eo-batch-quota-cards">';

    // Card: Pages remaining (combined)
    print '<div class="eo-batch-qcard eo-batch-qcard-main">';
    print '<div class="eo-batch-qcard-value">' . number_format($pagesRemaining, 0, ',', '.') . '</div>';
    print '<div class="eo-batch-qcard-label">' . $langs->trans('EasyOcrQuotaRemaining') . '</div>';
    print '</div>';

    // Card: From plan
    print '<div class="eo-batch-qcard">';
    print '<div class="eo-batch-qcard-value">' . number_format($pagesRemainingPlan, 0, ',', '.') . '</div>';
    print '<div class="eo-batch-qcard-label">' . $langs->trans('EasyOcrBatchFromPlan') . '</div>';
    print '<div class="eo-batch-qcard-sub">';
    $barClass = 'ok';
    if ($usagePercent >= 100) $barClass = 'danger';
    elseif ($usagePercent >= 80) $barClass = 'warning';
    print '<div class="eo-batch-usage-bar"><div class="eo-batch-usage-fill eo-status-' . $barClass . '" style="width:' . min($usagePercent, 100) . '%"></div></div>';
    print '<span class="opacitymedium" style="font-size:10px">' . $pagesUsed . ' / ' . $pagesLimit . '</span>';
    print '</div>';
    print '</div>';

    // Card: Wallet
    print '<div class="eo-batch-qcard' . ($pagesRemainingWallet > 0 ? ' eo-batch-qcard-wallet' : '') . '">';
    print '<div class="eo-batch-qcard-value">' . number_format($pagesRemainingWallet, 0, ',', '.') . '</div>';
    print '<div class="eo-batch-qcard-label">' . $langs->trans('EasyOcrWalletBalance') . '</div>';
    print '</div>';

    // Card: Plan name
    print '<div class="eo-batch-qcard">';
    print '<div class="eo-batch-qcard-value" style="font-size:15px">' . dol_escape_htmltag($planName);
    if ($isFree) print ' <span class="badge badge-status4 badge-status">FREE</span>';
    print '</div>';
    print '<div class="eo-batch-qcard-label">' . $langs->trans('EasyOcrPlan') . '</div>';
    print '</div>';

    print '</div>'; // eo-batch-quota-cards

    print '<br>';

    // ── Two-column layout using Dolibarr fichecenter ──
    print '<div class="fichecenter">';
    print '<div class="fichethirdleft">';

    // ── Upload form (AJAX-based, no traditional POST) ──
    print '<form id="eo-batch-form" onsubmit="return false;">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';

    // Batch name
    print '<div class="div-table-responsive-no-min">';
    print '<table class="border centpercent tableforfield">';
    print '<tr>';
    print '<td class="titlefield">' . $langs->trans('EasyOcrBatchName') . ' <span class="opacitymedium">(' . strtolower($langs->trans('Optional')) . ')</span></td>';
    print '<td><input type="text" id="batch_name" name="batch_name" class="flat minwidth300"';
    print ' placeholder="' . dol_escape_htmltag($langs->trans('EasyOcrBatchNamePlaceholder')) . '"';
    print ' value="' . dol_escape_htmltag(GETPOST('batch_name', 'alphanohtml')) . '"></td>';
    print '</tr>';
    print '</table>';
    print '</div>';

    print '<br>';

    // File drop zone
    print '<div class="div-table-responsive-no-min">';
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">' . $langs->trans('Files') . '</td>';
    print '<td>';

    print '<div class="eo-batch-dropzone" id="eo-batch-dropzone">';
    print '<input type="file" name="batch_files[]" id="eo-batch-files" multiple';
    print ' accept=".pdf,.png,.jpg,.jpeg,.tiff,.tif,.bmp,.webp" style="display:none">';
    print '<div class="eo-batch-dropzone-content" id="eo-batch-dropzone-content">';
    print '<div class="eo-batch-dropzone-icon">';
    print '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#8899aa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
    print '</div>';
    print '<p class="eo-batch-dropzone-text">';
    print $langs->trans('EasyOcrBatchDropFiles') . ' ';
    print '<a href="#" onclick="document.getElementById(\'eo-batch-files\').click(); return false;" class="eo-batch-dropzone-link">' . strtolower($langs->trans('EasyOcrBatchClickSelect')) . '</a>';
    print '</p>';
    print '<p class="eo-batch-dropzone-hint">PDF, PNG, JPG, TIFF, BMP, WEBP — ' . $langs->trans('EasyOcrBatchMaxSize', $maxFileSizeMb . 'MB');
    if ($maxBatchFiles > 0) {
      print ' · ' . $langs->transnoentities('EasyOcrBatchMaxFilesHint', $maxBatchFiles);
    }
    print '</p>';
    print '</div>'; // dropzone-content
    print '</div>'; // dropzone

    // File list (populated by JS)
    print '<div class="eo-batch-file-list" id="eo-batch-file-list" style="display:none">';
    print '<div class="eo-batch-file-list-header">';
    print '<span id="eo-batch-file-count"></span>';
    print '<a href="#" id="eo-batch-clear-all" onclick="eoBatchClearFiles(); return false;">' . $langs->trans('EasyOcrBatchClearAll') . '</a>';
    print '</div>';
    print '<div id="eo-batch-file-items"></div>';
    print '</div>';

    print '</td></tr>';
    print '</table>';
    print '</div>';

    print '<br>';

    // Submit button
    print '<div class="center">';
    print '<button type="submit" class="button" id="eo-batch-submit" disabled>';
    print img_picto('', 'object_calendarday', 'class="pictofixedwidth"');
    print '<span id="eo-batch-submit-text">' . $langs->trans('EasyOcrBatchProcess') . '</span>';
    print '</button>';
    print '</div>';

    print '</form>';

    print '</div>'; // fichethirdleft

    // ── RIGHT: Configuration sidebar ──
    print '<div class="fichetwothirdright">';

    // Configuration box
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">';
    print '<tr class="liste_titre"><td colspan="2">' . img_picto('', 'setup', 'class="pictofixedwidth"') . $langs->trans('EasyOcrBatchConfig') . '</td></tr>';

    // Include extracted text
    $disabledText = !$hasIncludeText ? ' disabled' : '';
    $lockedLabel = !$hasIncludeText ? ' ' . img_picto($langs->trans('EasyOcrBatchUpgradePlan'), 'warning') : '';
    print '<tr>';
    print '<td class="titlefield">' . $langs->trans('EasyOcrBatchIncludeText') . $lockedLabel . '</td>';
    print '<td>';
    print '<input type="checkbox" name="include_extracted_text" value="1" form="eo-batch-form"' . $disabledText . '> ';
    print '<span class="opacitymedium small">' . $langs->trans('EasyOcrBatchIncludeTextDesc') . '</span>';
    print '</td></tr>';

    // Auto-correction
    $disabledAuto = !$hasAutoCorrect ? ' disabled' : '';
    $lockedAuto = !$hasAutoCorrect ? ' ' . img_picto($langs->trans('EasyOcrBatchUpgradePlan'), 'warning') : '';
    print '<tr>';
    print '<td>' . $langs->trans('EasyOcrBatchAutoCorrect') . $lockedAuto . '</td>';
    print '<td>';
    print '<input type="checkbox" name="auto_correct" value="1" form="eo-batch-form"' . $disabledAuto . '> ';
    print '<span class="opacitymedium small">' . $langs->trans('EasyOcrBatchAutoCorrectDesc') . '</span>';
    print '</td></tr>';

    // Webhook URL — always auto-fill with our receiver endpoint
    $disabledWebhook = !$hasWebhooks ? ' disabled' : '';
    $lockedWebhook = !$hasWebhooks ? ' ' . img_picto($langs->trans('EasyOcrBatchUpgradePlan'), 'warning') : '';
    $webhookDefault = GETPOST('webhook_url', 'alpha');
    if (empty($webhookDefault)) {
      global $dolibarr_main_instance_unique_id;
      $instanceId = !empty($dolibarr_main_instance_unique_id) ? $dolibarr_main_instance_unique_id : '';
      $webhookDefault = dol_buildpath('/easyocr/webhook_batch.php', 2);
      // Always force HTTPS for webhook URLs (external callbacks must be secure)
      $webhookDefault = preg_replace('/^http:\/\//i', 'https://', $webhookDefault);
      if (!empty($instanceId)) {
        $webhookDefault .= '?instance_id=' . urlencode($instanceId);
      }
    }
    print '<tr>';
    print '<td>Webhook URL' . $lockedWebhook . '</td>';
    print '<td>';
    print '<input type="url" id="webhook_url" name="webhook_url" class="flat minwidth300" form="eo-batch-form"';
    print $disabledWebhook;
    print ' value="' . dol_escape_htmltag($webhookDefault) . '">';
    if ($hasWebhooks) {
      print '<br><span class="opacitymedium small">' . img_picto('', 'info', 'class="pictofixedwidth"') . $langs->trans('EasyOcrBatchWebhookAutoHint') . '</span>';
    }
    print '</td></tr>';

    $disabledAI = !$hasCustomInstructions ? ' disabled' : '';
    $lockedAI   = !$hasCustomInstructions ? ' ' . img_picto($langs->trans('EasyOcrBatchUpgradePlan'), 'warning') : '';

    // Template / supplier selector (auto-fills custom_instructions)
    if (!empty($eoBatchTemplates)) {
      print '<tr>';
      print '<td>' . $langs->trans('EasyOcrBatchTemplateSelect') . '</td>';
      print '<td>';
      print '<select id="batch_template_select" class="flat minwidth250">';
      print '<option value="">' . dol_escape_htmltag($langs->trans('EasyOcrBatchTemplateSelectHint')) . '</option>';
      foreach ($eoBatchTemplates as $tpl) {
        $tlabel = (!empty($tpl['supplier_name']) ? dol_escape_htmltag($tpl['supplier_name']) . ' — ' : '') . dol_escape_htmltag($tpl['name']);
        print '<option value="' . (int)$tpl['rowid'] . '">' . $tlabel . '</option>';
      }
      print '</select>';
      print '</td></tr>';
    }

    // Language
    print '<tr>';
    print '<td>' . $langs->trans('EasyOcrBatchLanguage') . $lockedAI . '</td>';
    print '<td>';
    print '<input type="text" id="batch_language" name="batch_language" class="flat" style="width:100px"' . $disabledAI . ' placeholder="' . dol_escape_htmltag($langs->trans('EasyOcrBatchLanguagePlaceholder')) . '" form="eo-batch-form" value="' . dol_escape_htmltag(GETPOST('batch_language', 'atohtml')) . '">';
    print '<br><span class="opacitymedium small">' . img_picto('', 'info', 'class="pictofixedwidth"') . $langs->trans('EasyOcrBatchLanguageHint') . '</span>';
    print '</td></tr>';

    // Custom instructions
    print '<tr>';
    print '<td>' . $langs->trans('EasyOcrCustomInstructions') . $lockedAI . '</td>';
    print '<td>';
    print '<textarea name="batch_custom_instructions" id="batch_custom_instructions" class="flat minwidth300" rows="3"' . $disabledAI . ' form="eo-batch-form" placeholder="' . dol_escape_htmltag($langs->trans('EasyOcrCustomInstructionsPlaceholder')) . '">' . dol_escape_htmltag(GETPOST('batch_custom_instructions', 'restricthtml')) . '</textarea>';
    print '</td></tr>';

    print '</table>';

    print '<br>';

    // How it works box
    print '<table class="border centpercent tableforfield">';
    print '<tr class="liste_titre"><td colspan="2">' . img_picto('', 'info', 'class="pictofixedwidth"') . $langs->trans('EasyOcrBatchHowItWorks') . '</td></tr>';
    print '<tr><td colspan="2">';
    print '<ol class="eo-batch-steps">';
    print '<li>' . $langs->trans('EasyOcrBatchStep1') . '</li>';
    print '<li>' . $langs->trans('EasyOcrBatchStep2') . '</li>';
    print '<li>' . $langs->trans('EasyOcrBatchStep3') . '</li>';
    print '</ol>';
    print '</td></tr>';
    print '</table>';

    print '</div>'; // fichetwothirdright
    print '</div>'; // fichecenter

  } elseif ($activeTab == 'list') {
    // ──── BATCH HISTORY TAB ────

    // Filter bar
    print '<div class="div-table-responsive-no-min">';
    print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '" id="eo-batch-filter-form">';
    print '<input type="hidden" name="tab" value="list">';
    print '<table class="border centpercent tableforfield">';

    // Row 1: Status + Name
    print '<tr>';
    print '<td class="titlefield">' . $langs->trans('Status') . '</td>';
    print '<td>';
    print '<select name="batch_status" id="eo-batch-filter-status" class="flat minwidth150">';
    print '<option value="">' . $langs->trans('EasyOcrBatchAllStatuses') . '</option>';
    $statuses = array('pending', 'processing', 'completed', 'partial', 'failed', 'cancelled');
    $currentStatus = GETPOST('batch_status', 'aZ09');
    foreach ($statuses as $st) {
      $sel = ($currentStatus == $st) ? ' selected' : '';
      print '<option value="' . $st . '"' . $sel . '>' . $langs->trans('EasyOcrBatchStatus' . ucfirst($st)) . '</option>';
    }
    print '</select>';
    print ' &nbsp; ';
    print '<input type="text" name="batch_name" id="eo-batch-filter-name" class="flat minwidth200"';
    print ' placeholder="' . dol_escape_htmltag($langs->trans('EasyOcrBatchSearchName')) . '"';
    print ' value="' . dol_escape_htmltag(GETPOST('batch_name', 'alphanohtml')) . '">';
    print '</td>';
    print '</tr>';

    // Row 2: Date range + actions
    print '<tr>';
    print '<td>' . $langs->trans('Period') . '</td>';
    print '<td>';
    print '<input type="date" name="batch_from" id="eo-batch-filter-from" class="flat"';
    print ' value="' . dol_escape_htmltag(GETPOST('batch_from', 'alphanohtml')) . '"';
    print ' title="' . dol_escape_htmltag($langs->trans('From')) . '">';
    print ' &rarr; ';
    print '<input type="date" name="batch_to" id="eo-batch-filter-to" class="flat"';
    print ' value="' . dol_escape_htmltag(GETPOST('batch_to', 'alphanohtml')) . '"';
    print ' title="' . dol_escape_htmltag($langs->trans('To')) . '">';
    print ' &nbsp; ';
    print '<span class="opacitymedium" style="margin-left:12px; margin-right:4px; font-size:12px">' . $langs->trans('MaxNbOfRecordPerPage') . ':</span>';
    print '<select name="batch_per_page" id="eo-batch-filter-perpage" class="flat" style="width:60px">';
    $perPageOptions = array(10, 20, 50, 100);
    foreach ($perPageOptions as $pp) {
      $sel = ($pp == 20) ? ' selected' : '';
      print '<option value="' . $pp . '"' . $sel . '>' . $pp . '</option>';
    }
    print '</select>';
    print ' &nbsp; ';
    print '<button type="button" class="button small" onclick="eoBatchLoadList()">';
    print img_picto('', 'search_icon', 'class="pictofixedwidth"') . $langs->trans('Search');
    print '</button>';
    print ' <button type="button" class="button small" onclick="eoBatchClearFilters()">';
    print img_picto('', 'eraser', 'class="pictofixedwidth"') . $langs->trans('EasyOcrClear');
    print '</button>';
    print ' <span style="margin-left:12px; border-left:1px solid #ccc; padding-left:12px">';
    print '<button type="button" class="button small" id="eo-batch-trash-toggle" onclick="eoBatchToggleTrash()" title="' . dol_escape_htmltag($langs->trans('EasyOcrBatchTrash')) . '">';
    print '<span class="fas fa-trash-alt pictofixedwidth" style="color:#fff"></span>' . $langs->trans('EasyOcrBatchTrash');
    print ' <span id="eo-batch-trash-badge" class="badge badge-status badge-status9" style="display:none; margin-left:4px; font-size:9px">0</span>';
    print '</button>';
    print '</span>';
    print '</td>';
    print '</tr>';

    print '</table>';
    print '</form>';
    print '</div>';

    print '<br>';

    // Batch list container (loaded via AJAX)
    print '<div id="eo-batch-list-container">';
    print '<div class="opacitymedium center" style="padding:40px">';
    print '<span class="fas fa-spinner fa-spin" style="margin-right:6px"></span>' . $langs->trans('Loading') . '...';
    print '</div>';
    print '</div>';

    // Batch detail modal/panel (hidden, populated via AJAX)
    print '<div id="eo-batch-detail-overlay" class="eo-batch-detail-overlay" style="display:none">';
    print '<div class="eo-batch-detail-panel">';
    print '<div class="eo-batch-detail-header">';
    print '<span class="eo-batch-detail-title" id="eo-batch-detail-title"></span>';
    print '<button type="button" class="eo-batch-detail-close" onclick="eoBatchCloseDetail()">&times;</button>';
    print '</div>';
    print '<div class="eo-batch-detail-body" id="eo-batch-detail-body">';
    print '</div>';
    print '</div>';
    print '</div>';
  } // end tabs

} // end canBatch

// Only close fiche if not coming from menu (tabs were opened)
if (!$fromMenu) {
  dol_fiche_end();
}

?>

<script>
  var eoBatchTemplates = <?php echo json_encode($eoBatchTemplates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
  (function() {
    var dropzone = document.getElementById('eo-batch-dropzone');

    // Template selector → auto-fill custom_instructions
    var tplSel = document.getElementById('batch_template_select');
    if (tplSel) {
      tplSel.addEventListener('change', function() {
        var rowid = parseInt(this.value, 10);
        var ta = document.getElementById('batch_custom_instructions');
        if (!ta) return;
        if (!rowid) {
          ta.value = '';
          return;
        }
        var found = eoBatchTemplates.find(function(t) {
          return t.rowid === rowid;
        });
        ta.value = found ? (found.custom_instructions || '') : '';
      });
    }
    var fileInput = document.getElementById('eo-batch-files');
    var fileList = document.getElementById('eo-batch-file-list');
    var fileItems = document.getElementById('eo-batch-file-items');
    var fileCount = document.getElementById('eo-batch-file-count');
    var submitBtn = document.getElementById('eo-batch-submit');
    var submitText = document.getElementById('eo-batch-submit-text');
    var dropContent = document.getElementById('eo-batch-dropzone-content');

    if (!dropzone || !fileInput) return;

    var selectedFiles = [];
    var maxSize = <?php echo (int)$maxFileSizeMb; ?> * 1024 * 1024;
    var maxFiles = <?php echo $maxBatchFiles > 0 ? (int)$maxBatchFiles : 200; ?>;
    var allowedExts = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'bmp', 'webp'];

    function formatSize(bytes) {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
      return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function getFileIcon(name) {
      var ext = name.split('.').pop().toLowerCase();
      if (ext === 'pdf') return '<span class="eo-batch-file-icon eo-batch-file-pdf">PDF</span>';
      return '<span class="eo-batch-file-icon eo-batch-file-img">IMG</span>';
    }

    function updateUI() {
      if (selectedFiles.length === 0) {
        fileList.style.display = 'none';
        dropzone.style.display = '';
        submitBtn.disabled = true;
        submitText.textContent = '<?php echo addslashes($langs->trans('EasyOcrBatchProcess')); ?>';
        return;
      }

      dropzone.style.display = 'none';
      fileList.style.display = '';
      submitBtn.disabled = false;

      var totalSize = 0;
      selectedFiles.forEach(function(f) {
        totalSize += f.size;
      });

      fileCount.textContent = selectedFiles.length + ' <?php echo addslashes(strtolower($langs->trans('EasyOcrBatchFiles'))); ?> · ' + formatSize(totalSize);
      submitText.textContent = '<?php echo addslashes($langs->trans('EasyOcrBatchProcessN')); ?>'.replace('%s', selectedFiles.length);

      fileItems.innerHTML = '';
      selectedFiles.forEach(function(file, idx) {
        var row = document.createElement('div');
        row.className = 'eo-batch-file-row';
        row.innerHTML = getFileIcon(file.name) +
          '<div class="eo-batch-file-info"><span class="eo-batch-file-name">' + file.name + '</span><span class="eo-batch-file-size">' + formatSize(file.size) + '</span></div>' +
          '<button type="button" class="eo-batch-file-remove" onclick="eoBatchRemoveFile(' + idx + ')" title="<?php echo addslashes($langs->trans('Delete')); ?>">&times;</button>';
        fileItems.appendChild(row);
      });

      // Sync the actual file input with a DataTransfer
      syncFileInput();
    }

    function syncFileInput() {
      try {
        var dt = new DataTransfer();
        selectedFiles.forEach(function(f) {
          dt.items.add(f);
        });
        fileInput.files = dt.files;
      } catch (e) {
        // Fallback for older browsers — form will still work
      }
    }

    function addFiles(files) {
      for (var i = 0; i < files.length; i++) {
        var f = files[i];
        var ext = f.name.split('.').pop().toLowerCase();

        if (allowedExts.indexOf(ext) === -1) continue;
        if (f.size > maxSize) continue;
        if (selectedFiles.length >= maxFiles) break;

        // Avoid duplicates
        var dup = false;
        for (var j = 0; j < selectedFiles.length; j++) {
          if (selectedFiles[j].name === f.name && selectedFiles[j].size === f.size) {
            dup = true;
            break;
          }
        }
        if (!dup) selectedFiles.push(f);
      }
      updateUI();
    }

    // Click to open file picker
    dropzone.addEventListener('click', function(e) {
      if (e.target.tagName === 'A') return;
      fileInput.click();
    });

    fileInput.addEventListener('change', function() {
      addFiles(this.files);
    });

    // Drag & drop
    ['dragenter', 'dragover'].forEach(function(ev) {
      dropzone.addEventListener(ev, function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('eo-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function(ev) {
      dropzone.addEventListener(ev, function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('eo-dragover');
      });
    });
    dropzone.addEventListener('drop', function(e) {
      addFiles(e.dataTransfer.files);
    });

    // Global functions
    window.eoBatchRemoveFile = function(idx) {
      selectedFiles.splice(idx, 1);
      updateUI();
    };

    window.eoBatchClearFiles = function() {
      selectedFiles = [];
      updateUI();
    };

    // Form submission — AJAX chunked upload (bypasses PHP max_file_uploads)
    var form = document.getElementById('eo-batch-form');
    if (form) {
      // Simple inline notification helper (Dolibarr style)
      function eoBatchNotify(msg, type) {
        var cls = (type === 'error') ? 'error' : 'ok';
        var icon = (type === 'error') ? 'fa-exclamation-triangle' : 'fa-check-circle';
        var color = (type === 'error') ? '#bc0000' : '#28a745';
        var el = document.createElement('div');
        el.className = 'jnotify-container';
        el.innerHTML = '<div class="jnotify-notification jnotify-notification-' + cls + '" style="padding:12px 16px; margin:8px 0; border-radius:4px; background:' + (type === 'error' ? '#fff5f5' : '#f0fff4') + '; border:1px solid ' + color + ';">' +
          '<span class="fas ' + icon + '" style="margin-right:8px; color:' + color + '"></span>' + msg +
          '</div>';
        var target = document.getElementById('eo-batch-form');
        if (target) target.parentNode.insertBefore(el, target);
        setTimeout(function() {
          if (el.parentNode) el.parentNode.removeChild(el);
        }, 8000);
      }

      submitBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (selectedFiles.length === 0) return;

        submitBtn.disabled = true;
        submitBtn.classList.add('eo-loading');

        var ajaxUrl = '<?php echo dol_buildpath('/easyocr/ajax/ajax_easyocr.php', 1); ?>';
        var sessionId = 'batch_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
        var totalFiles = selectedFiles.length;
        var uploaded = 0;
        var hadError = false;

        function updateProgress(done, total, msg) {
          submitText.textContent = msg || ('<?php echo addslashes($langs->trans('EasyOcrBatchUploading')); ?> ' + done + '/' + total);
        }

        function uploadFile(index) {
          if (hadError) return;
          if (index >= totalFiles) {
            // All files uploaded — now create the batch
            createBatch(sessionId);
            return;
          }

          updateProgress(index + 1, totalFiles);

          var fd = new FormData();
          fd.append('action', 'batchUploadFile');
          fd.append('session_id', sessionId);
          fd.append('file', selectedFiles[index]);

          jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
              if (res.status === 'ok') {
                uploaded++;
                uploadFile(index + 1);
              } else {
                hadError = true;
                resetBtn();
                eoBatchNotify(res.message || '<?php echo addslashes($langs->trans('EasyOcrBatchUploadError', '')); ?>', 'error');
              }
            },
            error: function() {
              hadError = true;
              resetBtn();
              eoBatchNotify('<?php echo addslashes($langs->trans('EasyOcrBatchApiError')); ?>', 'error');
            }
          });
        }

        function createBatch(sid) {
          updateProgress(totalFiles, totalFiles, '<?php echo addslashes($langs->trans('EasyOcrBatchProcessing')); ?>');

          var createData = {
            action: 'batchCreateFromUploads',
            session_id: sid,
            batch_name: (document.getElementById('batch_name') || {}).value || '',
            include_extracted_text: (form.querySelector('[name=include_extracted_text]') || {}).checked ? '1' : '0',
            auto_correct: (form.querySelector('[name=auto_correct]') || {}).checked ? '1' : '0',
            webhook_url: (document.getElementById('webhook_url') || {}).value || '',
            language: (document.getElementById('batch_language') || {}).value || '',
            custom_instructions: (document.getElementById('batch_custom_instructions') || {}).value || ''
          };

          jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: createData,
            dataType: 'json',
            success: function(res) {
              if (res.status === 'ok') {
                var msg = '<?php echo addslashes($langs->trans('EasyOcrBatchSuccess')); ?>';
                if (res.data && (res.data.uuid || res.data.batch_id)) {
                  msg += ' — ID: ' + (res.data.uuid || res.data.batch_id || '').substring(0, 12) + '…';
                }
                eoBatchNotify(msg, 'success');
                selectedFiles = [];
                updateUI();
                resetBtn();
              } else {
                resetBtn();
                eoBatchNotify(res.message || '<?php echo addslashes($langs->trans('EasyOcrBatchApiError')); ?>', 'error');
              }
            },
            error: function() {
              resetBtn();
              eoBatchNotify('<?php echo addslashes($langs->trans('EasyOcrBatchApiError')); ?>', 'error');
            }
          });
        }

        function resetBtn() {
          submitBtn.disabled = false;
          submitBtn.classList.remove('eo-loading');
          submitText.textContent = '<?php echo addslashes($langs->trans('EasyOcrBatchProcess')); ?>';
        }

        // Start uploading files one by one
        uploadFile(0);
      });
    }
  })();

  // ─── Batch List JS ──────────────────────────────────────────────────────
  var eoBatchAjaxUrl = '<?php echo dol_buildpath('/easyocr/ajax/ajax_easyocr.php', 1); ?>';
  var eoBatchI18n = {
    noResults: '<?php echo addslashes($langs->trans('EasyOcrBatchNoResults')); ?>',
    errorLoading: '<?php echo addslashes($langs->trans('EasyOcrBatchApiError')); ?>',
    documents: '<?php echo addslashes(strtolower($langs->trans('Documents'))); ?>',
    created: '<?php echo addslashes($langs->trans('DateCreation')); ?>',
    status: '<?php echo addslashes($langs->trans('Status')); ?>',
    actions: '<?php echo addslashes($langs->trans('EasyOcrActions')); ?>',
    view: '<?php echo addslashes($langs->trans('View')); ?>',
    cancel: '<?php echo addslashes($langs->trans('EasyOcrCancel')); ?>',
    cancelConfirm: '<?php echo addslashes($langs->trans('EasyOcrBatchCancelConfirm')); ?>',
    cancelled: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusCancelled')); ?>',
    refresh: '<?php echo addslashes($langs->trans('Refresh')); ?>',
    loading: '<?php echo addslashes($langs->trans('Loading')); ?>',
    name: '<?php echo addslashes($langs->trans('Name')); ?>',
    uuid: 'UUID',
    totalDocs: '<?php echo addslashes($langs->trans('EasyOcrBatchTotalDocs')); ?>',
    processed: '<?php echo addslashes($langs->trans('EasyOcrBatchProcessed')); ?>',
    createdAt: '<?php echo addslashes($langs->trans('DateCreation')); ?>',
    completedAt: '<?php echo addslashes($langs->trans('EasyOcrBatchCompletedAt')); ?>',
    filename: '<?php echo addslashes($langs->trans('Filename')); ?>',
    pages: '<?php echo addslashes($langs->trans('EasyOcrBatchPages')); ?>',
    result: '<?php echo addslashes($langs->trans('Result')); ?>',
    docInfo: '<?php echo addslashes($langs->trans('EasyOcrBatchDocInfo')); ?>',
    noDocResults: '<?php echo addslashes($langs->trans('EasyOcrBatchNoDocResults')); ?>',
    batchResults: '<?php echo addslashes($langs->trans('EasyOcrBatchResultsTitle')); ?>',
    progress: '<?php echo addslashes($langs->trans('EasyOcrBatchProgress')); ?>',
    failedDocs: '<?php echo addslashes($langs->trans('EasyOcrBatchFailedDocs')); ?>',
    completedDocs: '<?php echo addslashes($langs->trans('EasyOcrBatchCompletedDocs')); ?>',
    prev: '<?php echo addslashes($langs->trans('Previous')); ?>',
    next: '<?php echo addslashes($langs->trans('Next')); ?>',
    // Document detail labels
    docNumber: '<?php echo addslashes($langs->trans('EasyOcrLabelInvoice')); ?>',
    docType: '<?php echo addslashes($langs->trans('Type')); ?>',
    issueDate: '<?php echo addslashes($langs->trans('EasyOcrLabelDate')); ?>',
    dueDate: '<?php echo addslashes($langs->trans('DateDue')); ?>',
    currency: '<?php echo addslashes($langs->trans('Currency')); ?>',
    supplier: '<?php echo addslashes($langs->trans('EasyOcrSupplier')); ?>',
    customer: '<?php echo addslashes($langs->trans('EasyOcrBatchCustomer')); ?>',
    taxId: '<?php echo addslashes($langs->trans('EasyOcrBatchTaxId')); ?>',
    address: '<?php echo addslashes($langs->trans('Address')); ?>',
    email: '<?php echo addslashes($langs->trans('Email')); ?>',
    phone: '<?php echo addslashes($langs->trans('Phone')); ?>',
    items: '<?php echo addslashes($langs->trans('EasyOcrBatchItems')); ?>',
    description: '<?php echo addslashes($langs->trans('Description')); ?>',
    code: '<?php echo addslashes($langs->trans('Code')); ?>',
    qty: '<?php echo addslashes($langs->trans('Qty')); ?>',
    unitPrice: '<?php echo addslashes($langs->trans('PriceUHT')); ?>',
    taxRate: '<?php echo addslashes($langs->trans('VAT')); ?> %',
    netAmount: '<?php echo addslashes($langs->trans('TotalHT')); ?>',
    totalLine: '<?php echo addslashes($langs->trans('TotalTTC')); ?>',
    totals: '<?php echo addslashes($langs->trans('EasyOcrBatchTotals')); ?>',
    netSubtotal: '<?php echo addslashes($langs->trans('TotalHT')); ?>',
    taxTotal: '<?php echo addslashes($langs->trans('VATAmount')); ?>',
    total: '<?php echo addslashes($langs->trans('TotalTTC')); ?>',
    totalPayable: '<?php echo addslashes($langs->trans('EasyOcrBatchTotalPayable')); ?>',
    withholdings: '<?php echo addslashes($langs->trans('EasyOcrBatchWithholdings')); ?>',
    surcharges: '<?php echo addslashes($langs->trans('EasyOcrBatchSurcharges')); ?>',
    discountTotal: '<?php echo addslashes($langs->trans('EasyOcrBatchDiscountTotal')); ?>',
    payment: '<?php echo addslashes($langs->trans('EasyOcrBatchPayment')); ?>',
    payMethod: '<?php echo addslashes($langs->trans('EasyOcrBatchPayMethod')); ?>',
    payStatus: '<?php echo addslashes($langs->trans('EasyOcrBatchPayStatus')); ?>',
    payTerms: '<?php echo addslashes($langs->trans('EasyOcrBatchPayTerms')); ?>',
    payRef: '<?php echo addslashes($langs->trans('EasyOcrBatchPayRef')); ?>',
    bankAccount: '<?php echo addslashes($langs->trans('EasyOcrBatchBankAccount')); ?>',
    metadata: '<?php echo addslashes($langs->trans('EasyOcrBatchMetadata')); ?>',
    pageCount: '<?php echo addslashes($langs->trans('EasyOcrBatchPages')); ?>',
    language: '<?php echo addslashes($langs->trans('Language')); ?>',
    processingTime: '<?php echo addslashes($langs->trans('EasyOcrBatchProcessingTime')); ?>',
    error: '<?php echo addslashes($langs->trans('Error')); ?>',
    itemTypeService: '<?php echo addslashes($langs->trans('Service')); ?>',
    itemTypeDiscount: '<?php echo addslashes($langs->trans('Discount')); ?>',
    itemTypeProduct: '<?php echo addslashes($langs->trans('Product')); ?>',
    startedAt: '<?php echo addslashes($langs->trans('EasyOcrBatchStartedAt')); ?>',
    logo: '<?php echo addslashes($langs->trans('EasyOcrBatchLogo')); ?>',
    stamp: '<?php echo addslashes($langs->trans('EasyOcrBatchStamp')); ?>',
    signature: '<?php echo addslashes($langs->trans('EasyOcrBatchSignature')); ?>',
    notes: '<?php echo addslashes($langs->trans('EasyOcrBatchNotes')); ?>',
    close: '<?php echo addslashes($langs->trans('Close')); ?>',
    checkingInvoice: '<?php echo addslashes($langs->trans('EasyOcrBatchCheckingInvoice')); ?>',
    invoiceExists: '<?php echo addslashes($langs->trans('EasyOcrBatchInvoiceExists')); ?>',
    invoiceNotCreated: '<?php echo addslashes($langs->trans('EasyOcrBatchInvoiceNotCreated')); ?>',
    createInvoice: '<?php echo addslashes($langs->trans('EasyOcrBatchCreateInvoice')); ?>',
    viewInvoice: '<?php echo addslashes($langs->trans('EasyOcrBatchViewInvoice')); ?>',
    // Status translations
    statusPending: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusPending')); ?>',
    statusProcessing: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusProcessing')); ?>',
    statusCompleted: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusCompleted')); ?>',
    statusFailed: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusFailed')); ?>',
    statusCancelled: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusCancelled')); ?>',
    statusPartial: '<?php echo addslashes($langs->trans('EasyOcrBatchStatusPartial')); ?>',
    // Trash / Papelera
    trash: '<?php echo addslashes($langs->trans('EasyOcrBatchTrash')); ?>',
    trashConfirm: '<?php echo addslashes($langs->trans('EasyOcrBatchTrashConfirm')); ?>',
    trashEmpty: '<?php echo addslashes($langs->trans('EasyOcrBatchTrashEmpty')); ?>',
    backToHistory: '<?php echo addslashes($langs->trans('EasyOcrBatchBackToHistory')); ?>'
  };

  var eoBatchCurrentPage = 1;
  var eoBatchTrashMode = false;

  function eoBatchStatusBadge(status) {
    var map = {
      pending: {
        cls: 'badge-status0',
        icon: 'fa-clock',
        label: eoBatchI18n.statusPending
      },
      processing: {
        cls: 'badge-status1',
        icon: 'fa-sync fa-spin',
        label: eoBatchI18n.statusProcessing
      },
      completed: {
        cls: 'badge-status4',
        icon: 'fa-check',
        label: eoBatchI18n.statusCompleted
      },
      partial: {
        cls: 'badge-status3',
        icon: 'fa-exclamation-triangle',
        label: eoBatchI18n.statusPartial
      },
      failed: {
        cls: 'badge-status8',
        icon: 'fa-times',
        label: eoBatchI18n.statusFailed
      },
      cancelled: {
        cls: 'badge-status9',
        icon: 'fa-ban',
        label: eoBatchI18n.statusCancelled
      }
    };
    var s = map[status] || {
      cls: 'badge-status0',
      icon: 'fa-question',
      label: status
    };
    return '<span class="badge badge-status ' + s.cls + '">' +
      '<span class="fas ' + s.icon + '" style="margin-right:4px"></span>' +
      s.label + '</span>';
  }

  function eoBatchFormatDate(dateStr) {
    if (!dateStr) return '-';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    var pad = function(n) {
      return n < 10 ? '0' + n : '' + n;
    };
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() +
      ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function eoBatchLoadList(page) {
    if (typeof page === 'undefined') page = 1;
    eoBatchCurrentPage = page;

    var container = document.getElementById('eo-batch-list-container');
    if (!container) return;

    container.innerHTML = '<div class="opacitymedium center" style="padding:40px">' +
      '<span class="fas fa-spinner fa-spin" style="margin-right:6px"></span>' + eoBatchI18n.loading + '...</div>';

    // Collect filters
    var statusFilter = '';
    var nameFilter = '';
    var fromFilter = '';
    var toFilter = '';
    var sel = document.getElementById('eo-batch-filter-status');
    if (sel) statusFilter = sel.value;
    // Trash mode overrides status filter
    if (eoBatchTrashMode) {
      statusFilter = 'cancelled';
    }
    var nameEl = document.getElementById('eo-batch-filter-name');
    if (nameEl) nameFilter = nameEl.value.trim();
    var fromEl = document.getElementById('eo-batch-filter-from');
    if (fromEl) fromFilter = fromEl.value;
    var toEl = document.getElementById('eo-batch-filter-to');
    if (toEl) toFilter = toEl.value;

    var perPageEl = document.getElementById('eo-batch-filter-perpage');
    var perPage = perPageEl ? parseInt(perPageEl.value, 10) : 20;
    if (!perPage || perPage < 1) perPage = 20;

    var ajaxData = {
      action: 'batchList',
      page: page,
      per_page: perPage,
      status: statusFilter
    };
    if (nameFilter) ajaxData.name = nameFilter;
    if (fromFilter) ajaxData.from = fromFilter;
    if (toFilter) ajaxData.to = toFilter;

    jQuery.ajax({
      url: eoBatchAjaxUrl,
      type: 'POST',
      data: ajaxData,
      dataType: 'json',
      success: function(res) {
        if (res.status !== 'ok' || !res.data) {
          container.innerHTML = '<div class="warning">' + (res.message || eoBatchI18n.errorLoading) + '</div>';
          return;
        }

        // Laravel pagination: { current_page, data: [...], last_page, per_page, total }
        var apiResp = res.data.data || res.data;
        var batches = apiResp.data || apiResp;
        var currentPage = apiResp.current_page || res.data.current_page || 1;
        var lastPage = apiResp.last_page || res.data.last_page || 1;
        var total = apiResp.total || res.data.total || 0;

        if (!Array.isArray(batches) || batches.length === 0) {
          container.innerHTML = '<div class="opacitymedium center" style="padding:30px">' +
            '<span class="fas fa-inbox" style="font-size:24px; display:block; margin-bottom:8px; color:#bbb"></span>' +
            eoBatchI18n.noResults + '</div>';
          return;
        }

        var html = '<div class="div-table-responsive-no-min">';
        html += '<table class="tagtable liste listwithfilterbefore">';
        html += '<thead><tr class="liste_titre">';
        html += '<th class="wrapcolumntitle">' + eoBatchI18n.name + '</th>';
        html += '<th class="wrapcolumntitle center">' + eoBatchI18n.status + '</th>';
        html += '<th class="wrapcolumntitle center">' + eoBatchI18n.progress + '</th>';
        html += '<th class="wrapcolumntitle center">' + eoBatchI18n.documents + '</th>';
        html += '<th class="wrapcolumntitle center">' + eoBatchI18n.created + '</th>';
        html += '<th class="wrapcolumntitle center">' + eoBatchI18n.actions + '</th>';
        html += '</tr></thead>';
        html += '<tbody>';

        for (var i = 0; i < batches.length; i++) {
          var b = batches[i];
          var batchId = b.batch_id || b.uuid || b.id || '';
          var bName = b.name || batchId.substring(0, 12) + '…';
          var totalDocs = b.total_documents || 0;
          var completedDocs = b.completed_documents || 0;
          var failedDocs = b.failed_documents || 0;
          var bProgress = b.progress || 0;
          var bStatus = b.status || 'pending';
          var bCreated = b.created_at || '';
          var canCancel = (bStatus === 'pending' || bStatus === 'processing');
          var isCancelled = (bStatus === 'cancelled');
          var canTrash = !isCancelled; // All non-cancelled batches can be sent to trash

          // Progress display
          var progressHtml = '';
          if (bStatus === 'processing') {
            progressHtml = '<div class="eo-batch-progress-bar"><div class="eo-batch-progress-fill" style="width:' + bProgress + '%"></div></div>' +
              '<span class="opacitymedium" style="font-size:10px">' + bProgress + '%</span>';
          } else if (bStatus === 'completed') {
            progressHtml = '<span class="fas fa-check-circle" style="color:#28a745"></span> 100%';
          } else if (bStatus === 'partial' || bStatus === 'failed') {
            progressHtml = '<span class="opacitymedium">' + bProgress + '%</span>';
          } else {
            progressHtml = '<span class="opacitymedium">-</span>';
          }

          // Documents display
          var docsHtml = completedDocs + ' / ' + totalDocs;
          if (failedDocs > 0) {
            docsHtml += ' <span class="badge badge-status badge-status8" style="font-size:9px" title="' + eoBatchI18n.failedDocs + '">' + failedDocs + ' <span class="fas fa-times"></span></span>';
          }

          // Row class: dimmed for cancelled batches in normal view
          var rowClass = 'oddeven';
          if (isCancelled && !eoBatchTrashMode) rowClass += ' eo-batch-row-cancelled';

          html += '<tr class="' + rowClass + '">';
          html += '<td><a href="#" onclick="eoBatchShowDetail(\'' + batchId + '\'); return false;" class="eo-batch-list-name">';
          html += '<span class="fas fa-layer-group" style="margin-right:6px; color:#8899aa"></span>';
          html += eoBatchEscHtml(bName) + '</a>';
          html += '<br><span class="opacitymedium" style="font-size:10px">' + batchId.substring(0, 16) + '…</span>';
          html += '</td>';
          html += '<td class="center">' + eoBatchStatusBadge(bStatus) + '</td>';
          html += '<td class="center">' + progressHtml + '</td>';
          html += '<td class="center">' + docsHtml + '</td>';
          html += '<td class="center nowraponall">' + eoBatchFormatDate(bCreated) + '</td>';
          html += '<td class="center nowraponall">';
          html += '<a class="button reposition smallpaddingimp" onclick="eoBatchShowDetail(\'' + batchId + '\'); return false;" title="' + eoBatchI18n.view + '">';
          html += '<span class="fas fa-eye"></span></a> ';
          if (canTrash) {
            if (canCancel) {
              // Pending/processing: cancel icon
              html += '<a class="button reposition smallpaddingimp" onclick="eoBatchCancelBatch(\'' + batchId + '\'); return false;" title="' + eoBatchI18n.cancel + '">';
              html += '<span class="fas fa-ban" style="color:#bc0000"></span></a> ';
            }
            // All non-cancelled: trash icon to send to papelera
            html += '<a class="button reposition smallpaddingimp" onclick="eoBatchTrashBatch(\'' + batchId + '\'); return false;" title="' + eoBatchI18n.trash + '">';
            html += '<span class="fas fa-trash-alt" style="color:#fff"></span></a>';
          }
          html += '</td>';
          html += '</tr>';
        }

        html += '</tbody></table></div>';

        // Pagination
        if (lastPage > 1) {
          html += '<div class="center" style="margin-top:10px">';
          if (currentPage > 1) {
            html += '<a class="button reposition" onclick="eoBatchLoadList(' + (currentPage - 1) + '); return false;">&laquo; ' + eoBatchI18n.prev + '</a> ';
          }
          html += '<span class="opacitymedium">' + currentPage + ' / ' + lastPage + ' (' + total + ')</span>';
          if (currentPage < lastPage) {
            html += ' <a class="button reposition" onclick="eoBatchLoadList(' + (currentPage + 1) + '); return false;">' + eoBatchI18n.next + ' &raquo;</a>';
          }
          html += '</div>';
        }

        container.innerHTML = html;
      },
      error: function() {
        container.innerHTML = '<div class="warning">' + eoBatchI18n.errorLoading + '</div>';
      }
    });
  }

  function eoBatchEscHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function eoBatchClearFilters() {
    var ids = ['eo-batch-filter-status', 'eo-batch-filter-name', 'eo-batch-filter-from', 'eo-batch-filter-to'];
    for (var i = 0; i < ids.length; i++) {
      var el = document.getElementById(ids[i]);
      if (el) el.value = '';
    }
    var ppEl = document.getElementById('eo-batch-filter-perpage');
    if (ppEl) ppEl.value = '20';
    eoBatchLoadList(1);
  }

  function eoBatchShowDetail(uuid) {
    var overlay = document.getElementById('eo-batch-detail-overlay');
    var title = document.getElementById('eo-batch-detail-title');
    var body = document.getElementById('eo-batch-detail-body');
    if (!overlay || !body) return;

    title.textContent = eoBatchI18n.loading + '...';
    body.innerHTML = '<div class="opacitymedium center" style="padding:30px">' +
      '<span class="fas fa-spinner fa-spin" style="margin-right:6px"></span>' + eoBatchI18n.loading + '...</div>';
    overlay.style.display = 'flex';

    // Load batch status first
    jQuery.ajax({
      url: eoBatchAjaxUrl,
      type: 'POST',
      data: {
        action: 'batchStatus',
        uuid: uuid
      },
      dataType: 'json',
      success: function(res) {
        if (res.status !== 'ok') {
          body.innerHTML = '<div class="warning">' + (res.message || eoBatchI18n.errorLoading) + '</div>';
          return;
        }

        var batch = res.data.data || res.data;
        var batchId = batch.batch_id || batch.uuid || uuid;
        var bName = batch.name || batchId.substring(0, 16) + '…';
        var bStatus = batch.status || 'pending';
        var totalDocs = batch.total_documents || 0;
        var completedDocs = batch.completed_documents || 0;
        var failedDocs = batch.failed_documents || 0;
        var bProgress = batch.progress || 0;
        var bCreated = batch.created_at || '';
        var bCompleted = batch.completed_at || '';
        var bStarted = batch.started_at || '';

        title.innerHTML = '<span class="fas fa-layer-group" style="margin-right:8px"></span>' + eoBatchEscHtml(bName);

        var html = '<table class="border centpercent tableforfield">';
        html += '<tr><td class="titlefield">' + eoBatchI18n.uuid + '</td><td><code style="font-size:11px; background:#f5f5f5; padding:2px 6px; border-radius:3px">' + eoBatchEscHtml(batchId) + '</code></td></tr>';
        html += '<tr><td>' + eoBatchI18n.status + '</td><td>' + eoBatchStatusBadge(bStatus) + '</td></tr>';
        html += '<tr><td>' + eoBatchI18n.progress + '</td><td>';
        if (bStatus === 'processing') {
          html += '<div class="eo-batch-progress-bar" style="display:inline-block; width:120px; vertical-align:middle"><div class="eo-batch-progress-fill" style="width:' + bProgress + '%"></div></div> ' + bProgress + '%';
        } else {
          html += bProgress + '%';
        }
        html += '</td></tr>';
        html += '<tr><td>' + eoBatchI18n.completedDocs + '</td><td>' + completedDocs + ' / ' + totalDocs + '</td></tr>';
        if (failedDocs > 0) {
          html += '<tr><td>' + eoBatchI18n.failedDocs + '</td><td><span style="color:#bc0000; font-weight:600">' + failedDocs + '</span></td></tr>';
        }
        html += '<tr><td>' + eoBatchI18n.createdAt + '</td><td>' + eoBatchFormatDate(bCreated) + '</td></tr>';
        if (bStarted) {
          html += '<tr><td>' + eoBatchI18n.startedAt + '</td><td>' + eoBatchFormatDate(bStarted) + '</td></tr>';
        }
        if (bCompleted) {
          html += '<tr><td>' + eoBatchI18n.completedAt + '</td><td>' + eoBatchFormatDate(bCompleted) + '</td></tr>';
        }
        if (batch.name) {
          html += '<tr><td>' + eoBatchI18n.name + '</td><td>' + eoBatchEscHtml(batch.name) + '</td></tr>';
        }
        html += '</table>';

        // Action buttons
        var canCancel = (bStatus === 'pending' || bStatus === 'processing');
        var isCancelled = (bStatus === 'cancelled');
        var canTrash = !isCancelled;
        var hasResults = (bStatus === 'completed' || bStatus === 'partial');
        html += '<div class="center" style="margin:12px 0 8px">';
        if (hasResults) {
          html += '<a class="button" onclick="eoBatchLoadResults(\'' + batchId + '\'); return false;">';
          html += '<span class="fas fa-file-alt" style="margin-right:6px"></span>' + eoBatchI18n.batchResults + '</a> ';
        }
        if (canCancel) {
          html += '<a class="button" onclick="eoBatchCancelBatch(\'' + batchId + '\'); return false;">';
          html += '<span class="fas fa-ban" style="margin-right:6px; color:#bc0000"></span>' + eoBatchI18n.cancel + '</a> ';
        }
        if (canTrash) {
          html += '<a class="button" onclick="eoBatchTrashBatch(\'' + batchId + '\'); return false;">';
          html += '<span class="fas fa-trash-alt" style="margin-right:6px; color:#fff"></span>' + eoBatchI18n.trash + '</a> ';
        }
        html += '<a class="button" onclick="eoBatchCloseDetail(); return false;">';
        html += '<span class="fas fa-times" style="margin-right:6px"></span>' + eoBatchI18n.close + '</a>';
        html += '</div>';

        // Results area placeholder
        html += '<div id="eo-batch-results-area"></div>';

        body.innerHTML = html;

        // Auto-load results if completed or partial
        if (hasResults) {
          eoBatchLoadResults(batchId);
        }
      },
      error: function() {
        body.innerHTML = '<div class="warning">' + eoBatchI18n.errorLoading + '</div>';
      }
    });
  }

  function eoBatchLoadResults(uuid) {
    var area = document.getElementById('eo-batch-results-area');
    if (!area) return;

    area.innerHTML = '<div class="opacitymedium center" style="padding:16px">' +
      '<span class="fas fa-spinner fa-spin" style="margin-right:6px"></span>' + eoBatchI18n.loading + '...</div>';

    jQuery.ajax({
      url: eoBatchAjaxUrl,
      type: 'POST',
      data: {
        action: 'batchResults',
        uuid: uuid
      },
      dataType: 'json',
      success: function(res) {
        if (res.status !== 'ok') {
          area.innerHTML = '<div class="warning">' + (res.message || eoBatchI18n.errorLoading) + '</div>';
          return;
        }

        var results = res.data.data || res.data;
        var docs = results.documents || results.results || results;

        if (!Array.isArray(docs) || docs.length === 0) {
          area.innerHTML = '<div class="opacitymedium center" style="padding:16px">' + eoBatchI18n.noDocResults + '</div>';
          return;
        }

        var html = '<br><div class="underbanner clearboth"></div>';
        html += '<table class="tagtable liste">';
        html += '<thead><tr class="liste_titre">';
        html += '<th>#</th>';
        html += '<th>' + eoBatchI18n.filename + '</th>';
        html += '<th class="center">' + eoBatchI18n.pages + '</th>';
        html += '<th class="center">' + eoBatchI18n.status + '</th>';
        html += '<th class="center">' + eoBatchI18n.actions + '</th>';
        html += '</tr></thead><tbody>';

        // Collect unique docNums for batched invoice check
        var _invoiceCheckQueue = {};

        for (var i = 0; i < docs.length; i++) {
          var doc = docs[i];
          var dName = doc.filename || doc.original_filename || doc.name || ('Doc ' + (i + 1));
          var dData = doc.structured_data || doc.data || null;
          var dPages = (dData && dData.metadata && dData.metadata.page_count) ? dData.metadata.page_count : (doc.pages || doc.total_pages || '-');
          var dStatus = doc.status || 'completed';
          var dDocNum = (dData && dData.document_number) ? dData.document_number : '';
          var dTaxId = (dData && dData.supplier && dData.supplier.tax_id) ? dData.supplier.tax_id : '';

          html += '<tr class="oddeven">';
          html += '<td>' + (i + 1) + '</td>';
          html += '<td><span class="fas fa-file-pdf" style="margin-right:6px; color:#bc0000"></span>' + eoBatchEscHtml(dName);
          if (dDocNum) html += '<br><span class="opacitymedium" style="font-size:10px">' + eoBatchEscHtml(dDocNum) + '</span>';
          html += '</td>';
          html += '<td class="center">' + dPages + '</td>';
          html += '<td class="center">' + eoBatchStatusBadge(dStatus) + '</td>';
          html += '<td class="center" style="white-space:nowrap">';
          // Invoice status icon (inline button, unique ID per row)
          if (dDocNum && dStatus === 'completed') {
            html += '<span id="eo-row-invoice-' + i + '" class="eo-row-invoice-icon" style="margin-right:4px" title="' + eoBatchI18n.checkingInvoice + '...">';
            html += '<a class="button reposition smallpaddingimp" style="opacity:0.5"><span class="fas fa-file-invoice"></span></a></span>';
            // Queue for batched check (one AJAX per unique docNum)
            if (!_invoiceCheckQueue[dDocNum]) {
              _invoiceCheckQueue[dDocNum] = {
                taxId: dTaxId,
                data: dData,
                rows: []
              };
            }
            _invoiceCheckQueue[dDocNum].rows.push(i);
          }
          html += '<a class="button reposition smallpaddingimp" onclick="eoBatchToggleDocDetail(' + i + '); return false;" title="' + eoBatchI18n.view + '">';
          html += '<span class="fas fa-chevron-down" id="eo-doc-chevron-' + i + '"></span></a>';
          html += '</td>';
          html += '</tr>';

          // Expandable detail row
          html += '<tr id="eo-doc-detail-' + i + '" style="display:none" class="oddeven">';
          html += '<td colspan="5">';
          html += '<div class="eo-batch-doc-detail">';
          html += eoBatchRenderDocDetail(doc, i);
          html += '</div>';
          html += '</td>';
          html += '</tr>';
        }

        html += '</tbody></table>';
        area.innerHTML = html;

        // Fire batched invoice checks (one per unique docNum)
        var uniqueCount = Object.keys(_invoiceCheckQueue).length;
        console.log('[EasyOCR] Row invoice checks: ' + uniqueCount + ' unique docNums from ' + docs.length + ' documents');
        var _checkDelay = 0;
        for (var docNum in _invoiceCheckQueue) {
          if (!_invoiceCheckQueue.hasOwnProperty(docNum)) continue;
          console.log('[EasyOCR]   → docNum "' + docNum + '" applies to rows: [' + _invoiceCheckQueue[docNum].rows.join(', ') + ']');
          (function(dn, entry) {
            setTimeout(function() {
              eoBatchRowInvoiceCheck(dn, entry.taxId, entry.data, entry.rows);
            }, 200 + _checkDelay);
          })(docNum, _invoiceCheckQueue[docNum]);
          _checkDelay += 200;
        }
      },
      error: function() {
        area.innerHTML = '<div class="warning">' + eoBatchI18n.errorLoading + '</div>';
      }
    });
  }

  function eoBatchRenderDocDetail(doc, rowIdx) {
    var data = doc.structured_data || doc.data || doc.result || null;
    if (!data || typeof data !== 'object') {
      return '<div class="opacitymedium center" style="padding:12px">' + eoBatchI18n.noDocResults + '</div>';
    }

    var html = '';

    // ── Action bar: Check if invoice exists and show creation button ──
    var docNum = data.document_number || null;
    var taxId = (data.supplier && data.supplier.tax_id) ? data.supplier.tax_id : null;

    if (docNum) {
      // Use row index for unique IDs (avoids duplicates when multiple docs share docNum)
      var detailIdSuffix = 'detail-' + rowIdx;
      var docDataB64 = btoa(unescape(encodeURIComponent(JSON.stringify(data))));
      html += '<div class="eo-batch-action-bar" style="margin-bottom:12px; padding:10px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px; display:flex; align-items:center; justify-content:space-between">';
      html += '<div style="display:flex; align-items:center; gap:8px">';
      html += '<span class="fas fa-file-invoice" style="color:#666"></span>';
      html += '<span style="font-weight:500; font-size:13px">' + eoBatchI18n.docNumber + ': ' + eoBatchEscHtml(docNum) + '</span>';
      html += '<span id="eo-invoice-status-' + detailIdSuffix + '" data-doc-num="' + eoBatchEscHtml(docNum) + '" data-tax-id="' + eoBatchEscHtml(taxId || '') + '" data-doc-data-b64="' + docDataB64 + '" style="margin-left:8px; font-size:12px; color:#888">' + eoBatchI18n.checkingInvoice + '...</span>';
      html += '</div>';
      html += '<div id="eo-invoice-action-' + detailIdSuffix + '"></div>';
      html += '</div>';
      // NOTE: AJAX check is deferred — only triggered when user expands this row (see eoBatchToggleDocDetail)
    }

    // ── Section 1: Document info ──
    html += '<div class="eo-batch-section">';
    html += '<div class="eo-batch-section-title"><span class="fas fa-file-invoice" style="margin-right:6px"></span>' + eoBatchI18n.docInfo + '</div>';
    html += '<table class="border centpercent tableforfield">';
    if (data.document_number) html += '<tr><td class="titlefield fieldrequired">' + eoBatchI18n.docNumber + '</td><td><strong>' + eoBatchEscHtml(data.document_number) + '</strong></td></tr>';
    if (data.document_type) {
      var dtLabel = data.document_type.charAt(0).toUpperCase() + data.document_type.slice(1);
      html += '<tr><td>' + eoBatchI18n.docType + '</td><td>' + eoBatchEscHtml(dtLabel) + '</td></tr>';
    }
    if (data.issue_date) html += '<tr><td>' + eoBatchI18n.issueDate + '</td><td>' + eoBatchEscHtml(data.issue_date) + '</td></tr>';
    if (data.due_date) html += '<tr><td>' + eoBatchI18n.dueDate + '</td><td>' + eoBatchEscHtml(data.due_date) + '</td></tr>';
    if (data.currency) html += '<tr><td>' + eoBatchI18n.currency + '</td><td>' + eoBatchEscHtml(data.currency) + '</td></tr>';
    html += '</table></div>';

    // ── Section 2: Supplier & Customer side by side ──
    var sup = data.supplier || null;
    var cus = data.customer || null;
    if (sup || cus) {
      html += '<div class="eo-batch-parties">';
      if (sup) {
        html += '<div class="eo-batch-party">';
        html += '<div class="eo-batch-section-title"><span class="fas fa-building" style="margin-right:6px"></span>' + eoBatchI18n.supplier + '</div>';
        html += '<table class="border centpercent tableforfield">';
        if (sup.name) html += '<tr><td class="titlefield">' + eoBatchI18n.name + '</td><td><strong>' + eoBatchEscHtml(sup.name) + '</strong></td></tr>';
        if (sup.tax_id) html += '<tr><td>' + eoBatchI18n.taxId + '</td><td><code>' + eoBatchEscHtml(sup.tax_id) + '</code></td></tr>';
        if (sup.address) html += '<tr><td>' + eoBatchI18n.address + '</td><td>' + eoBatchEscHtml(sup.address) + '</td></tr>';
        if (sup.email) html += '<tr><td>' + eoBatchI18n.email + '</td><td>' + eoBatchEscHtml(sup.email) + '</td></tr>';
        if (sup.phone) html += '<tr><td>' + eoBatchI18n.phone + '</td><td>' + eoBatchEscHtml(sup.phone) + '</td></tr>';
        html += '</table></div>';
      }
      if (cus) {
        html += '<div class="eo-batch-party">';
        html += '<div class="eo-batch-section-title"><span class="fas fa-user" style="margin-right:6px"></span>' + eoBatchI18n.customer + '</div>';
        html += '<table class="border centpercent tableforfield">';
        if (cus.name) html += '<tr><td class="titlefield">' + eoBatchI18n.name + '</td><td><strong>' + eoBatchEscHtml(cus.name) + '</strong></td></tr>';
        if (cus.tax_id) html += '<tr><td>' + eoBatchI18n.taxId + '</td><td><code>' + eoBatchEscHtml(cus.tax_id) + '</code></td></tr>';
        if (cus.address) html += '<tr><td>' + eoBatchI18n.address + '</td><td>' + eoBatchEscHtml(cus.address) + '</td></tr>';
        if (cus.email) html += '<tr><td>' + eoBatchI18n.email + '</td><td>' + eoBatchEscHtml(cus.email) + '</td></tr>';
        if (cus.phone) html += '<tr><td>' + eoBatchI18n.phone + '</td><td>' + eoBatchEscHtml(cus.phone) + '</td></tr>';
        html += '</table></div>';
      }
      html += '</div>';
    }

    // ── Section 3: Items / Lines ──
    var items = data.items || data.lines || data.line_items || [];
    if (Array.isArray(items) && items.length > 0) {
      html += '<div class="eo-batch-section">';
      html += '<div class="eo-batch-section-title"><span class="fas fa-list" style="margin-right:6px"></span>' + eoBatchI18n.items + ' (' + items.length + ')</div>';
      html += '<div class="div-table-responsive-no-min">';
      html += '<table class="tagtable liste">';
      html += '<thead><tr class="liste_titre">';
      html += '<th>#</th>';
      html += '<th>' + eoBatchI18n.description + '</th>';
      html += '<th>' + eoBatchI18n.code + '</th>';
      html += '<th class="right">' + eoBatchI18n.qty + '</th>';
      html += '<th class="right">' + eoBatchI18n.unitPrice + '</th>';
      html += '<th class="right">' + eoBatchI18n.taxRate + '</th>';
      html += '<th class="right">' + eoBatchI18n.netAmount + '</th>';
      html += '<th class="right">' + eoBatchI18n.totalLine + '</th>';
      html += '</tr></thead><tbody>';

      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        var isDiscount = (it.item_type === 'discount');
        var rowCls = isDiscount ? 'oddeven eo-batch-item-discount' : 'oddeven';
        var taxStr = '-';
        if (it.taxes && Array.isArray(it.taxes) && it.taxes.length > 0) {
          taxStr = it.taxes.map(function(t) {
            return (t.tax_rate || 0) + '%';
          }).join(', ');
        }
        var typeIcon = '';
        if (it.item_type === 'discount') typeIcon = '<span class="fas fa-tag" style="color:#e67700; margin-right:4px" title="' + eoBatchI18n.itemTypeDiscount + '"></span>';
        else if (it.item_type === 'service') typeIcon = '<span class="fas fa-concierge-bell" style="color:#228be6; margin-right:4px" title="' + eoBatchI18n.itemTypeService + '"></span>';
        else if (it.item_type === 'product') typeIcon = '<span class="fas fa-box" style="color:#40c057; margin-right:4px" title="' + eoBatchI18n.itemTypeProduct + '"></span>';

        html += '<tr class="' + rowCls + '">';
        html += '<td>' + (i + 1) + '</td>';
        html += '<td>' + typeIcon + eoBatchEscHtml(it.description || '-') + '</td>';
        html += '<td class="opacitymedium" style="font-size:11px">' + eoBatchEscHtml(it.code || '-') + '</td>';
        html += '<td class="right">' + eoBatchFmt(it.quantity) + '</td>';
        html += '<td class="right">' + eoBatchFmt(it.unit_price) + '</td>';
        html += '<td class="right">' + taxStr + '</td>';
        html += '<td class="right">' + eoBatchFmt(it.net_amount) + '</td>';
        html += '<td class="right nowraponall"><strong>' + eoBatchFmt(it.total) + '</strong></td>';
        html += '</tr>';
      }
      html += '</tbody></table></div></div>';
    }

    // ── Section 4: Totals ──
    var tot = data.totals || null;
    if (tot) {
      html += '<div class="eo-batch-section">';
      html += '<div class="eo-batch-section-title"><span class="fas fa-calculator" style="margin-right:6px"></span>' + eoBatchI18n.totals + '</div>';
      html += '<table class="border centpercent tableforfield">';
      if (tot.gross_subtotal != null) html += '<tr><td class="titlefield">' + eoBatchI18n.netSubtotal + '</td><td class="right" style="width:180px">' + eoBatchFmt(tot.net_subtotal) + ' ' + eoBatchEscHtml(data.currency || '') + '</td></tr>';

      // Tax breakdown
      if (tot.taxes && Array.isArray(tot.taxes)) {
        for (var t = 0; t < tot.taxes.length; t++) {
          var tx = tot.taxes[t];
          var txLabel = (tx.tax_type || 'IVA').toUpperCase() + ' ' + (tx.tax_rate || 0) + '%';
          html += '<tr><td>' + eoBatchEscHtml(txLabel);
          if (tx.tax_base != null) html += ' <span class="opacitymedium">(base: ' + eoBatchFmt(tx.tax_base) + ')</span>';
          html += '</td><td class="right" style="width:180px">' + eoBatchFmt(tx.tax_amount) + ' ' + eoBatchEscHtml(data.currency || '') + '</td></tr>';
        }
      }
      if (tot.tax_total != null) html += '<tr><td>' + eoBatchI18n.taxTotal + '</td><td class="right" style="width:180px">' + eoBatchFmt(tot.tax_total) + ' ' + eoBatchEscHtml(data.currency || '') + '</td></tr>';

      if (tot.discount_total != null) html += '<tr><td>' + eoBatchI18n.discountTotal + '</td><td class="right" style="width:180px">' + eoBatchFmt(tot.discount_total) + ' ' + eoBatchEscHtml(data.currency || '') + '</td></tr>';
      if (tot.surcharge_total != null) html += '<tr><td>' + eoBatchI18n.surcharges + '</td><td class="right" style="width:180px">' + eoBatchFmt(tot.surcharge_total) + ' ' + eoBatchEscHtml(data.currency || '') + '</td></tr>';
      if (tot.withholding_total != null) html += '<tr><td>' + eoBatchI18n.withholdings + '</td><td class="right" style="width:180px">' + eoBatchFmt(tot.withholding_total) + ' ' + eoBatchEscHtml(data.currency || '') + '</td></tr>';

      html += '<tr class="liste_total"><td><strong>' + eoBatchI18n.total + '</strong></td><td class="right" style="width:180px"><strong style="font-size:14px">' + eoBatchFmt(tot.total) + ' ' + eoBatchEscHtml(data.currency || '') + '</strong></td></tr>';
      if (tot.total_payable != null && tot.total_payable !== tot.total) {
        html += '<tr><td>' + eoBatchI18n.totalPayable + '</td><td class="right" style="width:180px"><strong>' + eoBatchFmt(tot.total_payable) + ' ' + eoBatchEscHtml(data.currency || '') + '</strong></td></tr>';
      }
      html += '</table></div>';
    }

    // ── Section 5: Payment ──
    var pay = data.payment || null;
    if (pay && (pay.method || pay.status || pay.terms || pay.reference || pay.bank_account)) {
      html += '<div class="eo-batch-section">';
      html += '<div class="eo-batch-section-title"><span class="fas fa-credit-card" style="margin-right:6px"></span>' + eoBatchI18n.payment + '</div>';
      html += '<table class="border centpercent tableforfield">';
      if (pay.method) html += '<tr><td class="titlefield">' + eoBatchI18n.payMethod + '</td><td>' + eoBatchEscHtml(pay.method) + '</td></tr>';
      if (pay.status) {
        var payBadge = pay.status === 'paid' ? 'badge-status4' : (pay.status === 'pending' ? 'badge-status0' : 'badge-status1');
        html += '<tr><td>' + eoBatchI18n.payStatus + '</td><td><span class="badge badge-status ' + payBadge + '">' + eoBatchEscHtml(pay.status) + '</span></td></tr>';
      }
      if (pay.terms) html += '<tr><td>' + eoBatchI18n.payTerms + '</td><td>' + eoBatchEscHtml(pay.terms) + '</td></tr>';
      if (pay.reference) html += '<tr><td>' + eoBatchI18n.payRef + '</td><td>' + eoBatchEscHtml(pay.reference) + '</td></tr>';
      if (pay.bank_account) html += '<tr><td>' + eoBatchI18n.bankAccount + '</td><td><code>' + eoBatchEscHtml(pay.bank_account) + '</code></td></tr>';
      html += '</table></div>';
    }

    // ── Section 6: Metadata & processing ──
    var meta = data.metadata || null;
    var procTime = doc.processing_time_ms || null;
    if (meta || procTime) {
      html += '<div class="eo-batch-section">';
      html += '<div class="eo-batch-section-title"><span class="fas fa-info-circle" style="margin-right:6px"></span>' + eoBatchI18n.metadata + '</div>';
      html += '<table class="border centpercent tableforfield">';
      if (meta) {
        if (meta.page_count) html += '<tr><td class="titlefield">' + eoBatchI18n.pageCount + '</td><td>' + meta.page_count + '</td></tr>';
        if (meta.language) html += '<tr><td>' + eoBatchI18n.language + '</td><td>' + eoBatchEscHtml(meta.language) + '</td></tr>';
        if (meta.has_logo != null) html += '<tr><td>' + eoBatchI18n.logo + '</td><td>' + (meta.has_logo ? '<span class="fas fa-check" style="color:#28a745"></span>' : '<span class="fas fa-times" style="color:#999"></span>') + '</td></tr>';
        if (meta.has_stamp != null) html += '<tr><td>' + eoBatchI18n.stamp + '</td><td>' + (meta.has_stamp ? '<span class="fas fa-check" style="color:#28a745"></span>' : '<span class="fas fa-times" style="color:#999"></span>') + '</td></tr>';
        if (meta.has_signature != null) html += '<tr><td>' + eoBatchI18n.signature + '</td><td>' + (meta.has_signature ? '<span class="fas fa-check" style="color:#28a745"></span>' : '<span class="fas fa-times" style="color:#999"></span>') + '</td></tr>';
      }
      if (procTime) html += '<tr><td>' + eoBatchI18n.processingTime + '</td><td>' + (procTime / 1000).toFixed(1) + ' s</td></tr>';
      html += '</table></div>';
    }

    // ── Notes ──
    if (data.notes) {
      html += '<div class="eo-batch-section">';
      html += '<div class="eo-batch-section-title"><span class="fas fa-sticky-note" style="margin-right:6px"></span>' + eoBatchI18n.notes + '</div>';
      html += '<div style="padding:6px; background:#fffbeb; border:1px solid #f0e6b8; border-radius:3px; font-size:12px">' + eoBatchEscHtml(data.notes) + '</div>';
      html += '</div>';
    }

    // ── Error ──
    if (doc.error) {
      html += '<div class="eo-batch-section">';
      html += '<div class="warning" style="padding:8px">';
      html += '<span class="fas fa-exclamation-triangle" style="margin-right:6px"></span>';
      html += eoBatchI18n.error + ': ' + eoBatchEscHtml(String(doc.error));
      html += '</div></div>';
    }

    return html;
  }

  // Check if invoice exists and render action button
  function eoBatchCheckAndRenderInvoiceButton(docNum, docNumSafe, taxId, data) {
    console.log('[EasyOCR] eoBatchCheckAndRenderInvoiceButton: docNum="' + docNum + '", suffix="' + docNumSafe + '"');
    var statusEl = document.getElementById('eo-invoice-status-' + docNumSafe);
    var actionEl = document.getElementById('eo-invoice-action-' + docNumSafe);

    if (!statusEl || !actionEl) {
      console.log('[EasyOCR]   → elements not found, skipping');
      return;
    }

    var ajaxData = {
      action: 'checkInvoiceExists',
      token: '<?php echo newToken(); ?>',
      ref_supplier: docNum
    };

    // Add fk_soc if tax_id is available (better filtering)
    if (taxId) {
      // First need to find supplier by CIF
      jQuery.ajax({
        url: '<?php echo DOL_URL_ROOT . '/custom/easyocr/ajax/ajax_easyocr.php'; ?>',
        type: 'POST',
        data: {
          action: 'findSupplierByCIF',
          token: '<?php echo newToken(); ?>',
          cif: taxId
        },
        success: function(res) {
          if (res && res.status === 'found') {
            // Use first supplier if multiple found
            var supplierId = res.fk_soc || (res.suppliers && res.suppliers.length > 0 ? res.suppliers[0].id : null);
            if (supplierId) {
              ajaxData.fk_soc = supplierId;
            }
          }
          // Proceed with invoice check
          eoBatchDoInvoiceCheck(docNum, ajaxData, statusEl, actionEl, data);
        },
        error: function() {
          // Proceed without fk_soc filter
          eoBatchDoInvoiceCheck(docNum, ajaxData, statusEl, actionEl, data);
        }
      });
    } else {
      // No tax_id, check directly
      eoBatchDoInvoiceCheck(docNum, ajaxData, statusEl, actionEl, data);
    }
  }

  function eoBatchDoInvoiceCheck(docNum, ajaxData, statusEl, actionEl, data) {
    jQuery.ajax({
      url: '<?php echo DOL_URL_ROOT . '/custom/easyocr/ajax/ajax_easyocr.php'; ?>',
      type: 'POST',
      data: ajaxData,
      success: function(res) {
        if (!res) {
          statusEl.textContent = '';
          return;
        }

        if (res.exists) {
          // Invoice exists - show link
          statusEl.innerHTML = '<span class="fas fa-check-circle" style="color:#28a745; margin-right:4px"></span>' + eoBatchI18n.invoiceExists;
          var url = '<?php echo DOL_URL_ROOT; ?>/fourn/facture/card.php?facid=' + res.invoice_id;
          actionEl.innerHTML = '<a href="' + url + '" class="butAction" target="_blank" style="margin:0">' + eoBatchI18n.viewInvoice + '</a>';
        } else {
          // Invoice does not exist - show create button
          statusEl.innerHTML = '<span class="fas fa-info-circle" style="color:#666; margin-right:4px"></span><span style="color:#666">' + eoBatchI18n.invoiceNotCreated + '</span>';
          actionEl.innerHTML = '<button class="butAction" style="margin:0" onclick="eoBatchCreateInvoiceFromDoc(event); return false;">' + eoBatchI18n.createInvoice + '</button>';
          // Store data in button for later access
          actionEl.querySelector('button').dataset.docData = JSON.stringify(data);
        }
      },
      error: function() {
        statusEl.textContent = '';
      }
    });
  }

  // Open AI modal with batch document data pre-filled
  function eoBatchCreateInvoiceFromDoc(event) {
    var btn = event.target || event.srcElement;
    var dataStr = btn.dataset.docData;
    if (!dataStr) return;

    var data = JSON.parse(dataStr);

    // Store data in localStorage and redirect to extract.php where AI modal exists
    try {
      localStorage.setItem('eoBatchInvoiceData', JSON.stringify(data));
      localStorage.setItem('eoBatchInvoiceTimestamp', Date.now().toString());
      // Redirect to extract.php which will auto-open modal with this data
      window.location.href = '<?php echo dol_buildpath('/easyocr/extract.php', 1); ?>?fromBatch=1';
    } catch (e) {
      alert('Error al preparar datos. Por favor, intenta desde la herramienta principal.');
      console.error('localStorage error:', e);
    }
  }

  // Row-level invoice check — one AJAX per unique docNum, applied to all matching rows
  function eoBatchRowInvoiceCheck(docNum, taxId, data, rowIndexes) {
    console.log('[EasyOCR] eoBatchRowInvoiceCheck: docNum="' + docNum + '", rows=[' + rowIndexes.join(',') + ']');
    // Verify at least one row element exists
    var firstEl = document.getElementById('eo-row-invoice-' + rowIndexes[0]);
    if (!firstEl) return;

    var ajaxData = {
      action: 'checkInvoiceExists',
      token: '<?php echo newToken(); ?>',
      ref_supplier: docNum
    };

    function applyResult(htmlContent, docData) {
      for (var r = 0; r < rowIndexes.length; r++) {
        var el = document.getElementById('eo-row-invoice-' + rowIndexes[r]);
        if (!el) continue;
        el.innerHTML = htmlContent;
        el.title = '';
        if (docData) el.dataset.docData = JSON.stringify(docData);
      }
    }

    function doCheck(fkSoc) {
      if (fkSoc) ajaxData.fk_soc = fkSoc;
      jQuery.ajax({
        url: eoBatchAjaxUrl,
        type: 'POST',
        data: ajaxData,
        dataType: 'json',
        success: function(res) {
          if (!res) return;
          if (res.exists) {
            var url = '<?php echo DOL_URL_ROOT; ?>/fourn/facture/card.php?facid=' + res.invoice_id;
            applyResult('<a href="' + url + '" target="_blank" class="button reposition smallpaddingimp" title="' + eoBatchI18n.viewInvoice + ': ' + eoBatchEscHtml(res.invoice_ref || docNum) + '" style="color:#28a745"><span class="fas fa-file-invoice"></span></a>', null);
          } else {
            // For "create" action, each row gets its own onclick with its row index
            for (var r = 0; r < rowIndexes.length; r++) {
              var el = document.getElementById('eo-row-invoice-' + rowIndexes[r]);
              if (!el) continue;
              el.innerHTML = '<a href="#" onclick="eoBatchRowCreateInvoice(' + rowIndexes[r] + ', event); return false;" class="button reposition smallpaddingimp" title="' + eoBatchI18n.invoiceNotCreated + ' \u2014 ' + eoBatchI18n.createInvoice + '" style="color:#dc3545"><span class="fas fa-file-invoice"></span></a>';
              el.title = '';
              el.dataset.docData = JSON.stringify(data);
            }
          }
        },
        error: function() {
          applyResult('<a class="button reposition smallpaddingimp" style="opacity:0.3"><span class="fas fa-file-invoice"></span></a>', null);
        }
      });
    }

    if (taxId) {
      jQuery.ajax({
        url: eoBatchAjaxUrl,
        type: 'POST',
        data: {
          action: 'findSupplierByCIF',
          token: '<?php echo newToken(); ?>',
          cif: taxId
        },
        dataType: 'json',
        success: function(res) {
          var fkSoc = (res && res.status === 'found') ? (res.fk_soc || (res.suppliers && res.suppliers.length > 0 ? res.suppliers[0].id : null)) : null;
          doCheck(fkSoc);
        },
        error: function() {
          doCheck(null);
        }
      });
    } else {
      doCheck(null);
    }
  }

  // Create invoice from row icon click
  function eoBatchRowCreateInvoice(rowIdx, event) {
    event.preventDefault();
    var iconEl = document.getElementById('eo-row-invoice-' + rowIdx);
    if (!iconEl || !iconEl.dataset.docData) return;

    var data = JSON.parse(iconEl.dataset.docData);
    try {
      localStorage.setItem('eoBatchInvoiceData', JSON.stringify(data));
      localStorage.setItem('eoBatchInvoiceTimestamp', Date.now().toString());
      window.location.href = '<?php echo dol_buildpath('/easyocr/extract.php', 1); ?>?fromBatch=1';
    } catch (e) {
      console.error('localStorage error:', e);
    }
  }

  // Format number helper
  function eoBatchFmt(val) {
    if (val === null || val === undefined || val === '') return '-';
    var n = parseFloat(val);
    if (isNaN(n)) return eoBatchEscHtml(String(val));
    return n.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function eoBatchToggleDocDetail(idx) {
    var row = document.getElementById('eo-doc-detail-' + idx);
    var chevron = document.getElementById('eo-doc-chevron-' + idx);
    if (!row) return;
    if (row.style.display === 'none') {
      row.style.display = '';
      if (chevron) chevron.className = 'fas fa-chevron-up';
      // Lazy-load detail invoice check on first expand
      var statusEl = document.getElementById('eo-invoice-status-detail-' + idx);
      if (statusEl && !statusEl.dataset.checked) {
        statusEl.dataset.checked = '1';
        var docNum = statusEl.dataset.docNum;
        var taxId = statusEl.dataset.taxId || '';
        var docData = null;
        if (statusEl.dataset.docDataB64) {
          try {
            docData = JSON.parse(decodeURIComponent(escape(atob(statusEl.dataset.docDataB64))));
          } catch (e) {}
        }
        if (docNum) {
          console.log('[EasyOCR] Detail panel check for row ' + idx + ', docNum: ' + docNum);
          eoBatchCheckAndRenderInvoiceButton(docNum, 'detail-' + idx, taxId, docData);
        }
      }
    } else {
      row.style.display = 'none';
      if (chevron) chevron.className = 'fas fa-chevron-down';
    }
  }

  function eoBatchCancelBatch(uuid) {
    if (!confirm(eoBatchI18n.cancelConfirm)) return;

    jQuery.ajax({
      url: eoBatchAjaxUrl,
      type: 'POST',
      data: {
        action: 'batchCancel',
        uuid: uuid
      },
      dataType: 'json',
      success: function(res) {
        if (res.status === 'ok') {
          // Reload list
          eoBatchLoadList(eoBatchCurrentPage);
          eoBatchCloseDetail();
          eoBatchUpdateTrashBadge();
        } else {
          alert(res.message || eoBatchI18n.errorLoading);
        }
      },
      error: function() {
        alert(eoBatchI18n.errorLoading);
      }
    });
  }

  function eoBatchCloseDetail() {
    var overlay = document.getElementById('eo-batch-detail-overlay');
    if (overlay) overlay.style.display = 'none';
  }

  // ─── Trash / Papelera functions ─────────────────────────────────────────

  function eoBatchTrashBatch(uuid) {
    if (!confirm(eoBatchI18n.trashConfirm)) return;

    jQuery.ajax({
      url: eoBatchAjaxUrl,
      type: 'POST',
      data: {
        action: 'batchCancel',
        uuid: uuid
      },
      dataType: 'json',
      success: function(res) {
        if (res.status === 'ok') {
          eoBatchLoadList(eoBatchCurrentPage);
          eoBatchCloseDetail();
          eoBatchUpdateTrashBadge();
        } else {
          alert(res.message || eoBatchI18n.errorLoading);
        }
      },
      error: function() {
        alert(eoBatchI18n.errorLoading);
      }
    });
  }

  function eoBatchToggleTrash() {
    eoBatchTrashMode = !eoBatchTrashMode;
    var btn = document.getElementById('eo-batch-trash-toggle');
    var sel = document.getElementById('eo-batch-filter-status');

    if (eoBatchTrashMode) {
      // Activate trash mode
      if (btn) {
        btn.classList.add('butActionDelete');
        btn.classList.remove('button');
      }
      if (sel) {
        sel.value = 'cancelled';
        sel.disabled = true;
      }
    } else {
      // Deactivate trash mode
      if (btn) {
        btn.classList.remove('butActionDelete');
        btn.classList.add('button');
      }
      if (sel) {
        sel.value = '';
        sel.disabled = false;
      }
    }

    eoBatchLoadList(1);
  }

  function eoBatchUpdateTrashBadge() {
    jQuery.ajax({
      url: eoBatchAjaxUrl,
      type: 'POST',
      data: {
        action: 'batchList',
        page: 1,
        per_page: 1,
        status: 'cancelled'
      },
      dataType: 'json',
      success: function(res) {
        var badge = document.getElementById('eo-batch-trash-badge');
        if (!badge) return;
        if (res.status === 'ok' && res.data) {
          var total = res.data.total || 0;
          if (total > 0) {
            badge.textContent = total;
            badge.style.display = 'inline';
          } else {
            badge.style.display = 'none';
          }
        }
      }
    });
  }

  // Auto-load batch list on page load if on list tab
  <?php if ($activeTab == 'list' && $canBatch) { ?>
    jQuery(document).ready(function() {
      eoBatchLoadList(1);
      eoBatchUpdateTrashBadge();
    });
  <?php } ?>
</script>

<?php
llxFooter();
