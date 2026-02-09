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
?>

window.EasyOcrWorkerSrc = <?php echo json_encode(dol_buildpath('/custom/easyocr/js/pdf.worker.min.js', 1)); ?>;
window.EasyOcrLang = {
  labelDate: <?php echo json_encode($langs->trans('EasyOcrLabelDate')); ?>,
  labelInvoice: <?php echo json_encode($langs->trans('EasyOcrLabelInvoice')); ?>,
  labelHT: <?php echo json_encode($langs->trans('EasyOcrLabelHT')); ?>,
  labelTTC: <?php echo json_encode($langs->trans('EasyOcrLabelTTC')); ?>,
  labelIVA: <?php echo json_encode($langs->trans('EasyOcrLabelIVA')); ?>,
  labelDesc: <?php echo json_encode($langs->trans('EasyOcrLabelDesc')); ?>,
  labelCIF: <?php echo json_encode($langs->trans('EasyOcrLabelCIF')); ?>,
  labelDueDate: <?php echo json_encode($langs->trans('EasyOcrLabelDueDate')); ?>,
  nothingToUndo: <?php echo json_encode($langs->trans('EasyOcrNothingToUndo')); ?>,
  actionUndone: <?php echo json_encode($langs->trans('EasyOcrActionUndone')); ?>,
  errorLoadingPdf: <?php echo json_encode($langs->trans('EasyOcrErrorLoadingPdf')); ?>,
  templateDetected: <?php echo json_encode($langs->trans('EasyOcrTemplateDetected')); ?>,
  selectTemplateFirst: <?php echo json_encode($langs->trans('EasyOcrSelectTemplateFirst')); ?>,
  templateNoSelections: <?php echo json_encode($langs->trans('EasyOcrTemplateNoSelections')); ?>,
  errorLoadingTemplate: <?php echo json_encode($langs->trans('EasyOcrErrorLoadingTemplate')); ?>,
  enterTemplateName: <?php echo json_encode($langs->trans('EasyOcrEnterTemplateName')); ?>,
  templateSavedOk: <?php echo json_encode($langs->trans('EasyOcrTemplateSavedOk')); ?>,
  errorSavingTemplate: <?php echo json_encode($langs->trans('EasyOcrErrorSavingTemplate')); ?>,
  templateEditedOk: <?php echo json_encode($langs->trans('EasyOcrTemplateEditedOk')); ?>,
  errorEditingTemplate: <?php echo json_encode($langs->trans('EasyOcrErrorEditingTemplate')); ?>,
  completeAllFields: <?php echo json_encode($langs->trans('EasyOcrCompleteAllFields')); ?>,
  selectBankForPayment: <?php echo json_encode($langs->trans('EasyOcrSelectBankForPayment')); ?>,
  selectPaymentType: <?php echo json_encode($langs->trans('EasyOcrSelectPaymentType')); ?>,
  invoiceCreatedOk: <?php echo json_encode($langs->trans('EasyOcrInvoiceCreatedOk')); ?>,
  invoiceAlreadyExists: <?php echo json_encode($langs->trans('EasyOcrInvoiceAlreadyExists')); ?>,
  errorGeneratingInvoice: <?php echo json_encode($langs->trans('EasyOcrErrorGeneratingInvoice')); ?>,
  selectPdfFile: <?php echo json_encode($langs->trans('EasyOcrSelectPdfFile')); ?>,
  onlyPdfAccepted: <?php echo json_encode($langs->trans('EasyOcrOnlyPdfAccepted')); ?>,
  noMetadata: <?php echo json_encode($langs->trans('EasyOcrNoMetadata')); ?>,
  noRelevantMetadata: <?php echo json_encode($langs->trans('EasyOcrNoRelevantMetadata')); ?>,
  noSelectionsYet: <?php echo json_encode($langs->trans('EasyOcrNoSelectionsYet')); ?>,
  selectSupplier: <?php echo json_encode($langs->trans('EasyOcrSelectSupplier')); ?>,
  noTemplate: <?php echo json_encode($langs->trans('EasyOcrNoTemplate')); ?>,
  selectPaymentMode: <?php echo json_encode($langs->trans('EasyOcrSelectPaymentMode')); ?>,
  selectBankAccount: <?php echo json_encode($langs->trans('EasyOcrSelectBankAccount')); ?>,
  page: <?php echo json_encode($langs->trans('EasyOcrPage')); ?>,
  deleteSelection: <?php echo json_encode($langs->trans('EasyOcrDeleteSelection')); ?>,
  noSupplierGeneric: <?php echo json_encode($langs->trans('EasyOcrNoSupplierGeneric')); ?>,
  supplierLabel: <?php echo json_encode($langs->trans('EasyOcrSupplier')); ?>,
  invoiceNumber: <?php echo json_encode($langs->trans('EasyOcrInvoiceNumber')); ?>,
  dateLabel: <?php echo json_encode($langs->trans('EasyOcrDate')); ?>,
  taxableBase: <?php echo json_encode($langs->trans('EasyOcrTaxableBase')); ?>,
  totalTTC: <?php echo json_encode($langs->trans('EasyOcrReadinessTTC')); ?>,
  noFileSelected: <?php echo json_encode($langs->trans('EasyOcrNoFileSelected')); ?>,
  metaTitle: <?php echo json_encode($langs->trans('EasyOcrMetaTitle')); ?>,
  metaAuthor: <?php echo json_encode($langs->trans('EasyOcrMetaAuthor')); ?>,
  metaSubject: <?php echo json_encode($langs->trans('EasyOcrMetaSubject')); ?>,
  metaCreator: <?php echo json_encode($langs->trans('EasyOcrMetaCreator')); ?>,
  metaProducer: <?php echo json_encode($langs->trans('EasyOcrMetaProducer')); ?>,
  metaCreationDate: <?php echo json_encode($langs->trans('EasyOcrMetaCreationDate')); ?>,
  metaModDate: <?php echo json_encode($langs->trans('EasyOcrMetaModDate')); ?>,
  metaKeywords: <?php echo json_encode($langs->trans('EasyOcrMetaKeywords')); ?>,
  metaTrapped: <?php echo json_encode($langs->trans('EasyOcrMetaTrapped')); ?>,
  pdfVersion: <?php echo json_encode($langs->trans('EasyOcrPdfVersion')); ?>,
  xmpAuthor: <?php echo json_encode($langs->trans('EasyOcrXmpAuthor')); ?>,
  xmpDescription: <?php echo json_encode($langs->trans('EasyOcrXmpDescription')); ?>,
  xmpTitle: <?php echo json_encode($langs->trans('EasyOcrXmpTitle')); ?>,
  xmpSubject: <?php echo json_encode($langs->trans('EasyOcrXmpSubject')); ?>,
  invoiceCreatedWithRef: <?php echo json_encode($langs->trans('EasyOcrInvoiceCreatedWithRef')); ?>,
  supplierAutoDetected: <?php echo json_encode($langs->trans('EasyOcrSupplierAutoDetected')); ?>,
  noSupplierFoundByCIF: <?php echo json_encode($langs->trans('EasyOcrNoSupplierFoundByCIF')); ?>,
  dueDateLabel: <?php echo json_encode($langs->trans('EasyOcrDueDate')); ?>,
  descriptionLabel: <?php echo json_encode($langs->trans('EasyOcrDescription')); ?>
};
<?php

// Append static JavaScript
readfile(__DIR__.'/scripts.js');
