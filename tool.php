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

// Security check - require write permission to process invoices
if (!$user->rights->easyocr->write) {
    accessforbidden();
}

$form = new Form($db);
$langs->load('easyocr@easyocr');

$arrayofjs = array(
	dol_buildpath('/custom/easyocr/js/pdf.min.js', 1),
	dol_buildpath('/custom/easyocr/libraries/notify.min.js', 1),
	dol_buildpath('/custom/easyocr/js/scripts.js.php', 1),
);
$arrayofcss = array(
	dol_buildpath('/custom/easyocr/css/easyocr.css', 1),
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

    <!-- Banner suscripción IA -->
    <div class="eo-promo-banner">
      <div class="eo-promo-badge"><?php echo $langs->trans('EasyOcrPromoBadge'); ?></div>
      <div class="eo-promo-text">
        <strong><?php echo $langs->trans('EasyOcrPromoTitle'); ?></strong>
        <span><?php echo $langs->trans('EasyOcrPromoText'); ?></span>
      </div>
    </div>

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
      <h4 id="eo-invoice-title"><?php echo $langs->trans('EasyOcrInvoiceCreated'); ?></h4>
      <div class="eo-modal-header-actions">
        <a id="eo-invoice-link" href="#" target="_blank" class="eo-btn eo-btn-sm eo-btn-primary" title="<?php echo $langs->trans('EasyOcrOpenNewTab'); ?>"><?php echo $langs->trans('EasyOcrOpen'); ?></a>
        <button class="eo-modal-close" onclick="EasyOcr.closeInvoicePreview()">✕</button>
      </div>
    </div>
    <div class="eo-modal-body eo-modal-body-iframe">
      <iframe id="eo-invoice-iframe" src="about:blank" frameborder="0"></iframe>
    </div>
  </div>
</div>

<?php
llxFooter();
?>