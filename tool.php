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

$form = new Form($db);

$arrayofjs = array(
	dol_buildpath('/custom/easyocr/js/pdf.min.js', 1),
	dol_buildpath('/custom/easyocr/libraries/notify.min.js', 1),
	dol_buildpath('/custom/easyocr/js/scripts.js', 1),
);
$arrayofcss = array(
	dol_buildpath('/custom/easyocr/css/easyocr.css', 1),
);

llxHeader("", "EasyOcr", '', '', 0, 0, $arrayofjs, $arrayofcss);
?>

<script>window.EasyOcrWorkerSrc = '<?php echo dol_buildpath('/custom/easyocr/js/pdf.worker.min.js', 1); ?>';</script>

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
        Importar PDF
      </label>
      <input type="file" id="pdfInput" accept=".pdf" style="display:none">
      <span id="eo-filename" class="eo-filename">Ningún archivo seleccionado</span>
      <div id="eo-zoom-controls" class="eo-zoom-controls" style="display:none">
        <button class="eo-zoom-btn" onclick="EasyOcr.zoomOut()" title="Alejar (-)">−</button>
        <span id="eo-zoom-label" class="eo-zoom-label">150%</span>
        <button class="eo-zoom-btn" onclick="EasyOcr.zoomIn()" title="Acercar (+)">+</button>
      </div>
      <span id="eo-page-indicator" class="eo-page-indicator" style="display:none"></span>
    </div>

    <div class="eo-canvas-area" id="eo-canvas-area">
      <div class="eo-empty-state" id="eo-empty-state">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <p>Importa un PDF para comenzar</p>
        <p class="eo-empty-hint">o arrastra y suelta un archivo aquí</p>
      </div>
      <div class="eo-drop-overlay" id="eo-drop-overlay">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Suelta el PDF aquí</span>
      </div>
      <div id="canvas-container"></div>
    </div>
  </div>

  <!-- Panel derecho: Controles -->
  <div class="eo-sidebar" id="eo-sidebar">

    <!-- Etiquetas -->
    <div class="eo-section">
      <div class="eo-section-title">Etiquetas <span class="eo-shortcut-hint">1-4</span></div>
      <p class="eo-hint">Selecciona una etiqueta → Dibuja un rectángulo sobre el PDF</p>
      <div id="eo-tags" class="eo-tags"></div>
    </div>

    <!-- Plantillas -->
    <div class="eo-section">
      <div class="eo-section-title">Plantillas</div>
      <div class="eo-template-row">
        <select id="eo-template-select" class="eo-select">
          <option value="">Sin plantilla</option>
        </select>
        <button class="eo-btn eo-btn-sm eo-btn-primary" onclick="EasyOcr.loadTemplate()">Aplicar</button>
        <button class="eo-btn eo-btn-sm eo-btn-secondary" id="eo-btn-clear-tpl" onclick="EasyOcr.clearTemplate()" style="display:none" title="Limpiar plantilla">✕</button>
      </div>
    </div>

    <!-- Metadatos PDF -->
    <div class="eo-section" id="eo-metadata-section" style="display:none">
      <div class="eo-section-title eo-collapsible" onclick="this.classList.toggle('eo-collapsed');this.nextElementSibling.classList.toggle('eo-hidden')">
        Metadatos PDF
        <svg class="eo-collapse-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </div>
      <div id="eo-metadata-content" class="eo-metadata-content"></div>
    </div>

    <!-- Datos extraídos -->
    <div class="eo-section eo-section-grow">
      <div class="eo-section-title">
        Datos extraídos
        <span class="eo-badge" id="eo-selection-count">0</span>
      </div>

      <div class="eo-field">
        <label class="eo-label">Proveedor</label>
        <select id="eo-supplier" class="eo-select">
          <option value="">Selecciona proveedor</option>
        </select>
      </div>

      <div id="eo-selections-list" class="eo-selections-list">
        <div class="eo-empty-selections">Sin selecciones aún</div>
      </div>

      <!-- Checklist de completitud -->
      <div class="eo-readiness-panel">
        <div class="eo-readiness-row"><span id="eo-chk-supplier" class="eo-chk eo-chk-pending">○</span> Proveedor</div>
        <div class="eo-readiness-row"><span id="eo-chk-factura" class="eo-chk eo-chk-pending">○</span> Factura</div>
        <div class="eo-readiness-row"><span id="eo-chk-fecha" class="eo-chk eo-chk-pending">○</span> Fecha</div>
        <div class="eo-readiness-row"><span id="eo-chk-ht" class="eo-chk eo-chk-pending">○</span> HT totales</div>
        <div class="eo-readiness-row"><span id="eo-chk-ttc" class="eo-chk eo-chk-pending">○</span> Precio total</div>
        <div class="eo-readiness-row eo-readiness-summary">Completitud: <span id="eo-readiness" class="eo-readiness">0/5</span></div>
      </div>
    </div>

    <!-- Acciones -->
    <div class="eo-actions">
      <button class="eo-btn eo-btn-outline" onclick="EasyOcr.showSaveTemplate()" id="eo-btn-save-tpl" title="Ctrl+S">Guardar plantilla</button>
      <button class="eo-btn eo-btn-outline" onclick="EasyOcr.editTemplate()" id="eo-btn-edit-tpl" style="display:none" title="Ctrl+S">Editar plantilla</button>
      <button class="eo-btn eo-btn-success" onclick="EasyOcr.generateInvoice()" id="eo-btn-generate" disabled title="Ctrl+Enter">Generar Factura</button>
    </div>
    <div class="eo-help-bar">
      <?php
      $helpText = '<b>Atajos de teclado</b><br>';
      $helpText .= '<code>1-4</code> — Seleccionar etiqueta<br>';
      $helpText .= '<code>Ctrl+Z</code> — Deshacer última acción<br>';
      $helpText .= '<code>Ctrl+S</code> — Guardar/Editar plantilla<br>';
      $helpText .= '<code>Ctrl+Enter</code> — Generar factura<br>';
      $helpText .= '<code>Esc</code> — Cerrar modal / Deseleccionar<br>';
      $helpText .= '<br><b>Interacción</b><br>';
      $helpText .= '• Arrastra un PDF al visor para cargarlo<br>';
      $helpText .= '• Dibuja rectángulos tras seleccionar etiqueta<br>';
      $helpText .= '• Arrastra selecciones para moverlas<br>';
      $helpText .= '• Usa las esquinas para redimensionar<br>';
      $helpText .= '• +/− para hacer zoom al PDF';
      echo $form->textwithpicto('Ayuda y atajos', $helpText, 1, 'help', '', 0, 3);
      ?>
    </div>
  </div>
</div>

<!-- Modal guardar plantilla -->
<div id="eo-modal-template" class="eo-modal-overlay">
  <div class="eo-modal">
    <div class="eo-modal-header">
      <h4>Guardar como plantilla</h4>
      <button class="eo-modal-close" onclick="EasyOcr.hideSaveTemplate()">✕</button>
    </div>
    <div class="eo-modal-body">
      <div class="eo-field">
        <label class="eo-label">Nombre de la plantilla</label>
        <input id="eo-template-name" class="eo-input" type="text" placeholder="Ej: Factura Proveedor X">
      </div>
      <div class="eo-field">
        <label class="eo-label">Proveedor asociado</label>
        <select id="eo-template-supplier" class="eo-select">
          <option value="">Sin proveedor (genérico)</option>
        </select>
        <small style="color:#888;font-size:11px;margin-top:4px;display:block">Al cargar esta plantilla se seleccionará automáticamente el proveedor</small>
      </div>
      <button class="eo-btn eo-btn-primary" onclick="EasyOcr.saveTemplate()" style="width:100%;margin-top:12px">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal confirmación factura -->
<div id="eo-modal-confirm" class="eo-modal-overlay">
  <div class="eo-modal">
    <div class="eo-modal-header">
      <h4>Confirmar generación de factura</h4>
      <button class="eo-modal-close" onclick="document.getElementById('eo-modal-confirm').style.display='none'">✕</button>
    </div>
    <div class="eo-modal-body">
      <p class="eo-confirm-intro">Se creará una factura de proveedor con los siguientes datos:</p>
      <div id="eo-confirm-body"></div>
      <div class="eo-confirm-actions">
        <button class="eo-btn eo-btn-outline" onclick="document.getElementById('eo-modal-confirm').style.display='none'">Cancelar</button>
        <button class="eo-btn eo-btn-success" onclick="EasyOcr.confirmGenerateInvoice()">Confirmar y Generar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal preview factura creada -->
<div id="eo-modal-invoice" class="eo-modal-overlay">
  <div class="eo-modal eo-modal-lg">
    <div class="eo-modal-header">
      <h4 id="eo-invoice-title">Factura creada</h4>
      <div class="eo-modal-header-actions">
        <a id="eo-invoice-link" href="#" target="_blank" class="eo-btn eo-btn-sm eo-btn-primary" title="Abrir en pestaña nueva">Abrir ↗</a>
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