<?php
/* Copyright (C) 2024-2026 EasySoft Tech S.L.
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

if (!defined('NOREQUIREUSER')) {
	define('NOREQUIREUSER', '1');
}
if (!defined('NOREQUIREDB')) {
	define('NOREQUIREDB', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

/**
 * \file    js/scripts.js.php
 * \ingroup easyocr
 * \brief   JavaScript file for module EasyOcr (translations + main script).
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/../main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/../main.inc.php";
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

// Define js type
header('Content-Type: application/javascript');
if (empty($dolibarr_nocache)) {
	header('Cache-Control: max-age=3600, public, must-revalidate');
} else {
	header('Cache-Control: no-cache');
}

// Load translations
$langs->load('easyocr@easyocr');

// Helper function to encode translations without HTML entities
function transJson($key) {
	global $langs;
	return json_encode($langs->transnoentitiesnoconv($key), JSON_UNESCAPED_UNICODE);
}
?>

window.EasyOcrWorkerSrc = <?php echo json_encode(dol_buildpath('/custom/easyocr/js/pdf.worker.min.js', 1)); ?>;
window.EasyOcrLang = {
  labelDate: <?php echo transJson('EasyOcrLabelDate'); ?>,
  labelInvoice: <?php echo transJson('EasyOcrLabelInvoice'); ?>,
  labelHT: <?php echo transJson('EasyOcrLabelHT'); ?>,
  labelTTC: <?php echo transJson('EasyOcrLabelTTC'); ?>,
  labelIVA: <?php echo transJson('EasyOcrLabelIVA'); ?>,
  labelDesc: <?php echo transJson('EasyOcrLabelDesc'); ?>,
  labelCIF: <?php echo transJson('EasyOcrLabelCIF'); ?>,
  labelDueDate: <?php echo transJson('EasyOcrLabelDueDate'); ?>,
  nothingToUndo: <?php echo transJson('EasyOcrNothingToUndo'); ?>,
  actionUndone: <?php echo transJson('EasyOcrActionUndone'); ?>,
  errorLoadingPdf: <?php echo transJson('EasyOcrErrorLoadingPdf'); ?>,
  templateDetected: <?php echo transJson('EasyOcrTemplateDetected'); ?>,
  selectTemplateFirst: <?php echo transJson('EasyOcrSelectTemplateFirst'); ?>,
  templateNoSelections: <?php echo transJson('EasyOcrTemplateNoSelections'); ?>,
  errorLoadingTemplate: <?php echo transJson('EasyOcrErrorLoadingTemplate'); ?>,
  enterTemplateName: <?php echo transJson('EasyOcrEnterTemplateName'); ?>,
  templateSavedOk: <?php echo transJson('EasyOcrTemplateSavedOk'); ?>,
  errorSavingTemplate: <?php echo transJson('EasyOcrErrorSavingTemplate'); ?>,
  templateEditedOk: <?php echo transJson('EasyOcrTemplateEditedOk'); ?>,
  errorEditingTemplate: <?php echo transJson('EasyOcrErrorEditingTemplate'); ?>,
  completeAllFields: <?php echo transJson('EasyOcrCompleteAllFields'); ?>,
  selectBankForPayment: <?php echo transJson('EasyOcrSelectBankForPayment'); ?>,
  selectPaymentType: <?php echo transJson('EasyOcrSelectPaymentType'); ?>,
  invoiceCreatedOk: <?php echo transJson('EasyOcrInvoiceCreatedOk'); ?>,
  invoiceAlreadyExists: <?php echo transJson('EasyOcrInvoiceAlreadyExists'); ?>,
  errorGeneratingInvoice: <?php echo transJson('EasyOcrErrorGeneratingInvoice'); ?>,
  selectPdfFile: <?php echo transJson('EasyOcrSelectPdfFile'); ?>,
  onlyPdfAccepted: <?php echo transJson('EasyOcrOnlyPdfAccepted'); ?>,
  noMetadata: <?php echo transJson('EasyOcrNoMetadata'); ?>,
  noRelevantMetadata: <?php echo transJson('EasyOcrNoRelevantMetadata'); ?>,
  noSelectionsYet: <?php echo transJson('EasyOcrNoSelectionsYet'); ?>,
  selectSupplier: <?php echo transJson('EasyOcrSelectSupplier'); ?>,
  noTemplate: <?php echo transJson('EasyOcrNoTemplate'); ?>,
  selectPaymentMode: <?php echo transJson('EasyOcrSelectPaymentMode'); ?>,
  selectBankAccount: <?php echo transJson('EasyOcrSelectBankAccount'); ?>,
  page: <?php echo transJson('EasyOcrPage'); ?>,
  deleteSelection: <?php echo transJson('EasyOcrDeleteSelection'); ?>,
  noSupplierGeneric: <?php echo transJson('EasyOcrNoSupplierGeneric'); ?>,
  supplierLabel: <?php echo transJson('EasyOcrSupplier'); ?>,
  invoiceNumber: <?php echo transJson('EasyOcrInvoiceNumber'); ?>,
  dateLabel: <?php echo transJson('EasyOcrDate'); ?>,
  taxableBase: <?php echo transJson('EasyOcrTaxableBase'); ?>,
  totalTTC: <?php echo transJson('EasyOcrReadinessTTC'); ?>,
  noFileSelected: <?php echo transJson('EasyOcrNoFileSelected'); ?>,
  metaTitle: <?php echo transJson('EasyOcrMetaTitle'); ?>,
  metaAuthor: <?php echo transJson('EasyOcrMetaAuthor'); ?>,
  metaSubject: <?php echo transJson('EasyOcrMetaSubject'); ?>,
  metaCreator: <?php echo transJson('EasyOcrMetaCreator'); ?>,
  metaProducer: <?php echo transJson('EasyOcrMetaProducer'); ?>,
  metaCreationDate: <?php echo transJson('EasyOcrMetaCreationDate'); ?>,
  metaModDate: <?php echo transJson('EasyOcrMetaModDate'); ?>,
  metaKeywords: <?php echo transJson('EasyOcrMetaKeywords'); ?>,
  metaTrapped: <?php echo transJson('EasyOcrMetaTrapped'); ?>,
  pdfVersion: <?php echo transJson('EasyOcrPdfVersion'); ?>,
  xmpAuthor: <?php echo transJson('EasyOcrXmpAuthor'); ?>,
  xmpDescription: <?php echo transJson('EasyOcrXmpDescription'); ?>,
  xmpTitle: <?php echo transJson('EasyOcrXmpTitle'); ?>,
  xmpSubject: <?php echo transJson('EasyOcrXmpSubject'); ?>,
  invoiceCreatedWithRef: <?php echo transJson('EasyOcrInvoiceCreatedWithRef'); ?>,
  supplierAutoDetected: <?php echo transJson('EasyOcrSupplierAutoDetected'); ?>,
  noSupplierFoundByCIF: <?php echo transJson('EasyOcrNoSupplierFoundByCIF'); ?>,
  dueDateLabel: <?php echo transJson('EasyOcrDueDate'); ?>,
  descriptionLabel: <?php echo transJson('EasyOcrDescription'); ?>,
  // AI OCR
  aiOcrSuccess: <?php echo transJson('EasyOcrAIOcrSuccess'); ?>,
  aiOcrError: <?php echo transJson('EasyOcrAIOcrError'); ?>,
  aiApplied: <?php echo transJson('EasyOcrAIApplied'); ?>,
  aiNoData: <?php echo transJson('EasyOcrAINoData'); ?>,
  aiTaxes: <?php echo transJson('EasyOcrAITaxes'); ?>,
  aiTaxBase: <?php echo transJson('EasyOcrAITaxBase'); ?>,
  aiTaxAmount: <?php echo transJson('EasyOcrAITaxAmount'); ?>,
  aiLines: <?php echo transJson('EasyOcrAILines'); ?>,
  aiQty: <?php echo transJson('EasyOcrAIQty'); ?>,
  aiPrice: <?php echo transJson('EasyOcrAIPrice'); ?>,
  aiTotal: <?php echo transJson('EasyOcrAITotal'); ?>,
  currency: <?php echo transJson('EasyOcrAICurrency'); ?>,
  importPdfFirst: <?php echo transJson('EasyOcrAIImportPdfFirst'); ?>,
  aiNotConfigured: <?php echo transJson('EasyOcrAINotConfigured'); ?>,
  // AI Modal Premium
  aiDocType: <?php echo transJson('EasyOcrAIDocType'); ?>,
  aiName: <?php echo transJson('EasyOcrAIName'); ?>,
  aiAddress: <?php echo transJson('EasyOcrAIAddress'); ?>,
  aiCity: <?php echo transJson('EasyOcrAICity'); ?>,
  aiPostalCode: <?php echo transJson('EasyOcrAIPostalCode'); ?>,
  aiCountry: <?php echo transJson('EasyOcrAICountry'); ?>,
  aiPhone: <?php echo transJson('EasyOcrAIPhone'); ?>,
  aiEmail: <?php echo transJson('EasyOcrAIEmail'); ?>,
  aiConfidence: <?php echo transJson('EasyOcrAIConfidence'); ?>,
  aiPages: <?php echo transJson('EasyOcrAIPages'); ?>,
  aiDiscount: <?php echo transJson('EasyOcrAIDiscount'); ?>,
  aiPayMethod: <?php echo transJson('EasyOcrAIPayMethod'); ?>,
  aiPayStatus: <?php echo transJson('EasyOcrAIPayStatus'); ?>,
  aiPayBank: <?php echo transJson('EasyOcrAIPayBank'); ?>,
  aiPayRef: <?php echo transJson('EasyOcrAIPayRef'); ?>,
  aiNoLines: <?php echo transJson('EasyOcrAINoLines'); ?>,
  aiCreateInvoice: <?php echo transJson('EasyOcrAICreateInvoice'); ?>,
  aiProcessing: <?php echo transJson('EasyOcrAIProcessing'); ?>,
  aiStarting: <?php echo transJson('EasyOcrAIStarting'); ?>,
  aiMissingInvoiceNum: <?php echo transJson('EasyOcrAIMissingInvoiceNum'); ?>,
  aiMissingDate: <?php echo transJson('EasyOcrAIMissingDate'); ?>,
  aiMissingTotals: <?php echo transJson('EasyOcrAIMissingTotals'); ?>,
  aiSupplierNotFound: <?php echo transJson('EasyOcrAISupplierNotFound'); ?>,
  aiSupplierRequired: <?php echo transJson('EasyOcrAISupplierRequired'); ?>,
  aiSupplierCreated: <?php echo transJson('EasyOcrAISupplierCreated'); ?>
};
<?php

// Append static JavaScript
readfile(__DIR__.'/scripts.js');
