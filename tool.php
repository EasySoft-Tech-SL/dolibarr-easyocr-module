<?php

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {$i--;$j--;}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/lib/easyocr.lib.php';
require_once __DIR__ . '/lib/easyocr_ai.class.php';
require_once __DIR__ . '/lib/easyocr_autoload.php';

// Security check - require write permission to process invoices
if (!easyocrCheckRight($user, 'easyocr', 'write')) {
	accessforbidden();
}

// Check AI config server-side
$aiService = new EasyOcrAI($db);
$aiEnabled = $aiService->isEnabled();

// Fetch subscription info if API is enabled
$subscriptionData = null;
if ($aiEnabled) {
	try {
		$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
		$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';
		if (!empty($apiKey)) {
			$client = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
			$accountData = $client->account()->me();
			$subscriptionData = $accountData['data'] ?? null;
		}
	} catch (\Exception $e) {
		// Silently fail - subscription widget will not show
		$subscriptionData = null;
	}
}

$form = new Form($db);
$langs->load('easyocr@easyocr');

$arrayofjs = array(
	dol_buildpath('/easyocr/js/pdf.min.js', 1),
	dol_buildpath('/easyocr/libraries/notify.min.js', 1),
	dol_buildpath('/easyocr/js/scripts.js.php', 1),
);
$arrayofcss = array(
	dol_buildpath('/easyocr/css/easyocr.css', 1),
);

llxHeader("", "EasyOcr", '', '', 0, 0, $arrayofjs, $arrayofcss);
?>

<!-- Loader global -->
<div id="loader" class="eo-loader-overlay">
  <div class="eo-spinner"></div>
</div>

<!-- Layout principal a 2 paneles -->
<div class="eo-app">

  <!-- Panel izquierdo: Visor PDF -->
  <div class="eo-viewer">
    <div class="eo-viewer-toolbar">
      <label class="eo-upload-btn" for="pdfInput">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <?php echo $langs->trans('EasyOcrImportPdf'); ?>
      </label>
      <input type="file" id="pdfInput" accept=".pdf" style="display:none">
      <span id="eo-filename" class="eo-filename"><?php echo $langs->trans('EasyOcrNoFileSelected'); ?></span>
      <div id="eo-zoom-controls" class="eo-zoom-controls" style="display:none">
        <button class="eo-zoom-btn" onclick="EasyOcr.zoomOut()" title="<?php echo $langs->trans('EasyOcrZoomOut'); ?>">−</button>
        <span id="eo-zoom-label" class="eo-zoom-label">150%</span>
        <button class="eo-zoom-btn" onclick="EasyOcr.zoomIn()" title="<?php echo $langs->trans('EasyOcrZoomIn'); ?>">+</button>
      </div>
      <span id="eo-page-indicator" class="eo-page-indicator" style="display:none"></span>
    </div>

    <div class="eo-canvas-area" id="eo-canvas-area">
      <div class="eo-empty-state" id="eo-empty-state" title="<?php echo $langs->trans('EasyOcrDropHere'); ?>">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <p><?php echo $langs->trans('EasyOcrImportPdfToStart'); ?></p>
        <p class="eo-empty-hint"><?php echo $langs->trans('EasyOcrDragAndDrop'); ?></p>
      </div>
      <div class="eo-drop-overlay" id="eo-drop-overlay">
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span><?php echo $langs->trans('EasyOcrDropPdfHere'); ?></span>
      </div>
      <div id="canvas-container"></div>
    </div>
  </div>

  <!-- Panel derecho: Controles -->
  <div class="eo-sidebar" id="eo-sidebar">
  <div class="eo-sidebar-scroll">

    <!-- Banner IA OCR — Siempre visible, dos estados -->
    <div class="eo-section eo-ai-hero<?php echo $aiEnabled ? '' : ' eo-ai-disabled'; ?>" id="eo-ai-section" data-ai-enabled="<?php echo $aiEnabled ? '1' : '0'; ?>">
      <!-- Estado activo -->
      <div class="eo-ai-active-content" id="eo-ai-active"<?php if (!$aiEnabled) echo ' style="display:none"'; ?>>
        <div class="eo-ai-hero-header">
          <div class="eo-ai-hero-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 014 4v1a2 2 0 012 2v1a2 2 0 01-2 2H8a2 2 0 01-2-2V9a2 2 0 012-2V6a4 4 0 014-4z"/><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 14v4"/></svg>
          </div>
          <div>
            <span class="eo-ai-hero-title"><?php echo $langs->trans('EasyOcrAIOcr'); ?> <span class="eo-badge eo-badge-ai">PRO</span></span>
            <p class="eo-ai-hero-hint"><?php echo $langs->trans('EasyOcrAIOcrHint'); ?></p>
          </div>
        </div>
        <div class="eo-ai-progress" id="eo-ai-progress" style="display:none">
          <div class="eo-ai-progress-bar"><div class="eo-ai-progress-fill" id="eo-ai-progress-fill"></div></div>
          <span class="eo-ai-progress-text" id="eo-ai-progress-text"></span>
        </div>
        <button class="eo-btn eo-btn-ai" onclick="EasyOcr.runAIOcr()" id="eo-btn-ai-ocr">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 014 4v1a2 2 0 012 2v1a2 2 0 01-2 2H8a2 2 0 01-2-2V9a2 2 0 012-2V6a4 4 0 014-4z"/><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 14v4"/></svg>
          <?php echo $langs->trans('EasyOcrAIExtract'); ?>
        </button>

        <?php if (!empty($subscriptionData)) {
          $plan = $subscriptionData['plan'] ?? [];
          $quota = $subscriptionData['quota'] ?? [];
          $wallet = $subscriptionData['wallet'] ?? [];
          
          $pagesUsed = $quota['pages_used'] ?? 0;
          $pagesLimit = $quota['pages_limit'] ?? 0;
          $pagesRemaining = $quota['pages_remaining'] ?? 0;
          $usagePercentage = $quota['usage_percentage'] ?? 0;
          $resetDate = $quota['reset_date'] ?? '';
          $planName = $plan['name'] ?? '';
          $isFree = !empty($plan['is_free']);
          
          // Wallet info
          $hasWallet = !empty($wallet['exists']);
          $walletBalance = $wallet['balance_pages'] ?? 0;
          
          // Determinar estado
          $statusClass = '';
          $statusIcon = '';
          $statusText = '';
          if ($usagePercentage >= 100) {
            $statusClass = 'danger';
            $statusIcon = '⚠️';
            $statusText = $langs->trans('EasyOcrQuotaExceeded');
          } elseif ($usagePercentage >= 80) {
            $statusClass = 'warning';
            $statusIcon = '⚠️';
            $statusText = $langs->trans('EasyOcrQuotaNearLimit');
          } else {
            $statusClass = 'ok';
            $statusIcon = '✓';
            $statusText = '';
          }
          
          $percentage = $pagesLimit > 0 ? min(round(($pagesUsed / $pagesLimit) * 100, 1), 100) : 0;
        ?>
        <!-- Subscription Status -->
        <div class="eo-quota-compact" id="eo-quota-compact">
          <button class="eo-quota-toggle" onclick="this.classList.toggle('eo-active'); this.nextElementSibling.classList.toggle('eo-visible')" title="<?php echo $langs->trans('EasyOcrViewFullPlan'); ?>">
            <div class="eo-quota-toggle-left">
              <span class="eo-quota-status-icon eo-status-<?php echo $statusClass; ?>" id="eo-quota-status-icon"><?php echo $statusIcon; ?></span>
              <span class="eo-quota-compact-text" id="eo-quota-compact-text">
                <strong><?php echo number_format($pagesUsed, 0, ',', '.'); ?></strong> / <?php echo number_format($pagesLimit, 0, ',', '.'); ?> <?php echo $langs->trans('EasyOcrPlanPages'); ?>
              </span>
            </div>
            <div class="eo-quota-toggle-right">
              <span class="eo-quota-remaining" id="eo-quota-remaining"><?php echo number_format($pagesRemaining, 0, ',', '.'); ?> <?php echo $langs->trans('EasyOcrQuotaRemaining'); ?></span>
              <svg class="eo-quota-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
          </button>
          
          <div class="eo-quota-details">
            <div class="eo-quota-detail-header">
              <span class="eo-quota-plan-badge" id="eo-quota-plan-badge">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                <span id="eo-quota-plan-name"><?php echo dol_escape_htmltag($planName); ?></span>
                <?php if ($isFree) { ?>
                  <span class="eo-mini-badge" id="eo-quota-free-badge">FREE</span>
                <?php } ?>
              </span>
            </div>
            
            <div class="eo-quota-progress-wrapper">
              <div class="eo-quota-progress-bar">
                <div class="eo-quota-progress-fill eo-status-<?php echo $statusClass; ?>" id="eo-quota-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
              </div>
              <span class="eo-quota-percentage" id="eo-quota-percentage"><?php echo $percentage; ?>%</span>
            </div>
            
            <div class="eo-quota-stats-grid" id="eo-quota-stats-grid">
              <div class="eo-quota-stat-item">
                <span class="eo-quota-stat-label"><?php echo $langs->trans('EasyOcrQuotaPages'); ?></span>
                <span class="eo-quota-stat-value" id="eo-quota-pages-val"><?php echo number_format($pagesUsed, 0, ',', '.'); ?> / <?php echo number_format($pagesLimit, 0, ',', '.'); ?></span>
              </div>
              <div class="eo-quota-stat-item">
                <span class="eo-quota-stat-label"><?php echo $langs->trans('EasyOcrQuotaRemaining'); ?></span>
                <span class="eo-quota-stat-value" id="eo-quota-remaining-val"><?php echo number_format($pagesRemaining, 0, ',', '.'); ?></span>
              </div>
              <div class="eo-quota-stat-item" id="eo-quota-reset-item" <?php if (empty($resetDate)) echo 'style="display:none"'; ?>>
                <span class="eo-quota-stat-label"><?php echo $langs->trans('EasyOcrQuotaReset'); ?></span>
                <span class="eo-quota-stat-value" id="eo-quota-reset-val"><?php echo !empty($resetDate) ? dol_print_date(strtotime($resetDate), 'day') : ''; ?></span>
              </div>
              <div class="eo-quota-stat-item eo-wallet-stat" id="eo-quota-wallet-item" <?php if (!$hasWallet || $walletBalance <= 0) echo 'style="display:none"'; ?>>
                <span class="eo-quota-stat-label">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                  <?php echo $langs->trans('EasyOcrWalletBalance'); ?>
                </span>
                <span class="eo-quota-stat-value" id="eo-quota-wallet-val"><?php echo number_format($walletBalance, 0, ',', '.'); ?></span>
              </div>
            </div>
            
            <div class="eo-quota-alert" id="eo-quota-alert" <?php if (empty($statusText)) echo 'style="display:none"'; ?> <?php if (!empty($statusText)) echo 'class="eo-quota-alert eo-status-'.$statusClass.'"'; ?>>
              <span id="eo-quota-alert-text"><?php echo $statusText; ?></span>
            </div>
            
            <a href="<?php echo dol_buildpath('/easyocr/admin/plan.php', 1); ?>" class="eo-quota-link" target="_blank">
              <?php echo $langs->trans('EasyOcrViewFullPlan'); ?>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg>
            </a>
          </div>
        </div>
        <?php } ?>
      </div>
      <!-- Estado desactivado (promo) -->
      <div class="eo-ai-disabled-content" id="eo-ai-disabled"<?php if ($aiEnabled) echo ' style="display:none"'; ?>>
        <div class="eo-ai-hero-header">
          <div class="eo-ai-hero-icon eo-ai-icon-promo">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/></svg>
          </div>
          <div>
            <span class="eo-ai-hero-title">easyOCR AI <span class="eo-badge eo-badge-ai">PRO</span></span>
            <p class="eo-ai-cta-subtitle"><?php echo $langs->trans('EasyOcrAICtaHeadline'); ?></p>
          </div>
        </div>
        <ul class="eo-ai-features">
          <li><?php echo $langs->trans('EasyOcrAIFeat1'); ?></li>
          <li><?php echo $langs->trans('EasyOcrAIFeat2'); ?></li>
          <li><?php echo $langs->trans('EasyOcrAIFeat3'); ?></li>
        </ul>
        <div class="eo-ai-activate-hint">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <?php echo $langs->trans('EasyOcrAIActivateHint'); ?>
          &middot;
          <a href="https://easyocr.easysoft.es/" target="_blank" class="eo-ai-link">easyocr.easysoft.es</a>
        </div>
      </div>
    </div>

    <!-- Etiquetas -->
    <div class="eo-section">
      <div class="eo-section-title"><?php echo $langs->trans('EasyOcrLabels'); ?> <span class="eo-shortcut-hint">1-8</span></div>
      <p class="eo-hint"><?php echo $langs->trans('EasyOcrSelectLabelHint'); ?></p>
      <div id="eo-tags" class="eo-tags"></div>
    </div>

    <!-- Plantillas -->
    <div class="eo-section">
      <div class="eo-section-title"><?php echo $langs->trans('EasyOcrTemplates'); ?></div>
      <div class="eo-template-row">
        <select id="eo-template-select" class="eo-select">
          <option value=""><?php echo $langs->trans('EasyOcrNoTemplate'); ?></option>
        </select>
        <button class="eo-btn eo-btn-sm eo-btn-primary" onclick="EasyOcr.loadTemplate()"><?php echo $langs->trans('EasyOcrApply'); ?></button>
        <button class="eo-btn eo-btn-sm eo-btn-secondary" id="eo-btn-clear-tpl" onclick="EasyOcr.clearTemplate()" style="display:none" title="<?php echo $langs->trans('EasyOcrClearTemplate'); ?>">✕</button>
      </div>
    </div>

    <!-- Instrucciones IA personalizadas -->
    <?php if ($aiEnabled) { ?>
    <div class="eo-section" id="eo-ci-section">
      <div class="eo-section-title eo-collapsible eo-collapsed" onclick="this.classList.toggle('eo-collapsed');this.nextElementSibling.classList.toggle('eo-hidden')">
        <?php echo $langs->trans('EasyOcrCustomInstructions'); ?>
        <span id="eo-ci-badge" class="eo-badge eo-badge-ai" style="display:none;font-size:9px;padding:1px 5px">AI</span>
        <svg class="eo-collapse-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </div>
      <div class="eo-hidden">
        <p class="eo-hint"><?php echo $langs->trans('EasyOcrCustomInstructionsHint'); ?></p>
        <textarea id="eo-custom-instructions" class="eo-input" rows="3" placeholder="<?php echo $langs->trans('EasyOcrCustomInstructionsPlaceholder'); ?>"></textarea>
      </div>
    </div>
    <?php } ?>

    <!-- Metadatos PDF -->
    <div class="eo-section" id="eo-metadata-section" style="display:none">
      <div class="eo-section-title eo-collapsible" onclick="this.classList.toggle('eo-collapsed');this.nextElementSibling.classList.toggle('eo-hidden')">
        <?php echo $langs->trans('EasyOcrPdfMetadata'); ?>
        <svg class="eo-collapse-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </div>
      <div id="eo-metadata-content" class="eo-metadata-content"></div>
    </div>

    <!-- Datos extraídos -->
    <div class="eo-section eo-section-grow">
      <div class="eo-section-title">
        <?php echo $langs->trans('EasyOcrExtractedData'); ?>
        <span class="eo-badge" id="eo-selection-count">0</span>
      </div>

      <div class="eo-field">
        <label class="eo-label"><?php echo $langs->trans('EasyOcrSupplier'); ?></label>
        <select id="eo-supplier" class="eo-select">
          <option value=""><?php echo $langs->trans('EasyOcrSelectSupplier'); ?></option>
        </select>
      </div>

      <div id="eo-selections-list" class="eo-selections-list">
        <div class="eo-empty-selections"><?php echo $langs->trans('EasyOcrNoSelectionsYet'); ?></div>
      </div>

      <!-- Checklist de completitud -->
      <div class="eo-readiness-panel">
        <div class="eo-readiness-row"><span id="eo-chk-supplier" class="eo-chk eo-chk-pending">○</span> <?php echo $langs->trans('EasyOcrReadinessSupplier'); ?></div>
        <div class="eo-readiness-row"><span id="eo-chk-factura" class="eo-chk eo-chk-pending">○</span> <?php echo $langs->trans('EasyOcrReadinessInvoice'); ?></div>
        <div class="eo-readiness-row"><span id="eo-chk-fecha" class="eo-chk eo-chk-pending">○</span> <?php echo $langs->trans('EasyOcrReadinessDate'); ?></div>
        <div class="eo-readiness-row"><span id="eo-chk-ht" class="eo-chk eo-chk-pending">○</span> <?php echo $langs->trans('EasyOcrReadinessHT'); ?></div>
        <div class="eo-readiness-row"><span id="eo-chk-ttc" class="eo-chk eo-chk-pending">○</span> <?php echo $langs->trans('EasyOcrReadinessTTC'); ?></div>
        <div class="eo-readiness-row eo-readiness-optional"><span id="eo-chk-iva" class="eo-chk eo-chk-optional">○</span> <?php echo $langs->trans('EasyOcrReadinessIVA'); ?> <small class="eo-optional-badge"><?php echo $langs->trans('EasyOcrOptional'); ?></small></div>
        <div class="eo-readiness-row eo-readiness-optional"><span id="eo-chk-desc" class="eo-chk eo-chk-optional">○</span> <?php echo $langs->trans('EasyOcrReadinessDesc'); ?> <small class="eo-optional-badge"><?php echo $langs->trans('EasyOcrOptional'); ?></small></div>
        <div class="eo-readiness-row eo-readiness-optional"><span id="eo-chk-cif" class="eo-chk eo-chk-optional">○</span> <?php echo $langs->trans('EasyOcrReadinessCIF'); ?> <small class="eo-optional-badge"><?php echo $langs->trans('EasyOcrOptional'); ?></small></div>
        <div class="eo-readiness-row eo-readiness-optional"><span id="eo-chk-duedate" class="eo-chk eo-chk-optional">○</span> <?php echo $langs->trans('EasyOcrReadinessDueDate'); ?> <small class="eo-optional-badge"><?php echo $langs->trans('EasyOcrOptional'); ?></small></div>
        <div class="eo-readiness-row eo-readiness-summary"><?php echo $langs->trans('EasyOcrCompleteness'); ?>: <span id="eo-readiness" class="eo-readiness">0/5</span></div>
      </div>
    </div>

  </div><!-- /.eo-sidebar-scroll -->

    <!-- Acciones -->
    <div class="eo-actions">
      <button class="eo-btn eo-btn-outline" onclick="EasyOcr.showSaveTemplate()" id="eo-btn-save-tpl" title="Ctrl+S"><?php echo $langs->trans('EasyOcrSaveTemplate'); ?></button>
      <button class="eo-btn eo-btn-outline" onclick="EasyOcr.editTemplate()" id="eo-btn-edit-tpl" style="display:none" title="Ctrl+S"><?php echo $langs->trans('EasyOcrEditTemplate'); ?></button>
      <button class="eo-btn eo-btn-success" onclick="EasyOcr.generateInvoice()" id="eo-btn-generate" disabled title="Ctrl+Enter"><?php echo $langs->trans('EasyOcrGenerateInvoice'); ?></button>
    </div>
    <div class="eo-help-bar">
      <?php
      $helpText = '<b>'.$langs->trans('EasyOcrHelpKeyboardTitle').'</b><br>';
      $helpText .= '<code>1-4</code> — '.$langs->trans('EasyOcrHelpSelectLabel').'<br>';
      $helpText .= '<code>Ctrl+Z</code> — '.$langs->trans('EasyOcrHelpUndo').'<br>';
      $helpText .= '<code>Ctrl+S</code> — '.$langs->trans('EasyOcrHelpSaveEditTpl').'<br>';
      $helpText .= '<code>Ctrl+Enter</code> — '.$langs->trans('EasyOcrHelpGenerateInvoice').'<br>';
      $helpText .= '<code>Esc</code> — '.$langs->trans('EasyOcrHelpCloseModal').'<br>';
      $helpText .= '<br><b>'.$langs->trans('EasyOcrHelpInteractionTitle').'</b><br>';
      $helpText .= '• '.$langs->trans('EasyOcrHelpDragPdf').'<br>';
      $helpText .= '• '.$langs->trans('EasyOcrHelpDrawRects').'<br>';
      $helpText .= '• '.$langs->trans('EasyOcrHelpDragSelections').'<br>';
      $helpText .= '• '.$langs->trans('EasyOcrHelpResizeCorners').'<br>';
      $helpText .= '• '.$langs->trans('EasyOcrHelpZoom');
      echo $form->textwithpicto($langs->trans('EasyOcrHelpAndShortcuts'), $helpText, 1, 'help', '', 0, 3);
      ?>
    </div>
  </div>
</div>

<!-- Modal guardar plantilla -->
<div id="eo-modal-template" class="eo-modal-overlay">
  <div class="eo-modal">
    <div class="eo-modal-header">
      <h4><?php echo $langs->trans('EasyOcrSaveAsTemplate'); ?></h4>
      <button class="eo-modal-close" onclick="EasyOcr.hideSaveTemplate()">✕</button>
    </div>
    <div class="eo-modal-body">
      <div class="eo-field">
        <label class="eo-label"><?php echo $langs->trans('EasyOcrTemplateName'); ?></label>
        <input id="eo-template-name" class="eo-input" type="text" placeholder="<?php echo $langs->trans('EasyOcrTemplateNamePlaceholder'); ?>">
      </div>
      <div class="eo-field">
        <label class="eo-label"><?php echo $langs->trans('EasyOcrAssociatedSupplier'); ?></label>
        <select id="eo-template-supplier" class="eo-select">
          <option value=""><?php echo $langs->trans('EasyOcrNoSupplierGeneric'); ?></option>
        </select>
        <small style="color:#888;font-size:11px;margin-top:4px;display:block"><?php echo $langs->trans('EasyOcrAutoSelectSupplierHint'); ?></small>
      </div>
      <?php if ($aiEnabled) { ?>
      <div class="eo-field">
        <label class="eo-label"><?php echo $langs->trans('EasyOcrCustomInstructions'); ?> <span class="eo-badge eo-badge-ai" style="font-size:9px;padding:1px 5px">AI</span></label>
        <textarea id="eo-template-instructions" class="eo-input" rows="3" placeholder="<?php echo $langs->trans('EasyOcrCustomInstructionsPlaceholder'); ?>"></textarea>
        <small style="color:#888;font-size:11px;margin-top:4px;display:block"><?php echo $langs->trans('EasyOcrCustomInstructionsSaveHint'); ?></small>
      </div>
      <?php } ?>
      <button class="eo-btn eo-btn-primary" onclick="EasyOcr.saveTemplate()" style="width:100%;margin-top:12px"><?php echo $langs->trans('EasyOcrSave'); ?></button>
    </div>
  </div>
</div>

<!-- Modal confirmación factura -->
<div id="eo-modal-confirm" class="eo-modal-overlay">
  <div class="eo-modal">
    <div class="eo-modal-header">
      <h4><?php echo $langs->trans('EasyOcrConfirmGenerateInvoice'); ?></h4>
      <button class="eo-modal-close" onclick="document.getElementById('eo-modal-confirm').style.display='none'">✕</button>
    </div>
    <div class="eo-modal-body">
      <p class="eo-confirm-intro"><?php echo $langs->trans('EasyOcrConfirmIntro'); ?></p>
      <div id="eo-confirm-body"></div>

      <!-- Opción de pago asociado -->
      <div class="eo-payment-section">
        <label class="eo-checkbox-label">
          <input type="checkbox" id="eo-create-payment" onchange="EasyOcr.togglePaymentOptions()">
          <?php echo $langs->trans('EasyOcrCreatePayment'); ?>
        </label>
        <div id="eo-payment-options" style="display:none">
          <div class="eo-field">
            <label class="eo-label"><?php echo $langs->trans('EasyOcrPaymentMode'); ?></label>
            <select id="eo-payment-type" class="eo-select">
              <option value=""><?php echo $langs->trans('EasyOcrSelectPaymentMode'); ?></option>
            </select>
          </div>
          <div class="eo-field">
            <label class="eo-label"><?php echo $langs->trans('EasyOcrBankAccount'); ?></label>
            <select id="eo-payment-bank" class="eo-select">
              <option value=""><?php echo $langs->trans('EasyOcrSelectBankAccount'); ?></option>
            </select>
          </div>
        </div>
      </div>

      <div class="eo-confirm-actions">
        <button class="eo-btn eo-btn-outline" onclick="document.getElementById('eo-modal-confirm').style.display='none'"><?php echo $langs->trans('EasyOcrCancel'); ?></button>
        <button class="eo-btn eo-btn-success" onclick="EasyOcr.confirmGenerateInvoice()"><?php echo $langs->trans('EasyOcrConfirmAndGenerate'); ?></button>
      </div>
    </div>
  </div>
</div>

<!-- Modal preview factura creada -->
<div id="eo-modal-invoice" class="eo-modal-overlay">
  <div class="eo-modal eo-modal-lg">
    <div class="eo-modal-header">
      <div class="eo-invoice-header-left">
        <div class="eo-invoice-header-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </div>
        <h4 id="eo-invoice-title"><?php echo $langs->trans('EasyOcrInvoiceCreated'); ?></h4>
      </div>
      <div class="eo-modal-header-actions">
        <a id="eo-invoice-link" href="#" target="_blank" class="eo-invoice-open-btn" title="<?php echo $langs->trans('EasyOcrOpenNewTab'); ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          <?php echo $langs->trans('EasyOcrOpenNewTab') ?: 'Abrir en pestaña'; ?>
        </a>
        <button class="eo-modal-close" onclick="EasyOcr.closeInvoicePreview()">✕</button>
      </div>
    </div>
    <div class="eo-modal-body eo-modal-body-iframe">
      <iframe id="eo-invoice-iframe" src="about:blank" frameborder="0"></iframe>
    </div>
  </div>
</div>

<!-- Modal AI OCR Result - Premium -->
<div id="eo-modal-ai" class="eo-modal-overlay">
  <div class="eo-modal eo-modal-ai">
    <div class="eo-modal-ai-header">
      <div class="eo-modal-ai-title-row">
        <div class="eo-modal-ai-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 014 4v1a2 2 0 012 2v1a2 2 0 01-2 2H8a2 2 0 01-2-2V9a2 2 0 012-2V6a4 4 0 014-4z"/><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 14v4"/></svg>
        </div>
        <div>
          <h4><?php echo $langs->trans('EasyOcrAIResult'); ?></h4>
          <div class="eo-modal-ai-meta">
            <span id="eo-ai-meta-confidence" class="eo-ai-meta-pill"></span>
            <span id="eo-ai-meta-time" class="eo-ai-meta-pill"></span>
            <span id="eo-ai-meta-tokens" class="eo-ai-meta-pill"></span>
            <span id="eo-ai-meta-pages" class="eo-ai-meta-pill"></span>
          </div>
        </div>
      </div>
      <div class="eo-ai-header-actions">
        <button class="eo-btn-payload" id="eo-btn-show-payload" onclick="EasyOcr.toggleAIPayload()" title="Show JSON payload">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
          JSON
        </button>
        <button class="eo-modal-close" onclick="EasyOcr.closeAIModal()">✕</button>
      </div>
    </div>

    <!-- Payload viewer -->
    <div id="eo-ai-payload-panel" class="eo-ai-payload-panel" style="display:none">
      <pre id="eo-ai-payload-content" class="eo-ai-payload-content"></pre>
    </div>

    <div class="eo-modal-ai-body">
      <!-- Sección Documento -->
      <div class="eo-ai-card">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-doc">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAIDocument'); ?></span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body" id="eo-ai-doc-fields"></div>
      </div>

      <!-- Sección Proveedor -->
      <div class="eo-ai-card" id="eo-ai-card-supplier">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-supplier">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAISupplier'); ?></span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body" id="eo-ai-supplier-fields"></div>
      </div>

      <!-- Sección Cliente -->
      <div class="eo-ai-card" id="eo-ai-card-customer">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-customer">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAICustomer'); ?></span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body" id="eo-ai-customer-fields"></div>
      </div>

      <!-- Sección Líneas de factura -->
      <div class="eo-ai-card">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-lines">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAILines'); ?></span>
          <span class="eo-ai-card-count" id="eo-ai-lines-count">0</span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body eo-ai-card-body-lines" id="eo-ai-lines-container">
          <div class="eo-ai-lines-toolbar">
            <button class="eo-btn-icon-sm" onclick="EasyOcr.aiAddLine()" title="<?php echo $langs->trans('EasyOcrAIAddLine'); ?>">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              <?php echo $langs->trans('EasyOcrAIAddLine'); ?>
            </button>
          </div>
          <div class="eo-ai-lines-table-wrap">
            <table class="eo-ai-table" id="eo-ai-lines-table">
              <thead>
                <tr>
                  <th class="eo-ai-th-code"><?php echo $langs->trans('EasyOcrAICode') ?: 'Código'; ?></th>
                  <th class="eo-ai-th-desc"><?php echo $langs->trans('EasyOcrAILineDesc'); ?></th>
                  <th class="eo-ai-th-type"><?php echo $langs->trans('EasyOcrAIType') ?: 'Tipo'; ?></th>
                  <th class="eo-ai-th-qty"><?php echo $langs->trans('EasyOcrAIQty'); ?></th>
                  <th class="eo-ai-th-price"><?php echo $langs->trans('EasyOcrAIPrice'); ?></th>
                  <th class="eo-ai-th-disc"><?php echo $langs->trans('EasyOcrAIDiscount') ?: 'Dto%'; ?></th>
                  <th class="eo-ai-th-tax"><?php echo $langs->trans('EasyOcrAITaxRate'); ?></th>
                  <th class="eo-ai-th-re"><?php echo $langs->trans('EasyOcrAIRE') ?: 'RE%'; ?></th>
                  <th class="eo-ai-th-irpf"><?php echo $langs->trans('EasyOcrAIIRPF') ?: 'IRPF%'; ?></th>
                  <th class="eo-ai-th-total"><?php echo $langs->trans('EasyOcrAITotal'); ?></th>
                  <th class="eo-ai-th-actions"></th>
                </tr>
              </thead>
              <tbody id="eo-ai-lines-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Sección Totales -->
      <div class="eo-ai-card">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-totals">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAITotals'); ?></span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body" id="eo-ai-totals-fields"></div>
      </div>

      <!-- Sección Pago -->
      <div class="eo-ai-card" id="eo-ai-card-payment">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-payment">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAIPayment'); ?></span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body" id="eo-ai-payment-fields"></div>
      </div>

      <!-- Sección Notas -->
      <div class="eo-ai-card" id="eo-ai-notes-card" style="display:none">
        <div class="eo-ai-card-header" onclick="this.parentElement.classList.toggle('collapsed')">
          <div class="eo-ai-card-icon eo-ai-icon-notes">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </div>
          <span class="eo-ai-card-title"><?php echo $langs->trans('EasyOcrAINotes'); ?></span>
          <svg class="eo-ai-card-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="eo-ai-card-body" id="eo-ai-notes-fields"></div>
      </div>
    </div>

    <div class="eo-modal-ai-footer">
      <div class="eo-ai-footer-options">
        <!-- Row 1: Invoice status + Journal -->
        <div class="eo-ai-footer-row">
          <div class="eo-ai-option-group">
            <span class="eo-ai-option-label"><?php echo $langs->trans('EasyOcrAIInvoiceStatus') ?: 'Estado'; ?></span>
            <span class="eo-ai-option-sep"></span>
            <label class="eo-radio-label eo-radio-sm">
              <input type="radio" name="eo-ai-invoice-status" value="validated" checked> <?php echo $langs->trans('EasyOcrAIValidated') ?: 'Validada'; ?>
            </label>
            <label class="eo-radio-label eo-radio-sm">
              <input type="radio" name="eo-ai-invoice-status" value="draft"> <?php echo $langs->trans('EasyOcrAIDraft') ?: 'Borrador'; ?>
            </label>
          </div>
          <div class="eo-ai-option-group">
            <span class="eo-ai-option-label"><?php echo $langs->trans('EasyOcrAIJournal') ?: 'Diario'; ?></span>
            <span class="eo-ai-option-sep"></span>
            <select id="eo-ai-journal" class="eo-select eo-select-sm">
              <option value=""><?php echo $langs->trans('EasyOcrAIJournalAuto') ?: '-- Automático --'; ?></option>
            </select>
          </div>
        </div>
        <!-- Row 2: Payment -->
        <div class="eo-ai-footer-row">
          <label class="eo-checkbox-label eo-checkbox-sm">
            <input type="checkbox" id="eo-ai-create-payment" onchange="EasyOcr.toggleAIPayment()">
            <?php echo $langs->trans('EasyOcrCreatePayment'); ?>
          </label>
          <div id="eo-ai-payment-options" class="eo-ai-payment-opts" style="display:none">
            <select id="eo-ai-payment-type" class="eo-select eo-select-sm">
              <option value=""><?php echo $langs->trans('EasyOcrSelectPaymentMode'); ?></option>
            </select>
            <select id="eo-ai-payment-bank" class="eo-select eo-select-sm">
              <option value=""><?php echo $langs->trans('EasyOcrSelectBankAccount'); ?></option>
            </select>
          </div>
        </div>
      </div>
      <div class="eo-ai-footer-btns">
        <button class="eo-btn eo-btn-outline" onclick="EasyOcr.closeAIModal()">
          <?php echo $langs->trans('EasyOcrCancel'); ?>
        </button>
        <button class="eo-btn eo-btn-success" onclick="EasyOcr.createAIInvoice()" id="eo-btn-ai-create">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo $langs->trans('EasyOcrAICreateInvoice'); ?>
        </button>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($subscriptionData)) { ?>
<script>
(function() {
  var eoQuotaAjaxUrl = '<?php echo dol_buildpath('/easyocr/ajax/ajax_easyocr.php', 1); ?>';
  var eoQuotaLabels = {
    pages: '<?php echo addslashes($langs->trans('EasyOcrPlanPages')); ?>',
    remaining: '<?php echo addslashes($langs->trans('EasyOcrQuotaRemaining')); ?>'
  };

  function eoFmtNum(n) {
    return Number(n).toLocaleString('es-ES', { maximumFractionDigits: 0 });
  }

  function eoRefreshQuota() {
    jQuery.ajax({
      url: eoQuotaAjaxUrl,
      type: 'POST',
      data: { action: 'getSubscriptionInfo' },
      dataType: 'json',
      success: function(res) {
        if (!res || res.status !== 'ok') return;

        // Compact header
        var compactText = document.getElementById('eo-quota-compact-text');
        if (compactText) compactText.innerHTML = '<strong>' + eoFmtNum(res.pages_used) + '</strong> / ' + eoFmtNum(res.pages_limit) + ' ' + eoQuotaLabels.pages;

        var remainEl = document.getElementById('eo-quota-remaining');
        if (remainEl) remainEl.textContent = eoFmtNum(res.pages_remaining) + ' ' + eoQuotaLabels.remaining;

        // Status icon
        var statusIcon = document.getElementById('eo-quota-status-icon');
        if (statusIcon) {
          statusIcon.className = 'eo-quota-status-icon eo-status-' + res.status_class;
          statusIcon.textContent = (res.status_class === 'ok') ? '✓' : '⚠️';
        }

        // Progress bar
        var progressFill = document.getElementById('eo-quota-progress-fill');
        if (progressFill) {
          progressFill.style.width = res.percentage + '%';
          progressFill.className = 'eo-quota-progress-fill eo-status-' + res.status_class;
        }
        var percentEl = document.getElementById('eo-quota-percentage');
        if (percentEl) percentEl.textContent = res.percentage + '%';

        // Stats grid values
        var pagesVal = document.getElementById('eo-quota-pages-val');
        if (pagesVal) pagesVal.textContent = eoFmtNum(res.pages_used) + ' / ' + eoFmtNum(res.pages_limit);

        var remainVal = document.getElementById('eo-quota-remaining-val');
        if (remainVal) {
          remainVal.textContent = eoFmtNum(res.pages_remaining);
          remainVal.className = 'eo-quota-stat-value' + (res.pages_remaining <= 0 ? ' eo-depleted' : '');
        }

        // Reset date
        var resetItem = document.getElementById('eo-quota-reset-item');
        var resetVal = document.getElementById('eo-quota-reset-val');
        if (resetItem && resetVal) {
          if (res.reset_date) { resetItem.style.display = ''; resetVal.textContent = res.reset_date; }
          else { resetItem.style.display = 'none'; }
        }

        // Wallet
        var walletItem = document.getElementById('eo-quota-wallet-item');
        var walletVal = document.getElementById('eo-quota-wallet-val');
        if (walletItem && walletVal) {
          if (res.has_wallet && res.wallet_balance > 0) { walletItem.style.display = ''; walletVal.textContent = eoFmtNum(res.wallet_balance); }
          else { walletItem.style.display = 'none'; }
        }

        // Plan name
        var planName = document.getElementById('eo-quota-plan-name');
        if (planName) planName.textContent = res.plan_name;

        // Free badge
        var freeBadge = document.getElementById('eo-quota-free-badge');
        if (freeBadge) freeBadge.style.display = res.is_free ? '' : 'none';

        // Alert
        var alertEl = document.getElementById('eo-quota-alert');
        var alertText = document.getElementById('eo-quota-alert-text');
        if (alertEl && alertText) {
          if (res.status_text) {
            alertEl.style.display = '';
            alertEl.className = 'eo-quota-alert eo-status-' + res.status_class;
            alertText.textContent = res.status_text;
          } else {
            alertEl.style.display = 'none';
          }
        }
      }
    });
  }

  // Poll every 5 seconds
  setInterval(eoRefreshQuota, 5000);
})();
</script>
<?php } ?>

<?php
llxFooter();
?>