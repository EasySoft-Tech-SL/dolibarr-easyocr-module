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
 * \file       lib/easyocr_autoload.php
 * \ingroup    easyocr
 * \brief      PSR-4 autoloader for EasyOCR PHP client library
 */

// Load Composer autoloader for dependencies (Guzzle, etc.)
$composerAutoload = __DIR__ . '/easyocr/vendor/autoload.php';
if (file_exists($composerAutoload)) {
	require_once $composerAutoload;
}

// PSR-4 autoloader for EasySoft\EasyOCR namespace
spl_autoload_register(function ($class) {
	// Only autoload classes from EasySoft\EasyOCR namespace
	$prefix = 'EasySoft\\EasyOCR\\';
	$base_dir = __DIR__ . '/easyocr/src/';

	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		// Not our namespace, skip
		return;
	}

	// Get the relative class name (strip prefix)
	$relative_class = substr($class, $len);

	// Replace namespace separators with directory separators
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

	// If the file exists, require it
	if (file_exists($file)) {
		require_once $file;
	}
});
