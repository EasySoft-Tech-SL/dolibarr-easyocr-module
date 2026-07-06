<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L. — GPLv3
 * PWA manifest for the mobile expense scanner. Lightweight: no Dolibarr bootstrap
 * (the browser may fetch it without full session context). Paths are derived from
 * the script's own served location so it works on subfolder installs.
 */
header('Content-Type: application/manifest+json; charset=utf-8');

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$icon = $base . '/img/easyocr.png';

$manifest = array(
	'name'             => 'EasyOCR — Gastos',
	'short_name'       => 'Gastos',
	'description'      => 'Escanea tickets de gasto y registralos en Dolibarr',
	'start_url'        => $base . '/scan-expense.php',
	'scope'            => $base . '/',
	'display'          => 'standalone',
	'orientation'      => 'portrait',
	'background_color' => '#ffffff',
	'theme_color'      => '#0f7b5a',
	'icons'            => array(
		array('src' => $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
		array('src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
		array('src' => $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'),
	),
);

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
