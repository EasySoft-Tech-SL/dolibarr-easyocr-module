<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L. — GPLv3
 * PWA manifest for the mobile expense scanner. Lightweight: no Dolibarr bootstrap
 * (the browser may fetch it without full session context). Paths are derived from
 * the script's own served location so it works on subfolder installs.
 */
header('Content-Type: application/manifest+json; charset=utf-8');

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
// Los iconos tienen que existir a ESE tamano: antes se declaraba img/easyocr.png (32x32)
// como 192x192 y 512x512, y el movil estiraba una imagen de 32 px para el icono de la app.
// Estos dos salen del simbolo del logotipo (blanco + #FF7314 sobre negro), al 72% del ancho
// para que el maskable no recorte nada.
$icon192 = $base . '/img/pwa-192.png';
$icon512 = $base . '/img/pwa-512.png';

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
		array('src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
		array('src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
		array('src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
	),
);

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
