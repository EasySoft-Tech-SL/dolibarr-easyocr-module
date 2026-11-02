<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
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

/**
 * \file       admin/setup.php
 * \ingroup    easyocr
 * \brief      EasyOcr module setup page
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $db, $langs, $user, $conf;

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/easyocr.lib.php';
require_once __DIR__.'/../lib/easyocr_ai.class.php';
// Webhook helpers (token, URL): the receiver stops right after its function definitions
if (!defined('EASYOCR_WEBHOOK_LIB_ONLY')) {
	define('EASYOCR_WEBHOOK_LIB_ONLY', 1);
}
require_once __DIR__.'/../webhook_batch.php';

// Translations
$langs->loadLangs(array('errors', 'admin', 'easyocr@easyocr'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	// General settings
	$res = dolibarr_set_const($db, 'EASYOCR_INVOICE_DRAFT', GETPOST('EASYOCR_INVOICE_DRAFT', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_AI_AUTOCREATE_PRODUCT', GETPOST('EASYOCR_AI_AUTOCREATE_PRODUCT', 'int'), 'chaine', 0, '', $conf->entity);
	$res = dolibarr_set_const($db, 'EASYOCR_ALLOW_SELF_SUPPLIER', GETPOST('EASYOCR_ALLOW_SELF_SUPPLIER', 'int'), 'chaine', 0, '', $conf->entity);
	$res = dolibarr_set_const($db, 'EASYOCR_ALLOW_FOREIGN_CUSTOMER', GETPOST('EASYOCR_ALLOW_FOREIGN_CUSTOMER', 'int'), 'chaine', 0, '', $conf->entity);
	$res = dolibarr_set_const($db, 'EASYOCR_AI_RECEIVER_CONTEXT', GETPOST('EASYOCR_AI_RECEIVER_CONTEXT', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	// Duplicate-document guard
	$res = dolibarr_set_const($db, 'EASYOCR_DUPLICATE_CHECK', GETPOST('EASYOCR_DUPLICATE_CHECK', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_DUPLICATE_WINDOW_DAYS', max(0, GETPOST('EASYOCR_DUPLICATE_WINDOW_DAYS', 'int')), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	// Webhook auto-payment settings
	$res = dolibarr_set_const($db, 'EASYOCR_WEBHOOK_MARK_PAID', GETPOST('EASYOCR_WEBHOOK_MARK_PAID', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_WEBHOOK_BANK_ID', GETPOST('EASYOCR_WEBHOOK_BANK_ID', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_WEBHOOK_PAYMENT_TYPE', GETPOST('EASYOCR_WEBHOOK_PAYMENT_TYPE', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	// Employee expense (mobile scan) settings
	$res = dolibarr_set_const($db, 'EASYOCR_EXPENSE_TARGET', GETPOST('EASYOCR_EXPENSE_TARGET', 'aZ09'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_EXPENSE_ALLOW_VALIDATE', GETPOST('EASYOCR_EXPENSE_ALLOW_VALIDATE', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	// Various-payment target settings (bank account + payment mode + accounting code)
	$res = dolibarr_set_const($db, 'EASYOCR_EXPENSE_VARIOUS_BANK_ID', GETPOST('EASYOCR_EXPENSE_VARIOUS_BANK_ID', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE', GETPOST('EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_EXPENSE_VARIOUS_ACCOUNT', GETPOST('EASYOCR_EXPENSE_VARIOUS_ACCOUNT', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	// AI OCR settings
	$res = dolibarr_set_const($db, 'EASYOCR_AI_ENABLED', GETPOST('EASYOCR_AI_ENABLED', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	// The API key travels in every request: only https, or plain http towards this machine
	$aiUrl = trim(GETPOST('EASYOCR_AI_URL', 'alpha'));
	if ($aiUrl === '') {
		$aiUrl = EasyOcrAI::DEFAULT_URL;
	}
	if (EasyOcrAI::isAllowedServiceUrl($aiUrl)) {
		$res = dolibarr_set_const($db, 'EASYOCR_AI_URL', rtrim($aiUrl, '/'), 'chaine', 0, '', $conf->entity);
		if (!($res > 0)) $error++;
	} else {
		setEventMessages($langs->trans('EasyOcrAIUrlMustBeHttps'), null, 'errors');
		$error++;
	}

	$res = dolibarr_set_const($db, 'EASYOCR_AI_APIKEY', GETPOST('EASYOCR_AI_APIKEY', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	$res = dolibarr_set_const($db, 'EASYOCR_AI_TIMEOUT', GETPOST('EASYOCR_AI_TIMEOUT', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($res > 0)) $error++;

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Regenerate the webhook token (confirmed with formconfirm; the CSRF token is checked here
// too, whatever MAIN_SECURITY_CSRF_WITH_TOKEN says)
if ($action == 'confirm_regentoken' && $confirm == 'yes') {
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden();
	}
	if (easyocrWebhookRegenerateToken($db, $conf->entity) !== '') {
		setEventMessages($langs->trans('EasyOcrWebhookTokenRegenerated'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#webhook');
	exit;
}

// Test the connection with the saved URL and key (POST: main.inc.php checks the CSRF token)
if ($action == 'testconnection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$check = EasyOcrAI::checkAccount(getDolGlobalString('EASYOCR_AI_URL', EasyOcrAI::DEFAULT_URL), getDolGlobalString('EASYOCR_AI_APIKEY'), 15);
	if ($check['code'] === 'ok') {
		if ($check['pages'] !== null) {
			setEventMessages($langs->trans('EasyOcrTestConnectionOk', (string) $check['pages']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('EasyOcrTestConnectionOkNoPages'), null, 'mesgs');
		}
	} else {
		$checkKeys = array(
			'invalid_key'   => 'EasyOcrTestConnectionInvalidKey',
			'no_connection' => 'EasyOcrTestConnectionNoConnection',
			'service_down'  => 'EasyOcrTestConnectionServiceDown',
		);
		setEventMessages($langs->trans(isset($checkKeys[$check['code']]) ? $checkKeys[$check['code']] : 'EasyOcrTestConnectionError'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#aiconfig');
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('EasyOcrSetup');
$help_url = '';

llxHeader('', $title, $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = easyocr_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('EasyOcrSetup'), -1, 'easyocr@easyocr');

if ($action == 'regentoken') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'],
		$langs->trans('EasyOcrWebhookRegenerateTokenTitle'),
		$langs->trans('EasyOcrWebhookRegenerateTokenConfirm'),
		'confirm_regentoken',
		'',
		0,
		1
	);
}

// --- General info ---
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td>'.$langs->trans("EasyOcrConfigurationOptions").'</td>';
print '<td class="center">'.$langs->trans("Status").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrModuleDescription").'</td>';
print '<td class="center">';
print $langs->trans("EasyOcrModuleActiveInfo");
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// --- General configuration form ---
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("EasyOcrGeneralConfig").'</td>';
print '</tr>';

// Invoice as draft
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrInvoiceDraft").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_INVOICE_DRAFT', !empty($conf->global->EASYOCR_INVOICE_DRAFT) ? $conf->global->EASYOCR_INVOICE_DRAFT : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrInvoiceDraftDesc").'</span>';
print '</td>';
print '</tr>';

// Auto-create products from supplier reference
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAutoCreateProduct").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_AI_AUTOCREATE_PRODUCT', !empty($conf->global->EASYOCR_AI_AUTOCREATE_PRODUCT) ? $conf->global->EASYOCR_AI_AUTOCREATE_PRODUCT : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrAutoCreateProductDesc").'</span>';
print '</td>';
print '</tr>';

// Send our own identity to the AI so it does not return us as the supplier
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrReceiverContext").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_AI_RECEIVER_CONTEXT', !empty($conf->global->EASYOCR_AI_RECEIVER_CONTEXT) ? 1 : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrReceiverContextDesc").'</span>';
print '</td>';
print '</tr>';

// Allow the extracted supplier to be our own company (escape hatch for the guard)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAllowSelfSupplier").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_ALLOW_SELF_SUPPLIER', !empty($conf->global->EASYOCR_ALLOW_SELF_SUPPLIER) ? $conf->global->EASYOCR_ALLOW_SELF_SUPPLIER : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrAllowSelfSupplierDesc").'</span>';
print '</td>';
print '</tr>';

// Allow invoices addressed to another company (escape hatch for the foreign-customer guard)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAllowForeignCustomer").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_ALLOW_FOREIGN_CUSTOMER', !empty($conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER) ? $conf->global->EASYOCR_ALLOW_FOREIGN_CUSTOMER : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrAllowForeignCustomerDesc").'</span>';
print '</td>';
print '</tr>';

// Duplicate-document guard: skip files already sent to the AI service
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrDuplicateCheck").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_DUPLICATE_CHECK', isset($conf->global->EASYOCR_DUPLICATE_CHECK) ? (empty($conf->global->EASYOCR_DUPLICATE_CHECK) ? 0 : 1) : 1, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrDuplicateCheckDesc").'</span>';
print '</td>';
print '</tr>';

// How far back the duplicate guard looks (0 = forever)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrDuplicateWindow").'</td>';
print '<td>';
print '<input type="number" min="0" step="1" class="flat width75" name="EASYOCR_DUPLICATE_WINDOW_DAYS" value="'.(int) (!empty($conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS) ? $conf->global->EASYOCR_DUPLICATE_WINDOW_DAYS : 0).'"> '.$langs->trans("Days");
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrDuplicateWindowDesc").'</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// --- Webhook auto-payment configuration ---
print '<div class="div-table-responsive-no-min" id="webhook">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("EasyOcrWebhookPaymentConfig").'</td>';
print '</tr>';

// Webhook address (with this entity's token) and its regeneration
$eoWebhookUrl = easyocrWebhookBuildUrl($db, true);
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrWebhookUrl").'</td>';
print '<td>';
print '<input type="text" id="eo-webhook-url" readonly class="minwidth400 quatrevingtpercent" value="'.dol_escape_htmltag($eoWebhookUrl).'" onclick="this.select();">';
print ' <a class="butActionDelete smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=regentoken&token='.newToken().'">'.$langs->trans("EasyOcrWebhookRegenerateToken").'</a>';
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrWebhookUrlDesc").'</span>';
$eoLegacyUntil = (int) getDolGlobalString('EASYOCR_WEBHOOK_LEGACY_UNTIL');
if ($eoLegacyUntil > 0) {
	$eoLegacyKey = ($eoLegacyUntil > dol_now()) ? 'EasyOcrWebhookLegacyUntil' : 'EasyOcrWebhookLegacyExpired';
	print '<br>'.img_warning().' <span class="opacitymedium small">'.$langs->trans($eoLegacyKey, dol_print_date($eoLegacyUntil, 'dayhour')).'</span>';
}
print '</td>';
print '</tr>';

// Mark invoices created via webhook as paid
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrWebhookMarkPaid").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_WEBHOOK_MARK_PAID', !empty($conf->global->EASYOCR_WEBHOOK_MARK_PAID) ? $conf->global->EASYOCR_WEBHOOK_MARK_PAID : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrWebhookMarkPaidDesc").'</span>';
print '</td>';
print '</tr>';

// Bank account for the auto-payment
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrWebhookBankAccount").'</td>';
print '<td>';
print $form->select_comptes(!empty($conf->global->EASYOCR_WEBHOOK_BANK_ID) ? $conf->global->EASYOCR_WEBHOOK_BANK_ID : '', 'EASYOCR_WEBHOOK_BANK_ID', 0, '', 1, '', 0, '', 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrWebhookBankAccountDesc").'</span>';
print '</td>';
print '</tr>';

// Payment method for the auto-payment
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrWebhookPaymentType").'</td>';
print '<td>';
print $form->select_types_paiements(!empty($conf->global->EASYOCR_WEBHOOK_PAYMENT_TYPE) ? $conf->global->EASYOCR_WEBHOOK_PAYMENT_TYPE : '', 'EASYOCR_WEBHOOK_PAYMENT_TYPE', '', 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrWebhookPaymentTypeDesc").'</span>';
// A cash account only takes cash: warn before the bank refuses every payment.
// Without a method set, the module pays with id 6 (card).
$whBankCfg = !empty($conf->global->EASYOCR_WEBHOOK_BANK_ID) ? (int) $conf->global->EASYOCR_WEBHOOK_BANK_ID : 0;
if ($whBankCfg > 0) {
	$whTypeCfg = !empty($conf->global->EASYOCR_WEBHOOK_PAYMENT_TYPE) ? (int) $conf->global->EASYOCR_WEBHOOK_PAYMENT_TYPE : 6;
	$resAcc = $db->query("SELECT courant FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".$whBankCfg);
	$objAcc = $resAcc ? $db->fetch_object($resAcc) : null;
	$resTyp = $db->query("SELECT code FROM ".MAIN_DB_PREFIX."c_paiement WHERE id = ".$whTypeCfg);
	$objTyp = $resTyp ? $db->fetch_object($resTyp) : null;
	if ($objAcc && easyocrPaymentConfigRefused($objAcc->courant, $objTyp ? $objTyp->code : '')) {
		print '<br>'.img_warning().' <span class="warning">'.$langs->trans("EasyOcrWebhookCashAccountWarning").'</span>';
	}
}
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// --- Employee expense (mobile scan) configuration ---
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("EasyOcrExpenseConfig").'</td>';
print '</tr>';

// Target object for scanned employee expenses
$expenseTarget = !empty($conf->global->EASYOCR_EXPENSE_TARGET) ? $conf->global->EASYOCR_EXPENSE_TARGET : 'expensereport';
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrExpenseTarget").'</td>';
print '<td>';
$expenseTargets = array(
	'expensereport'    => $langs->trans("EasyOcrExpenseTargetExpense"),
	'supplier_invoice' => $langs->trans("EasyOcrExpenseTargetInvoice"),
	'various_payment'  => $langs->trans("EasyOcrExpenseTargetVarious"),
);
print $form->selectarray('EASYOCR_EXPENSE_TARGET', $expenseTargets, $expenseTarget, 0);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrExpenseTargetDesc").'</span>';
if ($expenseTarget == 'expensereport' && empty($conf->expensereport->enabled)) {
	print '<br><span class="error">'.$langs->trans("EasyOcrExpenseModuleDisabledWarn").'</span>';
}
print '</td>';
print '</tr>';

// Allow the employee to validate the expense from mobile
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrExpenseAllowValidate").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_EXPENSE_ALLOW_VALIDATE', !empty($conf->global->EASYOCR_EXPENSE_ALLOW_VALIDATE) ? $conf->global->EASYOCR_EXPENSE_ALLOW_VALIDATE : 0, 1);
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrExpenseAllowValidateDesc").'</span>';
print '</td>';
print '</tr>';

// ── Various-payment target settings (only used when diana = Pago diverso) ──
$vBank = !empty($conf->global->EASYOCR_EXPENSE_VARIOUS_BANK_ID) ? $conf->global->EASYOCR_EXPENSE_VARIOUS_BANK_ID : '';
$vPay  = !empty($conf->global->EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE) ? $conf->global->EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE : '';
$vAcct = !empty($conf->global->EASYOCR_EXPENSE_VARIOUS_ACCOUNT) ? $conf->global->EASYOCR_EXPENSE_VARIOUS_ACCOUNT : '';

print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium small">'.$langs->trans("EasyOcrExpenseVariousHint").'</span></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrExpenseVariousBank").'</td>';
print '<td>';
print $form->select_comptes($vBank, 'EASYOCR_EXPENSE_VARIOUS_BANK_ID', 0, '', 1, '', 0, '', 1);
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrExpenseVariousPaymentType").'</td>';
print '<td>';
print $form->select_types_paiements($vPay, 'EASYOCR_EXPENSE_VARIOUS_PAYMENT_TYPE', '', 0, 1);
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrExpenseVariousAccount").'</td>';
print '<td>';
print '<input type="text" name="EASYOCR_EXPENSE_VARIOUS_ACCOUNT" class="width150" value="'.dol_escape_htmltag($vAcct).'" placeholder="629, 600...">';
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrExpenseVariousAccountDesc").'</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';

// --- AI OCR Configuration ---

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("EasyOcrAIConfiguration").'</td>';
print '</tr>';

// Enable AI
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAIEnabled").'</td>';
print '<td>';
print $form->selectyesno('EASYOCR_AI_ENABLED', !empty($conf->global->EASYOCR_AI_ENABLED) ? $conf->global->EASYOCR_AI_ENABLED : 0, 1);
print '</td>';
print '</tr>';

// AI URL
print '<tr class="oddeven" id="aiconfig">';
print '<td>'.$langs->trans("EasyOcrAIUrl").'</td>';
print '<td>';
print '<input type="url" name="EASYOCR_AI_URL" class="minwidth400" value="'.dol_escape_htmltag(getDolGlobalString('EASYOCR_AI_URL', EasyOcrAI::DEFAULT_URL)).'">';
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrAIUrlDesc").'</span>';
print '</td>';
print '</tr>';

// API Key
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAIApiKey").'</td>';
print '<td>';
print '<input type="password" name="EASYOCR_AI_APIKEY" class="minwidth400" value="'.dol_escape_htmltag(!empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '').'" autocomplete="off">';
print '</td>';
print '</tr>';

// Timeout
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrAITimeout").'</td>';
print '<td>';
print '<input type="number" name="EASYOCR_AI_TIMEOUT" class="width100" min="10" max="600" value="'.dol_escape_htmltag(!empty($conf->global->EASYOCR_AI_TIMEOUT) ? $conf->global->EASYOCR_AI_TIMEOUT : '120').'">';
print ' <span class="opacitymedium">'.$langs->trans("Seconds").'</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Connection test with the saved URL and key (own form: forms cannot be nested)
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" class="center">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testconnection">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans("EasyOcrTestConnection")).'">';
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrTestConnectionDesc").'</span>';
print '</form>';

print '<br>';

// Information box
print '<div class="info">';
print '<strong>'.$langs->trans("EasyOcrSetupInfo").'</strong><br>';
print $langs->trans("EasyOcrSetupInfoDesc");
print '</div>';

print '<br>';

// --- Cloud service info ---
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="2"><span class="fas fa-cloud" style="margin-right:6px"></span>'.$langs->trans("EasyOcrCloudServiceTitle").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrCloudServiceWebsite").'</td>';
print '<td>';
print '<a href="https://easyocr.es" target="_blank" rel="noopener noreferrer">';
print '<span class="fas fa-external-link-alt" style="margin-right:4px"></span>https://easyocr.es/';
print '</a>';
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrCloudServiceWebsiteDesc").'</span>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("EasyOcrCloudServicePanel").'</td>';
print '<td>';
print '<a href="https://app.easyocr.es" target="_blank" rel="noopener noreferrer">';
print '<span class="fas fa-external-link-alt" style="margin-right:4px"></span>https://app.easyocr.es/';
print '</a>';
print '<br><span class="opacitymedium small">'.$langs->trans("EasyOcrCloudServicePanelDesc").'</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
