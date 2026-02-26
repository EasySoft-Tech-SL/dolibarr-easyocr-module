<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       index.php
 * \ingroup    easyocr
 * \brief      Dashboard principal del módulo EasyOcr con accesos directos en tarjetas
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

require_once __DIR__.'/lib/easyocr.lib.php';

// Load translations
$langs->loadLangs(array("easyocr@easyocr"));

// Security check
if (!easyocrCheckRight($user, 'easyocr', 'read')) {
	accessforbidden();
}

$permWrite  = easyocrCheckRight($user, 'easyocr', 'write');
$permRead   = easyocrCheckRight($user, 'easyocr', 'read');
$permDelete = easyocrCheckRight($user, 'easyocr', 'delete');

/*
 * View
 */

$title     = 'EasyOcr';
$help_url  = '';

// ─── Quick stats from DB — antes del header (patrón estándar Dolibarr) ──────
$nbInvoices = 0;
$nbTemplates = 0;

$resql = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."facture_fourn WHERE import_key IN ('easyocr','easyocr-ai','easyocr-wh') AND entity IN (".getEntity('facture_fourn').")");
if ($resql) {
	$obj = $db->fetch_object($resql);
	$nbInvoices = (int) $obj->nb;
}

$resql2 = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."easyocr_template");
if ($resql2) {
	$obj2 = $db->fetch_object($resql2);
	$nbTemplates = (int) $obj2->nb;
}

llxHeader('', $title, $help_url, '', 0, 0, array(), array(), '', 'mod-easyocr page-index');

// ─── Inline styles ─────────────────────────────────────────────────────────
?>
<style>
/* ── Dashboard EasyOcr ── */
.eo-dashboard {
	max-width: 960px;
	margin: 0 auto;
	padding: 20px 0 40px;
}

/* ── Header compacto ── */
.eo-header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 28px;
	padding-bottom: 20px;
	border-bottom: 1px solid #dee2e6;
}
.eo-header-logo {
	width: 48px;
	height: 48px;
	flex-shrink: 0;
}
.eo-header-text h1 {
	margin: 0;
	font-size: 1.5em;
	font-weight: 700;
	color: #263238;
}
.eo-header-text p {
	margin: 2px 0 0;
	font-size: .85em;
	color: #78909c;
}

/* ── Stat counters ── */
.eo-stats {
	display: flex;
	gap: 16px;
	margin-bottom: 30px;
	flex-wrap: wrap;
}
.eo-stat {
	display: flex;
	align-items: center;
	gap: 14px;
	background: #fff;
	border: 1px solid #e0e4e8;
	border-radius: 10px;
	padding: 16px 22px;
	min-width: 180px;
	text-decoration: none;
	color: inherit;
	transition: border-color .15s, box-shadow .15s;
}
.eo-stat:hover {
	border-color: #90a4ae;
	box-shadow: 0 2px 8px rgba(0,0,0,.08);
	text-decoration: none;
}
.eo-stat-icon {
	width: 40px;
	height: 40px;
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	font-size: 1.1em;
}
.eo-stat-icon--inv { background: #fff3e0; color: #e65100; }
.eo-stat-icon--tpl { background: #ede7f6; color: #5e35b1; }
.eo-stat-info strong {
	display: block;
	font-size: 1.4em;
	font-weight: 700;
	color: #263238;
	line-height: 1.1;
}
.eo-stat-info span {
	font-size: .8em;
	color: #78909c;
	text-transform: uppercase;
	letter-spacing: .03em;
}

/* ── Section title ── */
.eo-section-title {
	font-size: .78em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: .08em;
	color: #90a4ae;
	margin-bottom: 14px;
}

/* ── Cards grid ── */
.eo-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 18px;
}

/* ── Single card ── */
.eo-card {
	background: #fff;
	border: 1px solid #e0e4e8;
	border-radius: 10px;
	padding: 24px 22px 20px;
	text-decoration: none;
	color: inherit;
	display: flex;
	flex-direction: column;
	position: relative;
	transition: border-color .15s, box-shadow .15s;
}
.eo-card:hover {
	border-color: var(--eo-c, #546e7a);
	box-shadow: 0 4px 16px rgba(0,0,0,.09);
	text-decoration: none;
}

/* top accent bar */
.eo-card::after {
	content: '';
	position: absolute;
	top: 0; left: 16px; right: 16px;
	height: 3px;
	border-radius: 0 0 3px 3px;
	background: var(--eo-c, #546e7a);
	opacity: 0;
	transition: opacity .15s;
}
.eo-card:hover::after {
	opacity: 1;
}

/* icon circle */
.eo-card-icon {
	width: 46px;
	height: 46px;
	border-radius: 10px;
	background: var(--eo-bg, #eceff1);
	color: var(--eo-c, #546e7a);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 1.2em;
	margin-bottom: 16px;
	flex-shrink: 0;
}

.eo-card h3 {
	margin: 0 0 6px;
	font-size: 1em;
	font-weight: 600;
	color: #263238;
}
.eo-card p {
	margin: 0;
	font-size: .84em;
	color: #78909c;
	line-height: 1.5;
	flex: 1;
}
.eo-card-footer {
	margin-top: 16px;
	display: flex;
	align-items: center;
	gap: 5px;
	font-size: .78em;
	font-weight: 600;
	color: var(--eo-c, #546e7a);
	opacity: .6;
	transition: opacity .15s;
}
.eo-card:hover .eo-card-footer {
	opacity: 1;
}
.eo-card-footer .fas,
.eo-card-footer .far {
	font-size: .75em;
}

/* ── Color variants ── */
.eo-card--pdf   { --eo-c: #1565c0; --eo-bg: #e3f2fd; }
.eo-card--batch { --eo-c: #2e7d32; --eo-bg: #e8f5e9; }
.eo-card--tpl   { --eo-c: #5e35b1; --eo-bg: #ede7f6; }
.eo-card--inv   { --eo-c: #e65100; --eo-bg: #fff3e0; }
.eo-card--admin { --eo-c: #546e7a; --eo-bg: #eceff1; }
</style>

<div class="eo-dashboard">

	<!-- Header -->
	<div class="eo-header">
		<img class="eo-header-logo" src="<?php echo dol_buildpath('/easyocr/img/easyocr.png', 1); ?>" alt="EasyOcr">
		<div class="eo-header-text">
			<h1>EasyOcr</h1>
			<p><?php echo $langs->trans('ModuleEasyocrDescLong'); ?></p>
		</div>
	</div>

	<!-- Stats -->
	<div class="eo-stats">
		<a href="<?php echo dol_buildpath('/easyocr/invoices.php', 1); ?>" class="eo-stat">
			<div class="eo-stat-icon eo-stat-icon--inv"><i class="fas fa-file-invoice"></i></div>
			<div class="eo-stat-info">
				<strong><?php echo $nbInvoices; ?></strong>
				<span><?php echo $langs->trans('EasyOcrMenuInvoices'); ?></span>
			</div>
		</a>
		<a href="<?php echo dol_buildpath('/easyocr/templates.php', 1); ?>" class="eo-stat">
			<div class="eo-stat-icon eo-stat-icon--tpl"><i class="fas fa-ruler-combined"></i></div>
			<div class="eo-stat-info">
				<strong><?php echo $nbTemplates; ?></strong>
				<span><?php echo $langs->trans('EasyOcrMenuTemplates'); ?></span>
			</div>
		</a>
	</div>

	<!-- Cards -->
	<div class="eo-section-title"><?php echo $langs->trans('EasyOcrIndexSections'); ?></div>

	<div class="eo-cards">

		<?php if ($permWrite): ?>
		<a href="<?php echo dol_buildpath('/easyocr/extract.php', 1); ?>" class="eo-card eo-card--pdf">
			<div class="eo-card-icon"><i class="fas fa-file-pdf"></i></div>
			<h3><?php echo $langs->trans('EasyOcrMenuUploadPdf'); ?></h3>
			<p><?php echo $langs->trans('EasyOcrIndexDescTool'); ?></p>
			<span class="eo-card-footer"><?php echo $langs->trans('EasyOcrIndexOpen'); ?> <i class="fas fa-arrow-right"></i></span>
		</a>

		<a href="<?php echo dol_buildpath('/easyocr/batch.php', 1); ?>" class="eo-card eo-card--batch">
			<div class="eo-card-icon"><i class="fas fa-layer-group"></i></div>
			<h3><?php echo $langs->trans('EasyOcrMenuBatch'); ?></h3>
			<p><?php echo $langs->trans('EasyOcrIndexDescBatch'); ?></p>
			<span class="eo-card-footer"><?php echo $langs->trans('EasyOcrIndexOpen'); ?> <i class="fas fa-arrow-right"></i></span>
		</a>
		<?php endif; ?>

		<?php if ($permRead): ?>
		<a href="<?php echo dol_buildpath('/easyocr/templates.php', 1); ?>" class="eo-card eo-card--tpl">
			<div class="eo-card-icon"><i class="fas fa-th-large"></i></div>
			<h3><?php echo $langs->trans('EasyOcrMenuTemplates'); ?></h3>
			<p><?php echo $langs->trans('EasyOcrIndexDescTemplates'); ?></p>
			<span class="eo-card-footer"><?php echo $langs->trans('EasyOcrIndexOpen'); ?> <i class="fas fa-arrow-right"></i></span>
		</a>

		<a href="<?php echo dol_buildpath('/easyocr/invoices.php', 1); ?>" class="eo-card eo-card--inv">
			<div class="eo-card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
			<h3><?php echo $langs->trans('EasyOcrMenuInvoices'); ?></h3>
			<p><?php echo $langs->trans('EasyOcrIndexDescInvoices'); ?></p>
			<span class="eo-card-footer"><?php echo $langs->trans('EasyOcrIndexOpen'); ?> <i class="fas fa-arrow-right"></i></span>
		</a>
		<?php endif; ?>

		<?php if ($user->admin): ?>
		<a href="<?php echo dol_buildpath('/easyocr/admin/setup.php', 1); ?>" class="eo-card eo-card--admin">
			<div class="eo-card-icon"><i class="fas fa-cog"></i></div>
			<h3><?php echo $langs->trans('EasyOcrSetup'); ?></h3>
			<p><?php echo $langs->trans('EasyOcrIndexDescSetup'); ?></p>
			<span class="eo-card-footer"><?php echo $langs->trans('EasyOcrIndexOpen'); ?> <i class="fas fa-arrow-right"></i></span>
		</a>
		<?php endif; ?>

	</div>

</div>

<?php
llxFooter();
$db->close();
