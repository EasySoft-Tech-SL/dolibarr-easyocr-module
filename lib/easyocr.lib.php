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
 * \file       lib/easyocr.lib.php
 * \ingroup    easyocr
 * \brief      Library file for EasyOcr module
 */

/**
 * Check user permission, compatible with Dolibarr v14 through v23+.
 * Uses $user->hasRight() when available (v16+), falls back to
 * $user->rights->module->perm for older versions.
 *
 * @param  User   $user    User object
 * @param  string $module  Module name (e.g. 'easyocr')
 * @param  string $perm    Permission name (e.g. 'read', 'write', 'delete')
 * @return bool             True if user has the permission
 */
function easyocrCheckRight($user, $module, $perm)
{
	if (method_exists($user, 'hasRight')) {
		return $user->hasRight($module, $perm);
	}
	return !empty($user->rights->{$module}->{$perm});
}

/**
 * Prepare admin pages header
 *
 * @return array Array of tabs
 */
function easyocr_admin_prepare_head()
{
	global $langs, $conf;

	$langs->load('easyocr@easyocr');

	$h = 0;
	$head = array();

	// Setup/Configuration tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('EasyOcrSetup');
	$head[$h][2] = 'settings';
	$h++;

	// Service Plan tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/plan.php', 1);
	$head[$h][1] = '<span class="fas fa-star" style="color: #f39c12;"></span> ' . $langs->trans('EasyOcrPlan');
	$head[$h][2] = 'plan';
	$h++;

	// License agreement tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/copying.php', 1);
	$head[$h][1] = '<span class="fas fa-file-contract" style="color: #34495e;"></span> ' . $langs->trans('EasyOcrCopying');
	$head[$h][2] = 'copying';
	$h++;

	// Telemetry & Data Protection tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/telemetry.php', 1);
	$head[$h][1] = '<span class="fas fa-shield-alt" style="color: #3498db;"></span> ' . $langs->trans('EasyOcrTelemetry');
	$head[$h][2] = 'telemetry';
	$h++;

	// About tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/about.php', 1);
	$head[$h][1] = '<span class="fas fa-info-circle" style="color: #3498db;"></span> ' . $langs->trans('EasyOcrAbout');
	$head[$h][2] = 'about';
	$h++;

	// ChangeLog tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/changelog.php', 1);
	$head[$h][1] = '<span class="fas fa-list-ul" style="color: #52c41a;"></span> ' . $langs->trans('EasyOcrChangeLog');
	$head[$h][2] = 'changelog';
	$h++;

	// Complete the array
	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin', 'remove');

	return $head;
}
