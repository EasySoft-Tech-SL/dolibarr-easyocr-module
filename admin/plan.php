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
 * \file       admin/plan.php
 * \ingroup    easyocr
 * \brief      EasyOcr active service plan page
 */

// Load Dolibarr environment
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
require_once __DIR__.'/../lib/easyocr_autoload.php';

// Translations
$langs->loadLangs(array('errors', 'admin', 'easyocr@easyocr'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

$backtopage = GETPOST('backtopage', 'alpha');

// Get configuration
$apiKey = !empty($conf->global->EASYOCR_AI_APIKEY) ? $conf->global->EASYOCR_AI_APIKEY : '';
$apiUrl = !empty($conf->global->EASYOCR_AI_URL) ? $conf->global->EASYOCR_AI_URL : 'https://app.easyocr.es';
$apiEnabled = !empty($conf->global->EASYOCR_AI_ENABLED) ? $conf->global->EASYOCR_AI_ENABLED : 0;

$accountData = null;
$error = '';

// Try to fetch account data if API key is configured
if (!empty($apiKey) && $apiEnabled) {
	try {
		$client   = new \EasySoft\EasyOCR\EasyOCRClient($apiKey, ['base_url' => $apiUrl]);
		$response = $client->getHttpClient()->get('account/me');
		$rawBody  = (string) $response->getBody();
		// Strip UTF-8 BOM (\xEF\xBB\xBF) emitted by the server — json_decode fails with it
		$rawBody  = ltrim($rawBody, "\xEF\xBB\xBF");
		$accountData = json_decode($rawBody, true);
		if ($accountData === null) {
			$error = $langs->trans('EasyOcrPlanErrorAPI') . ': JSON inválido — ' . json_last_error_msg();
		}
	} catch (\GuzzleHttp\Exception\ClientException $e) {
		$statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
		if ($statusCode === 401 || $statusCode === 403) {
			$error = $langs->trans('EasyOcrPlanErrorAuth') . ': ' . $e->getMessage();
		} else {
			$error = $langs->trans('EasyOcrPlanErrorAPI') . ': ' . $e->getMessage();
		}
	} catch (\Exception $e) {
		$error = $langs->trans('Error') . ': ' . $e->getMessage();
	}
}

/**
 * Helper function to render a progress bar
 */
function renderProgressBar($used, $limit, $showPercentage = true, $height = 20) {
	if ($limit <= 0) {
		return '<em>-</em>';
	}
	$percentage = round(($used / $limit) * 100, 1);
	$displayPercentage = min($percentage, 100);
	$barColor = $percentage < 75 ? '#27ae60' : ($percentage < 100 ? '#f39c12' : '#e74c3c');
	
	$html = '<div style="margin-top: 5px;">';
	$html .= '<div style="width: 100%; background-color: #f0f0f0; border-radius: 3px; height: '.$height.'px; overflow: hidden;">';
	$html .= '<div style="width: '.$displayPercentage.'%; background-color: '.$barColor.'; border-radius: 3px; height: '.$height.'px; text-align: center; color: white; line-height: '.$height.'px; font-size: 11px; transition: width 0.3s ease;">';
	if ($showPercentage && $displayPercentage > 10) {
		$html .= $percentage.'%';
	}
	$html .= '</div></div></div>';
	return $html;
}

/**
 * Helper function to render yes/no with icon
 */
function renderYesNo($value, $langs) {
	if ($value) {
		return '<span class="fas fa-check" style="color: #27ae60;"></span> '.$langs->trans("Yes");
	} else {
		return '<span class="fas fa-times" style="color: #e74c3c;"></span> '.$langs->trans("No");
	}
}

/**
 * Helper function to render a status badge
 */
function renderStatusBadge($status, $langs) {
	$badges = array(
		'active' => array('class' => 'badge-status4', 'label' => 'EasyOcrPlanStatusActive'),
		'inactive' => array('class' => 'badge-status8', 'label' => 'EasyOcrPlanStatusInactive'),
		'trial' => array('class' => 'badge-status1', 'label' => 'EasyOcrPlanStatusTrial'),
		'cancelled' => array('class' => 'badge-status8', 'label' => 'EasyOcrPlanStatusCancelled'),
		'expired' => array('class' => 'badge-status8', 'label' => 'EasyOcrPlanStatusExpired'),
		'live' => array('class' => 'badge-status4', 'label' => 'EasyOcrPlanEnvLive'),
		'sandbox' => array('class' => 'badge-status1', 'label' => 'EasyOcrPlanEnvSandbox'),
	);
	
	$badge = isset($badges[$status]) ? $badges[$status] : array('class' => 'badge-status0', 'label' => ucfirst($status));
	return '<span class="badge '.$badge['class'].' badge-status">'.$langs->trans($badge['label']).'</span>';
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('EasyOcrPlan');
$help_url = '';

llxHeader('', $title, $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = easyocr_admin_prepare_head();
print dol_get_fiche_head($head, 'plan', $langs->trans("Module402020Name"), 0, 'easyocr@easyocr');

print '<div class="fichecenter">';

// Check if API is configured
if (empty($apiKey) || !$apiEnabled) {
	print '<div class="warning">';
	print '<span class="fas fa-exclamation-triangle"></span> ';
	print $langs->trans('EasyOcrPlanNotConfigured');
	print '<br><br>';
	print '<a class="butAction" href="'.dol_buildpath('/easyocr/admin/setup.php', 1).'">';
	print $langs->trans('EasyOcrPlanGoToSetup').'</a>';
	print '</div>';
} elseif (!empty($error)) {
	// Show error message
	print '<div class="error">';
	print '<span class="fas fa-exclamation-circle"></span> ';
	print $error;
	print '</div>';
} elseif (!empty($accountData)) {
	// Extract all data sections
	$data = $accountData['data'] ?? [];
	$account = $data['account'] ?? [];
	$subscription = $data['subscription'] ?? [];
	$plan = $data['plan'] ?? [];
	$limits = $data['limits'] ?? [];
	$features = $data['features'] ?? [];
	$quota = $data['quota'] ?? [];
	$apiKeyInfo = $data['current_api_key'] ?? [];
	$wallet = $data['wallet'] ?? [];
	$tokens = $quota['tokens'] ?? [];
	$requests = $quota['requests'] ?? [];

	// =========================================================================
	// SECTION 1: Account Information
	// =========================================================================
	if (!empty($account)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-user" style="color: #3498db;"></span> '.$langs->trans("EasyOcrPlanAccount").'</td>';
		print '</tr>';

		if (!empty($account['name'])) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("EasyOcrPlanAccountName").'</td>';
			print '<td><strong>'.dol_escape_htmltag($account['name']).'</strong></td></tr>';
		}

		if (!empty($account['email'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanAccountEmail").'</td>';
			print '<td>'.dol_escape_htmltag($account['email']).'</td></tr>';
		}

		if (!empty($account['billing_mode'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanBillingMode").'</td>';
			print '<td>'.renderStatusBadge($account['billing_mode'] === 'subscription' ? 'active' : 'trial', $langs);
			print ' '.ucfirst(dol_escape_htmltag($account['billing_mode'])).'</td></tr>';
		}

		if (isset($account['is_active'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanAccountStatus").'</td>';
			print '<td>'.renderStatusBadge($account['is_active'] ? 'active' : 'inactive', $langs).'</td></tr>';
		}

		if (!empty($account['created_at'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanCreatedAt").'</td>';
			print '<td>'.dol_print_date(strtotime($account['created_at']), 'dayhour').'</td></tr>';
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 2: Subscription Status
	// =========================================================================
	if (!empty($subscription)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-sync-alt" style="color: #9b59b6;"></span> '.$langs->trans("EasyOcrPlanSubscription").'</td>';
		print '</tr>';

		if (!empty($subscription['status'])) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("Status").'</td>';
			print '<td>'.renderStatusBadge($subscription['status'], $langs).'</td></tr>';
		}

		if (!empty($subscription['billing_cycle'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanBillingCycle").'</td>';
			print '<td>'.ucfirst(dol_escape_htmltag($subscription['billing_cycle']));
			if ($subscription['billing_cycle'] === 'monthly') {
				print ' <span class="opacitymedium">('.$langs->trans("EasyOcrPlanMonthly").')</span>';
			} elseif ($subscription['billing_cycle'] === 'yearly') {
				print ' <span class="opacitymedium">('.$langs->trans("EasyOcrPlanYearly").')</span>';
			}
			print '</td></tr>';
		}

		if (!empty($subscription['current_period_start'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanPeriodStart").'</td>';
			print '<td>'.dol_print_date(strtotime($subscription['current_period_start']), 'dayhour').'</td></tr>';
		}

		if (!empty($subscription['current_period_end'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanPeriodEnd").'</td>';
			print '<td>'.dol_print_date(strtotime($subscription['current_period_end']), 'dayhour').'</td></tr>';
		}

		if (!empty($subscription['trial_ends_at'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanTrialEnds").'</td>';
			print '<td>'.dol_print_date(strtotime($subscription['trial_ends_at']), 'dayhour').'</td></tr>';
		}

		if (!empty($subscription['cancelled_at'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanCancelledAt").'</td>';
			print '<td><span style="color: #e74c3c;">'.dol_print_date(strtotime($subscription['cancelled_at']), 'dayhour').'</span></td></tr>';
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 3: Plan Details
	// =========================================================================
	if (!empty($plan)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-star" style="color: #f39c12;"></span> '.$langs->trans("EasyOcrPlanCurrent").'</td>';
		print '</tr>';

		if (!empty($plan['name'])) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("EasyOcrPlanName").'</td>';
			print '<td><strong style="font-size: 1.1em;">'.dol_escape_htmltag($plan['name']).'</strong>';
			if (!empty($plan['is_free'])) {
				print ' <span class="badge badge-status1">'.$langs->trans("EasyOcrPlanFree").'</span>';
			}
			print '</td></tr>';
		}

		if (!empty($plan['slug'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanSlug").'</td>';
			print '<td><code>'.dol_escape_htmltag($plan['slug']).'</code></td></tr>';
		}

		if (isset($plan['price_monthly'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanPriceMonthly").'</td>';
			print '<td><strong>'.price($plan['price_monthly'], 0, '', 1, -1, 2).' €</strong> / '.$langs->trans("EasyOcrPlanMonth").'</td></tr>';
		}

		if (isset($plan['price_yearly'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanPriceYearly").'</td>';
			print '<td><strong>'.price($plan['price_yearly'], 0, '', 1, -1, 2).' €</strong> / '.$langs->trans("EasyOcrPlanYear").'</td></tr>';
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 4: Usage Quota (with progress bars)
	// =========================================================================
	if (!empty($quota)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="3"><span class="fas fa-chart-bar" style="color: #27ae60;"></span> '.$langs->trans("EasyOcrPlanQuota").'</td>';
		print '</tr>';

		// Period header
		if (!empty($quota['period'])) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("EasyOcrPlanPeriod").'</td>';
			print '<td colspan="2"><strong>'.dol_escape_htmltag($quota['period']).'</strong></td></tr>';
		}

		// Pages usage with progress bar
		if (isset($quota['pages_used']) && isset($quota['pages_limit'])) {
			$pagesUsed = $quota['pages_used'];
			$pagesLimit = $quota['pages_limit'];
			$pagesRemaining = $quota['pages_remaining'] ?? ($pagesLimit - $pagesUsed);
			$pagesPercentage = $quota['usage_percentage'] ?? ($pagesLimit > 0 ? round(($pagesUsed / $pagesLimit) * 100, 1) : 0);
			
			print '<tr class="oddeven">';
			print '<td>'.$langs->trans("EasyOcrPlanQuotaPages").'</td>';
			print '<td style="width: 200px;">';
			print '<strong>'.$pagesUsed.'</strong> / '.$pagesLimit;
			if ($pagesPercentage > 100) {
				print ' <span class="badge badge-status8">'.$pagesPercentage.'%</span>';
			}
			print '</td>';
			print '<td>'.renderProgressBar($pagesUsed, $pagesLimit).'</td>';
			print '</tr>';

			print '<tr class="oddeven">';
			print '<td>'.$langs->trans("EasyOcrPlanPagesRemaining").'</td>';
			print '<td colspan="2"><strong style="color: '.($pagesRemaining > 0 ? '#27ae60' : '#e74c3c').';">'.$pagesRemaining.'</strong> '.$langs->trans("EasyOcrPlanPages").'</td>';
			print '</tr>';
		}

		// Documents usage with progress bar
		if (isset($quota['documents_used']) && isset($quota['documents_limit'])) {
			$docsUsed = $quota['documents_used'];
			$docsLimit = $quota['documents_limit'];
			$docsRemaining = $quota['documents_remaining'] ?? ($docsLimit - $docsUsed);
			
			print '<tr class="oddeven">';
			print '<td>'.$langs->trans("EasyOcrPlanQuotaDocs").'</td>';
			print '<td style="width: 200px;"><strong>'.$docsUsed.'</strong> / '.$docsLimit.'</td>';
			print '<td>'.renderProgressBar($docsUsed, $docsLimit).'</td>';
			print '</tr>';

			print '<tr class="oddeven">';
			print '<td>'.$langs->trans("EasyOcrPlanDocsRemaining").'</td>';
			print '<td colspan="2"><strong style="color: '.($docsRemaining > 0 ? '#27ae60' : '#e74c3c').';">'.$docsRemaining.'</strong> '.$langs->trans("EasyOcrPlanDocuments").'</td>';
			print '</tr>';
		}

		// Reset date
		if (!empty($quota['reset_date'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanQuotaReset").'</td>';
			print '<td colspan="2"><span class="fas fa-calendar-alt"></span> '.dol_print_date(strtotime($quota['reset_date']), 'dayhour').'</td></tr>';
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 5: Token Usage & API Requests
	// =========================================================================
	if (!empty($tokens) || !empty($requests)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-database" style="color: #e67e22;"></span> '.$langs->trans("EasyOcrPlanUsageStats").'</td>';
		print '</tr>';

		// Token usage
		if (!empty($tokens)) {
			if (isset($tokens['input'])) {
				print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("EasyOcrPlanTokensInput").'</td>';
				print '<td>'.number_format($tokens['input'], 0, ',', '.').'</td></tr>';
			}
			if (isset($tokens['output'])) {
				print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanTokensOutput").'</td>';
				print '<td>'.number_format($tokens['output'], 0, ',', '.').'</td></tr>';
			}
			if (isset($tokens['total'])) {
				print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanTokensTotal").'</td>';
				print '<td><strong>'.number_format($tokens['total'], 0, ',', '.').'</strong></td></tr>';
			}
		}

		// Request stats
		if (!empty($requests)) {
			if (isset($requests['total'])) {
				print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanRequestsTotal").'</td>';
				print '<td>'.number_format($requests['total'], 0, ',', '.').'</td></tr>';
			}
			if (isset($requests['failed'])) {
				print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanRequestsFailed").'</td>';
				print '<td><span style="color: '.($requests['failed'] > 0 ? '#e74c3c' : '#27ae60').';">'.$requests['failed'].'</span></td></tr>';
			}
			if (isset($requests['success_rate'])) {
				print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanSuccessRate").'</td>';
				print '<td><strong style="color: #27ae60;">'.$requests['success_rate'].'%</strong></td></tr>';
			}
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 6: Plan Limits
	// =========================================================================
	if (!empty($limits)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-ruler" style="color: #3498db;"></span> '.$langs->trans("EasyOcrPlanLimits").'</td>';
		print '</tr>';

		$limitFields = array(
			'max_pages_per_month' => 'EasyOcrPlanLimitPagesMonth',
			'max_documents_per_month' => 'EasyOcrPlanLimitDocsMonth',
			'max_pages_per_document' => 'EasyOcrPlanLimitPagesDoc',
			'max_file_size_mb' => 'EasyOcrPlanLimitFileSize',
			'max_batch_size' => 'EasyOcrPlanLimitBatch',
			'max_api_keys' => 'EasyOcrPlanLimitApiKeys',
			'rate_limit_per_minute' => 'EasyOcrPlanLimitRate',
			'max_concurrent_requests' => 'EasyOcrPlanLimitConcurrent',
			'document_retention_days' => 'EasyOcrPlanLimitRetention',
		);

		foreach ($limitFields as $key => $transKey) {
			if (isset($limits[$key])) {
				print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($transKey).'</td>';
				print '<td><strong>'.$limits[$key].'</strong>';
				// Add unit suffix
				if ($key === 'max_file_size_mb') print ' MB';
				elseif ($key === 'rate_limit_per_minute') print ' '.$langs->trans("EasyOcrPlanPerMin");
				elseif ($key === 'document_retention_days') print ' '.$langs->trans("Days");
				print '</td></tr>';
			}
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 7: Features
	// =========================================================================
	if (!empty($features) && is_array($features)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-puzzle-piece" style="color: #9b59b6;"></span> '.$langs->trans("EasyOcrPlanFeatures").'</td>';
		print '</tr>';

		$featureLabels = array(
			'api_access' => 'EasyOcrPlanFeatureApi',
			'streaming' => 'EasyOcrPlanFeatureStreaming',
			'batch_processing' => 'EasyOcrPlanFeatureBatch',
			'custom_instructions' => 'EasyOcrPlanFeatureInstructions',
			'webhooks' => 'EasyOcrPlanFeatureWebhooks',
			'priority_queue' => 'EasyOcrPlanFeaturePriority',
			'document_storage' => 'EasyOcrPlanFeatureStorage',
			'include_text' => 'EasyOcrPlanFeatureText',
			'auto_correct' => 'EasyOcrPlanFeatureAutoCorrect',
			'vision' => 'EasyOcrPlanFeatureVision',
		);

		foreach ($features as $featureKey => $featureValue) {
			$label = isset($featureLabels[$featureKey]) ? $langs->trans($featureLabels[$featureKey]) : ucfirst(str_replace('_', ' ', $featureKey));
			print '<tr class="oddeven"><td class="titlefield">'.$label.'</td>';
			print '<td>';
			if (is_bool($featureValue) || $featureValue === 0 || $featureValue === 1) {
				print renderYesNo($featureValue, $langs);
			} elseif (is_numeric($featureValue)) {
				print '<strong>'.$featureValue.'</strong>';
			} else {
				print dol_escape_htmltag($featureValue);
			}
			print '</td></tr>';
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 8: Current API Key Info
	// =========================================================================
	if (!empty($apiKeyInfo)) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2"><span class="fas fa-key" style="color: #1abc9c;"></span> '.$langs->trans("EasyOcrPlanApiKey").'</td>';
		print '</tr>';

		if (!empty($apiKeyInfo['name'])) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("EasyOcrPlanKeyName").'</td>';
			print '<td><strong>'.dol_escape_htmltag($apiKeyInfo['name']).'</strong></td></tr>';
		}

		if (!empty($apiKeyInfo['prefix'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanKeyPrefix").'</td>';
			print '<td><code>'.dol_escape_htmltag($apiKeyInfo['prefix']).'...</code></td></tr>';
		}

		if (!empty($apiKeyInfo['environment'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanKeyEnv").'</td>';
			print '<td>'.renderStatusBadge($apiKeyInfo['environment'], $langs).'</td></tr>';
		}

		if (isset($apiKeyInfo['is_active'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("Status").'</td>';
			print '<td>'.renderStatusBadge($apiKeyInfo['is_active'] ? 'active' : 'inactive', $langs).'</td></tr>';
		}

		if (!empty($apiKeyInfo['created_at'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanKeyCreated").'</td>';
			print '<td>'.dol_print_date(strtotime($apiKeyInfo['created_at']), 'dayhour').'</td></tr>';
		}

		if (!empty($apiKeyInfo['last_used_at'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanKeyLastUsed").'</td>';
			print '<td>'.dol_print_date(strtotime($apiKeyInfo['last_used_at']), 'dayhour').'</td></tr>';
		}

		if (!empty($apiKeyInfo['expires_at'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanKeyExpires").'</td>';
			print '<td>'.dol_print_date(strtotime($apiKeyInfo['expires_at']), 'dayhour').'</td></tr>';
		} else {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanKeyExpires").'</td>';
			print '<td><span class="opacitymedium">'.$langs->trans("EasyOcrPlanNoExpiration").'</span></td></tr>';
		}

		print '</table></div><br>';
	}

	// =========================================================================
	// SECTION 9: Wallet / Prepaid Balance
	// =========================================================================
	if (!empty($wallet) && !empty($wallet['exists'])) {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="3"><span class="fas fa-wallet" style="color: #e74c3c;"></span> '.$langs->trans("EasyOcrPlanWallet").'</td>';
		print '</tr>';

		if (isset($wallet['balance_pages'])) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("EasyOcrPlanWalletBalance").'</td>';
			print '<td colspan="2"><strong style="font-size: 1.2em; color: #27ae60;">'.number_format($wallet['balance_pages'], 0, ',', '.').'</strong> '.$langs->trans("EasyOcrPlanPages").'</td></tr>';
		}

		if (isset($wallet['total_purchased_pages']) && isset($wallet['total_consumed_pages'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanWalletPurchased").'</td>';
			print '<td style="width: 150px;">'.number_format($wallet['total_purchased_pages'], 0, ',', '.').' '.$langs->trans("EasyOcrPlanPages").'</td>';
			print '<td>'.renderProgressBar($wallet['total_consumed_pages'], $wallet['total_purchased_pages']).'</td></tr>';
		}

		if (isset($wallet['total_consumed_pages'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanWalletConsumed").'</td>';
			print '<td colspan="2">'.number_format($wallet['total_consumed_pages'], 0, ',', '.').' '.$langs->trans("EasyOcrPlanPages").'</td></tr>';
		}

		if (isset($wallet['is_active'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("Status").'</td>';
			print '<td colspan="2">'.renderStatusBadge($wallet['is_active'] ? 'active' : 'inactive', $langs).'</td></tr>';
		}

		if (isset($wallet['can_process'])) {
			print '<tr class="oddeven"><td>'.$langs->trans("EasyOcrPlanWalletCanProcess").'</td>';
			print '<td colspan="2">'.renderYesNo($wallet['can_process'], $langs).'</td></tr>';
		}

		print '</table></div><br>';
	}

} else {
	// No data available
	print '<div class="info">';
	print $langs->trans('EasyOcrPlanNoData');
	print '</div>';
}

print '</div>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
