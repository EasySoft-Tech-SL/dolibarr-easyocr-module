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

	// About tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/about.php', 1);
	$head[$h][1] = $langs->trans('EasyOcrAbout');
	$head[$h][2] = 'about';
	$h++;

	// ChangeLog tab
	$head[$h][0] = dol_buildpath('/easyocr/admin/changelog.php', 1);
	$head[$h][1] = $langs->trans('EasyOcrChangeLog');
	$head[$h][2] = 'changelog';
	$h++;

	// Complete the array
	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin', 'remove');

	return $head;
}
