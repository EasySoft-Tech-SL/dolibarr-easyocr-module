/*
 * EasyOCR — scan-expense.js
 * Mobile expense receipt scanner (AI-only).
 * Flow: photo -> EXIF-correct + resize -> AI OCR (action=aiOcr) -> confirm -> create (action=newExpenseAI)
 * Copyright (C) 2025-2026 EasySoft Tech S.L. — GPLv3
 */
(function () {
	'use strict';

	function init() {

	var CFG = window.EO_SCAN_CFG || {};
	if (!CFG.ajaxUrl) { return; } // locked screen: no config injected

	var MAX_DIM = 1600;      // longest side after downscale
	var JPEG_QUALITY = 0.82;

	var elFile    = document.getElementById('eo-scan-file');
	var elShoot   = document.getElementById('eo-scan-shoot');
	var elCapture = document.getElementById('eo-scan-capture');
	var elLoading = document.getElementById('eo-scan-loading');
	var elLoadTxt = document.getElementById('eo-scan-loading-text');
	var elConfirm = document.getElementById('eo-scan-confirm');
	var elSuccess = document.getElementById('eo-scan-success');
	var elPreview = document.getElementById('eo-scan-preview');
	var elRetake  = document.getElementById('eo-scan-retake');
	var elAgain   = document.getElementById('eo-scan-again');

	var currentDataUrl = '';  // processed image (data URL) kept for the create call

	// ── Helpers ──────────────────────────────────────────────────────────
	function show(el) { if (el) el.style.display = ''; }
	function hide(el) { if (el) el.style.display = 'none'; }

	function toast(msg, isError) {
		var t = document.createElement('div');
		t.className = 'eo-scan-toast' + (isError ? ' eo-scan-toast-error' : '');
		t.textContent = msg;
		document.body.appendChild(t);
		setTimeout(function () { t.classList.add('show'); }, 10);
		setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 3200);
	}

	function post(params) {
		var body = new URLSearchParams();
		body.append('token', CFG.token || '');
		Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
		return fetch(CFG.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			credentials: 'same-origin',
			body: body.toString()
		}).then(function (r) {
			if (r.status === 401) { throw { sessionExpired: true }; }
			return r.text();
		}).then(function (txt) {
			try { return JSON.parse(txt); }
			catch (e) {
				// Tolerate stray PHP notices/warnings printed before the JSON body
				var i = txt.indexOf('{');
				if (i > 0) { try { return JSON.parse(txt.slice(i)); } catch (e2) {} }
				throw { raw: txt };
			}
		});
	}

	// Load an image respecting EXIF orientation, downscale, return JPEG data URL
	function processImage(file) {
		return new Promise(function (resolve, reject) {
			var finish = function (bitmap, w, h) {
				var scale = Math.min(1, MAX_DIM / Math.max(w, h));
				var cw = Math.round(w * scale), ch = Math.round(h * scale);
				var canvas = document.createElement('canvas');
				canvas.width = cw; canvas.height = ch;
				canvas.getContext('2d').drawImage(bitmap, 0, 0, cw, ch);
				try { resolve(canvas.toDataURL('image/jpeg', JPEG_QUALITY)); }
				catch (e) { reject(e); }
			};
			if (window.createImageBitmap) {
				// imageOrientation:'from-image' applies EXIF rotation (modern browsers)
				createImageBitmap(file, { imageOrientation: 'from-image' })
					.then(function (bmp) { finish(bmp, bmp.width, bmp.height); })
					.catch(function () { fallbackImg(); });
			} else {
				fallbackImg();
			}
			function fallbackImg() {
				var img = new Image();
				var url = URL.createObjectURL(file);
				img.onload = function () { URL.revokeObjectURL(url); finish(img, img.naturalWidth, img.naturalHeight); };
				img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('image load failed')); };
				img.src = url;
			}
		});
	}

	// Recursively find a structured_data node in the API response
	function findStructured(o, depth) {
		if (!o || typeof o !== 'object' || depth > 6) return null;
		if (o.structured_data && typeof o.structured_data === 'object') return o.structured_data;
		for (var k in o) {
			if (Object.prototype.hasOwnProperty.call(o, k)) {
				var r = findStructured(o[k], (depth || 0) + 1);
				if (r) return r;
			}
		}
		return null;
	}

	function num(v) {
		if (v === null || v === undefined) return '';
		return ('' + v).replace(/[^0-9.,-]/g, '');
	}

	function prefillFromOcr(apiResult) {
		var sd = findStructured(apiResult, 0) || apiResult || {};
		var supplier = sd.supplier || sd.merchant || {};
		var totals = sd.totals || {};

		var merchant = supplier.name || sd.merchant_name || sd.supplier_name || '';
		// The "Total" field is the amount WITH VAT (TTC). Prefer the payable total;
		// if only net + tax come separately, the payable is their sum.
		var total = (totals.total_payable != null ? totals.total_payable
			: (totals.total != null ? totals.total : (sd.total != null ? sd.total : null)));
		if ((total == null || parseFloat(total) === 0) && totals.net_subtotal != null) {
			var net = parseFloat(totals.net_subtotal) || 0;
			var tax = parseFloat(totals.tax_total != null ? totals.tax_total : 0) || 0;
			if (net > 0) total = net + tax;
		}
		if (total == null) total = '';
		var date = sd.issue_date || sd.date || '';
		var vat = 0;
		if (totals.taxes && totals.taxes.length && totals.taxes[0].tax_rate != null) vat = totals.taxes[0].tax_rate;
		else if (totals.tax_rate != null) vat = totals.tax_rate;
		else if (sd.taxes && sd.taxes.length && sd.taxes[0].tax_rate != null) vat = sd.taxes[0].tax_rate;

		setVal('eo-exp-merchant', merchant);
		setVal('eo-exp-total', num(total));
		setVal('eo-exp-vat', num(vat));
		var dInput = document.getElementById('eo-exp-date');
		if (dInput) dInput.value = /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : todayIso();

		renderLines(sd.items || sd.lines || []);
		recomputeBreakdown();
	}

	function setVal(id, v) { var e = document.getElementById(id); if (e) e.value = (v == null ? '' : v); }
	function getVal(id) { var e = document.getElementById(id); return e ? e.value : ''; }
	function todayIso() {
		var d = new Date(), m = ('0' + (d.getMonth() + 1)).slice(-2), day = ('0' + d.getDate()).slice(-2);
		return d.getFullYear() + '-' + m + '-' + day;
	}
	function setTxt(id, t) { var e = document.getElementById(id); if (e) e.textContent = t; }
	function fmt(n) {
		var v = parseFloat(('' + n).replace(',', '.'));
		if (isNaN(v)) return '—';
		return v.toFixed(2) + ' €';
	}

	// Base/VAT/Total breakdown, derived live from the (editable) total + VAT rate
	function recomputeBreakdown() {
		var ttc = parseFloat(getVal('eo-exp-total').replace(',', '.'));
		var rate = parseFloat(getVal('eo-exp-vat').replace(',', '.'));
		if (isNaN(ttc)) { setTxt('eo-exp-base', '—'); setTxt('eo-exp-ivaamt', '—'); setTxt('eo-exp-totalamt', '—'); return; }
		if (isNaN(rate)) rate = 0;
		var base = ttc / (1 + rate / 100);
		setTxt('eo-exp-base', fmt(base));
		setTxt('eo-exp-ivaamt', fmt(ttc - base));
		setTxt('eo-exp-totalamt', fmt(ttc));
	}

	// Detected line items (read-only, informational)
	function renderLines(items) {
		var wrap = document.getElementById('eo-exp-lines-wrap');
		var ul = document.getElementById('eo-exp-lines');
		var cnt = document.getElementById('eo-exp-lines-count');
		if (!wrap || !ul) return;
		ul.innerHTML = '';
		if (!items || !items.length) { wrap.style.display = 'none'; return; }
		items.forEach(function (it) {
			var desc = it.description || it.code || '';
			var qty = (it.quantity != null && it.quantity !== '') ? it.quantity : '';
			var tot = (it.total != null) ? it.total : (it.net_amount != null ? it.net_amount : '');
			var li = document.createElement('li');
			var left = document.createElement('span');
			left.className = 'eo-line-desc';
			left.textContent = (qty ? (qty + '× ') : '') + desc;
			var right = document.createElement('span');
			right.className = 'eo-line-amt';
			right.textContent = fmt(tot);
			li.appendChild(left);
			li.appendChild(right);
			ul.appendChild(li);
		});
		if (cnt) cnt.textContent = items.length;
		wrap.style.display = '';
	}

	function goCapture() { stopCamera(); hide(elLoading); hide(elConfirm); hide(elSuccess); show(elCapture); if (elFile) elFile.value = ''; currentDataUrl = ''; }
	function goConfirm() { hide(elLoading); hide(elCapture); hide(elSuccess); show(elConfirm); }
	function goSuccess() { hide(elLoading); hide(elCapture); hide(elConfirm); show(elSuccess); }

	function handleSessionExpired() {
		toast('Sesión expirada', true);
		setTimeout(function () { window.location.reload(); }, 1500);
	}

	// ── OCR pipeline (shared by the in-app camera and the file picker) ───
	function runOcr(dataUrl) {
		currentDataUrl = dataUrl;
		if (elPreview) elPreview.src = dataUrl;
		stopCamera();
		hide(elCapture); show(elLoading);
		if (elLoadTxt) elLoadTxt.textContent = CFG.i18n.analyzing;
		// Send the photo to the expense OCR endpoint (image -> OCR; the server sends
		// the right filename so the microservice accepts the image natively).
		post({ action: 'expenseOcr', image_base64: dataUrl }).then(function (res) {
			if (!res || res.status !== 'ok') {
				goCapture();
				toast((res && res.message) ? res.message : CFG.i18n.errorOcr, true);
				return;
			}
			prefillFromOcr(res.data);
			goConfirm();
		}).catch(function (err) {
			if (err && err.sessionExpired) return handleSessionExpired();
			goCapture();
			toast(CFG.i18n.errorOcr, true);
		});
	}

	// ── In-app camera with framing guide (getUserMedia) ──────────────────
	var camStream = null;
	var elCam = document.getElementById('eo-scan-cam');
	var elCamVideo = document.getElementById('eo-cam-video');
	var elCamCanvas = document.getElementById('eo-cam-canvas');
	var elCamShoot = document.getElementById('eo-cam-shoot');
	var elCamCancel = document.getElementById('eo-cam-cancel');
	var elCamGallery = document.getElementById('eo-cam-gallery');
	var elGallery = document.getElementById('eo-scan-gallery');
	var elGalleryBtn = document.getElementById('eo-scan-gallery-btn');

	function stopCamera() {
		if (camStream) { camStream.getTracks().forEach(function (t) { t.stop(); }); camStream = null; }
		if (elCam) elCam.style.display = 'none';
		if (elCamVideo) elCamVideo.srcObject = null;
	}

	function startCapture() {
		// Prefer the in-app camera (framing guide); fall back to the native picker
		if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia && elCam && elCamVideo) {
			navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
				.then(function (stream) {
					camStream = stream;
					elCamVideo.srcObject = stream;
					elCam.style.display = '';
				})
				.catch(function () { if (elFile) elFile.click(); }); // denied / unavailable
		} else if (elFile) {
			elFile.click();
		}
	}

	function captureFromCamera() {
		if (!elCamVideo || !elCamVideo.videoWidth) return;
		var vw = elCamVideo.videoWidth, vh = elCamVideo.videoHeight;
		var scale = Math.min(1, MAX_DIM / Math.max(vw, vh));
		var cw = Math.round(vw * scale), ch = Math.round(vh * scale);
		var canvas = elCamCanvas || document.createElement('canvas');
		canvas.width = cw; canvas.height = ch;
		canvas.getContext('2d').drawImage(elCamVideo, 0, 0, cw, ch);
		runOcr(canvas.toDataURL('image/jpeg', JPEG_QUALITY));
	}

	// ── Events ───────────────────────────────────────────────────────────
	if (elShoot) elShoot.addEventListener('click', startCapture);
	if (elCamShoot) elCamShoot.addEventListener('click', captureFromCamera);
	if (elCamCancel) elCamCancel.addEventListener('click', function () { stopCamera(); goCapture(); });

	// Shared handler for both the camera-capture fallback input and the gallery input
	function handleFile(input) {
		var file = input && input.files && input.files[0];
		if (!file) return;
		hide(elCapture); show(elLoading);
		if (elLoadTxt) elLoadTxt.textContent = CFG.i18n.analyzing;
		processImage(file).then(runOcr).catch(function () {
			goCapture();
			toast(CFG.i18n.errorOcr, true);
		});
	}
	if (elFile) elFile.addEventListener('change', function () { handleFile(elFile); });
	if (elGallery) elGallery.addEventListener('change', function () { handleFile(elGallery); });
	if (elGalleryBtn) elGalleryBtn.addEventListener('click', function () { if (elGallery) elGallery.click(); });
	if (elCamGallery) elCamGallery.addEventListener('click', function () { stopCamera(); if (elGallery) elGallery.click(); });

	if (elRetake) elRetake.addEventListener('click', goCapture);
	if (elAgain) elAgain.addEventListener('click', goCapture);

	// Live breakdown recompute when the user edits total or VAT
	var elTotalIn = document.getElementById('eo-exp-total');
	var elVatIn = document.getElementById('eo-exp-vat');
	if (elTotalIn) elTotalIn.addEventListener('input', recomputeBreakdown);
	if (elVatIn) elVatIn.addEventListener('input', recomputeBreakdown);

	// Tap the receipt preview to view it full-screen; tap the overlay to close
	var elZoom = document.getElementById('eo-scan-zoom');
	var elZoomImg = document.getElementById('eo-scan-zoom-img');
	if (elPreview && elZoom && elZoomImg) {
		elPreview.addEventListener('click', function () {
			elZoomImg.src = currentDataUrl || elPreview.src;
			elZoom.style.display = '';
		});
		elZoom.addEventListener('click', function () { elZoom.style.display = 'none'; });
	}

	if (elConfirm) elConfirm.addEventListener('submit', function (e) {
		e.preventDefault();
		if (!currentDataUrl) { toast(CFG.i18n.noPhoto, true); return; }
		var total = getVal('eo-exp-total');
		if (!total) { toast(CFG.i18n.errorSave, true); return; }

		var validateEl = document.getElementById('eo-exp-validate');
		var params = {
			action: 'newExpenseAI',
			total_ttc: total,
			vat_rate: getVal('eo-exp-vat'),
			merchant: getVal('eo-exp-merchant'),
			datef: getVal('eo-exp-date'),
			fk_c_type_fees: getVal('eo-exp-category') || 0,
			project_id: getVal('project_id') || 0,
			validate: (validateEl && validateEl.checked) ? 1 : 0,
			image_base64: currentDataUrl
		};

		var submitBtn = document.getElementById('eo-scan-submit');
		if (submitBtn) submitBtn.disabled = true;
		show(elLoading); hide(elConfirm);
		if (elLoadTxt) elLoadTxt.textContent = CFG.i18n.sending;

		post(params).then(function (res) {
			if (submitBtn) submitBtn.disabled = false;
			if (!res || res.status !== 'ok') {
				goConfirm();
				toast((res && res.message) ? res.message : CFG.i18n.errorSave, true);
				return;
			}
			var refEl = document.getElementById('eo-scan-success-ref');
			var titleEl = document.getElementById('eo-scan-success-title');
			if (titleEl) titleEl.textContent = res.is_draft ? CFG.i18n.draftOk : CFG.i18n.validatedOk;
			if (refEl) refEl.textContent = res.ref || '';
			goSuccess();
		}).catch(function (err) {
			if (submitBtn) submitBtn.disabled = false;
			if (err && err.sessionExpired) return handleSessionExpired();
			goConfirm();
			toast(CFG.i18n.errorSave, true);
		});
	});

	// ── PWA install button (beforeinstallprompt) ────────────────────────
	var elInstall = document.getElementById('eo-scan-install');
	var deferredPrompt = null;
	var isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		deferredPrompt = e;
		if (elInstall && !isStandalone) { elInstall.style.display = ''; }
	});
	if (elInstall) elInstall.addEventListener('click', function () {
		if (!deferredPrompt) { return; }
		deferredPrompt.prompt();
		deferredPrompt.userChoice.then(function () {
			deferredPrompt = null;
			elInstall.style.display = 'none';
		});
	});
	window.addEventListener('appinstalled', function () {
		if (elInstall) { elInstall.style.display = 'none'; }
	});

	// ── Service worker registration (PWA) — only over HTTPS ──────────────
	if ('serviceWorker' in navigator && location.protocol === 'https:') {
		var swUrl = CFG.ajaxUrl.replace(/ajax\/ajax_easyocr\.php.*$/, 'sw.js.php');
		navigator.serviceWorker.register(swUrl).catch(function (e) {
			// non-fatal: PWA offline just won't be available
			if (window.console) console.warn('EasyOCR SW register failed', e);
		});
	}

	} // end init

	// The script is injected in <head> (before the DOM and the inline config),
	// so defer init until the document — and window.EO_SCAN_CFG — are ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
