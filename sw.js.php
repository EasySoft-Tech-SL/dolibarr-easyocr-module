<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L. — GPLv3
 * Service worker for the expense scanner PWA. Served as JS. Scope = this dir.
 * Conservative: network-first, caches only static GET assets, never touches
 * POST/OCR requests (AI-only feature requires connectivity anyway).
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: ' . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/');

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
const EO_CACHE = 'easyocr-scan-v1';
const EO_SHELL = '<?php echo $base; ?>/scan-expense.php';

self.addEventListener('install', function (e) {
	self.skipWaiting();
});

self.addEventListener('activate', function (e) {
	e.waitUntil((async function () {
		const keys = await caches.keys();
		await Promise.all(keys.filter(function (k) { return k !== EO_CACHE; }).map(function (k) { return caches.delete(k); }));
		await self.clients.claim();
	})());
});

self.addEventListener('fetch', function (e) {
	const req = e.request;
	if (req.method !== 'GET') { return; } // never cache POST (OCR / create)
	const url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }

	e.respondWith((async function () {
		try {
			const net = await fetch(req);
			if (/\.(css|js|png|jpe?g|svg|woff2?)$/i.test(url.pathname)) {
				const c = await caches.open(EO_CACHE);
				c.put(req, net.clone());
			}
			return net;
		} catch (err) {
			const cached = await caches.match(req);
			if (cached) { return cached; }
			throw err;
		}
	})());
});
