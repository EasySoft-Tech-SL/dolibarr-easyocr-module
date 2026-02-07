/* ============================================
   EasyOcr v2.1 - Motor principal
   Flujo: Seleccionar etiqueta → Dibujar → Asignación automática
   Copyright (C) 2024-2026 EasySoft Tech S.L.
   ============================================ */

// Configurar PDF.js worker
if (typeof pdfjsLib !== 'undefined' && window.EasyOcrWorkerSrc) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = window.EasyOcrWorkerSrc;
}

const EasyOcr = (function () {

    // ---- Estado global ----
    const state = {
        file: null,
        templateId: null,
        pages: [],
        activeTag: null,
        pdfDoc: null,
        scale: 1.5,
        isDrawing: false,
        drawStart: { x: 0, y: 0 },
        drawPage: null,
        suppliersData: [],   // Cache de proveedores
        templatesData: [],   // Cache de plantillas
        banksData: [],       // Cache de cuentas bancarias
        paymentTypesData: [], // Cache de tipos de pago
        pdfArrayBuffer: null // Para re-render en zoom
    };

    // Historial para undo
    const history = [];
    const MAX_HISTORY = 30;

    // Etiquetas disponibles
    const tags = [
        { label: "Con fecha de", color: "#8a27b2", key: "Confechade" },
        { label: "Factura", color: "#1c7cff", key: "Factura" },
        { label: "HT totales", color: "#e51515", key: "HTtotales" },
        { label: "Precio total", color: "#e515b3", key: "Preciototal" },
    ];

    // Toast stacking
    let toastCount = 0;

    // ---- Utilidades ----
    function hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function showLoader() {
        document.getElementById('loader').style.display = 'flex';
    }

    function hideLoader() {
        document.getElementById('loader').style.display = 'none';
    }

    function toast(msg, type) {
        type = type || 'success';
        const el = document.createElement('div');
        el.className = 'eo-toast ' + type;
        el.textContent = msg;
        const offset = 20 + (toastCount * 52);
        el.style.bottom = offset + 'px';
        document.body.appendChild(el);
        toastCount++;
        setTimeout(() => {
            el.classList.add('eo-toast-out');
            setTimeout(() => {
                el.remove();
                toastCount = Math.max(0, toastCount - 1);
            }, 300);
        }, 3000);
    }

    function showModal(id) {
        document.getElementById(id).style.display = 'flex';
    }

    function hideModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    // ---- Historial (Undo) ----
    function pushHistory() {
        const snapshot = state.pages.map(p => ({
            selections: p.selections.map(s => ({ ...s }))
        }));
        history.push(snapshot);
        if (history.length > MAX_HISTORY) history.shift();
    }

    function undo() {
        if (history.length === 0) {
            toast('Nada que deshacer', 'warn');
            return;
        }
        const snapshot = history.pop();
        state.pages.forEach((page, i) => {
            if (snapshot[i]) {
                page.selections = snapshot[i].selections;
            }
        });
        state.pages.forEach((p, i) => redrawPage(i));
        renderTags();
        renderSelections();
        updateReadiness();
        toast('Acción deshecha');
    }

    // ---- Obtener selecciones usadas ----
    function getUsedLabels() {
        const used = new Set();
        state.pages.forEach(page => {
            page.selections.forEach(sel => used.add(sel.label));
        });
        return used;
    }

    // ---- Renderizar etiquetas en sidebar ----
    function renderTags() {
        const container = document.getElementById('eo-tags');
        const used = getUsedLabels();
        let html = '';

        tags.forEach((tag, idx) => {
            const isUsed = used.has(tag.label);
            const isActive = state.activeTag && state.activeTag.label === tag.label;
            let cls = 'eo-tag';
            if (isActive) cls += ' active';
            if (isUsed) cls += ' used';

            html += `<div class="${cls}" style="background:${tag.color}" 
                          data-tag-idx="${idx}" 
                          onclick="EasyOcr.selectTag(${idx})">
                        <span class="eo-tag-key">${idx + 1}</span>
                        ${tag.label}
                     </div>`;
        });

        container.innerHTML = html;
    }

    // ---- Seleccionar / Deseleccionar etiqueta ----
    function selectTag(idx) {
        if (idx < 0 || idx >= tags.length) return;
        const tag = tags[idx];
        const used = getUsedLabels();

        if (used.has(tag.label)) return;

        if (state.activeTag && state.activeTag.label === tag.label) {
            state.activeTag = null;
        } else {
            state.activeTag = tag;
        }

        renderTags();
        updateCanvasCursors();
    }

    function updateCanvasCursors() {
        state.pages.forEach(page => {
            page.canvas.style.cursor = state.activeTag ? 'crosshair' : 'default';
        });
    }

    // ---- Validación visual / Readiness ----
    function updateReadiness() {
        const supplier = $('#eo-supplier').val();
        const factura = getSelectionValue('Factura');
        const fecha = getSelectionValue('Con fecha de');
        const totalTtc = getSelectionValue('Precio total');
        const totalHt = getSelectionValue('HT totales');

        const checks = [
            { id: 'eo-chk-supplier', ok: !!supplier },
            { id: 'eo-chk-factura', ok: !!factura },
            { id: 'eo-chk-fecha', ok: !!fecha },
            { id: 'eo-chk-ht', ok: !!totalHt },
            { id: 'eo-chk-ttc', ok: !!totalTtc }
        ];

        let ready = 0;
        checks.forEach(c => {
            const el = document.getElementById(c.id);
            if (el) {
                el.className = 'eo-chk ' + (c.ok ? 'eo-chk-ok' : 'eo-chk-pending');
                el.textContent = c.ok ? '✓' : '○';
            }
            if (c.ok) ready++;
        });

        const btn = document.getElementById('eo-btn-generate');
        const counter = document.getElementById('eo-readiness');
        if (btn) {
            btn.disabled = ready < 5;
            btn.classList.toggle('eo-btn-ready', ready === 5);
        }
        if (counter) {
            counter.textContent = ready + '/5';
            counter.className = 'eo-readiness ' + (ready === 5 ? 'eo-readiness-ok' : '');
        }
    }

    // ---- Renderizar lista de selecciones en sidebar ----
    function renderSelections() {
        const container = document.getElementById('eo-selections-list');
        const countBadge = document.getElementById('eo-selection-count');
        let allSelections = [];

        state.pages.forEach((page, pageIdx) => {
            page.selections.forEach((sel, selIdx) => {
                allSelections.push({ ...sel, pageIdx, selIdx });
            });
        });

        countBadge.textContent = allSelections.length;

        if (allSelections.length === 0) {
            container.innerHTML = '<div class="eo-empty-selections">Sin selecciones aún</div>';
            updateReadiness();
            return;
        }

        let html = '';
        allSelections.forEach(sel => {
            html += `<div class="eo-sel-item">
                <div class="eo-sel-header">
                    <div class="eo-sel-label">
                        <span class="eo-sel-color" style="background:${sel.color}"></span>
                        ${sel.label}
                        <span class="eo-sel-page">Pág. ${sel.pageIdx + 1}</span>
                    </div>
                    <button class="eo-sel-delete" onclick="EasyOcr.deleteSelection(${sel.pageIdx}, ${sel.selIdx})" title="Eliminar">✕</button>
                </div>
                <input type="text" class="eo-sel-input" 
                    data-page="${sel.pageIdx}" 
                    data-sel="${sel.selIdx}"
                    value="${(sel.text || '').replace(/"/g, '&quot;')}"
                    onchange="EasyOcr.updateSelectionText(${sel.pageIdx}, ${sel.selIdx}, this.value)"
                    oninput="EasyOcr.updateSelectionText(${sel.pageIdx}, ${sel.selIdx}, this.value)">
            </div>`;
        });

        container.innerHTML = html;
        updateReadiness();
    }

    // ---- Actualizar texto de selección ----
    function updateSelectionText(pageIdx, selIdx, value) {
        if (state.pages[pageIdx] && state.pages[pageIdx].selections[selIdx]) {
            state.pages[pageIdx].selections[selIdx].text = value;
            updateReadiness();
        }
    }

    // ---- Eliminar selección ----
    function deleteSelection(pageIdx, selIdx) {
        pushHistory();
        state.pages[pageIdx].selections.splice(selIdx, 1);
        redrawPage(pageIdx);
        renderSelections();
        renderTags();
    }

    // ---- Dibujar rectángulos y handles sobre canvas ----
    function redrawPage(pageIdx) {
        const page = state.pages[pageIdx];
        if (!page || !page.baseImage) return;

        page.ctx.clearRect(0, 0, page.canvas.width, page.canvas.height);
        page.ctx.drawImage(page.baseImage, 0, 0);

        page.selections.forEach(sel => {
            page.ctx.fillStyle = hexToRgba(sel.color, 0.25);
            page.ctx.fillRect(sel.startX, sel.startY, sel.width, sel.height);

            page.ctx.strokeStyle = sel.color;
            page.ctx.lineWidth = 2;
            page.ctx.strokeRect(sel.startX, sel.startY, sel.width, sel.height);

            const labelH = 18;
            page.ctx.fillStyle = sel.color;
            page.ctx.font = 'bold 11px sans-serif';
            const textW = page.ctx.measureText(sel.label).width + 12;
            page.ctx.fillRect(sel.startX, sel.startY - labelH, textW, labelH);
            page.ctx.fillStyle = '#fff';
            page.ctx.fillText(sel.label, sel.startX + 6, sel.startY - 5);

            const hs = 6;
            page.ctx.fillStyle = sel.color;
            [[sel.startX, sel.startY],
             [sel.startX + sel.width, sel.startY],
             [sel.startX, sel.startY + sel.height],
             [sel.startX + sel.width, sel.startY + sel.height]].forEach(([hx, hy]) => {
                page.ctx.fillRect(hx - hs/2, hy - hs/2, hs, hs);
            });
        });
    }

    // ---- Configurar interacción del canvas ----
    function setupCanvasInteraction(pageIdx) {
        const page = state.pages[pageIdx];
        const canvas = page.canvas;

        let resizing = null;
        let moving = null;

        function getScaleRatio() {
            return canvas.width / canvas.offsetWidth;
        }

        function getMousePos(e) {
            const rect = canvas.getBoundingClientRect();
            const ratio = getScaleRatio();
            return {
                x: (e.clientX - rect.left) * ratio,
                y: (e.clientY - rect.top) * ratio
            };
        }

        function getHandleAt(x, y) {
            const tol = 10;
            for (let i = page.selections.length - 1; i >= 0; i--) {
                const s = page.selections[i];
                const corners = [
                    { type: 'nw', cx: s.startX, cy: s.startY },
                    { type: 'ne', cx: s.startX + s.width, cy: s.startY },
                    { type: 'sw', cx: s.startX, cy: s.startY + s.height },
                    { type: 'se', cx: s.startX + s.width, cy: s.startY + s.height },
                ];
                for (const c of corners) {
                    if (Math.abs(x - c.cx) <= tol && Math.abs(y - c.cy) <= tol) {
                        return { selIdx: i, handle: c.type };
                    }
                }
            }
            return null;
        }

        function getSelectionAt(x, y) {
            for (let i = page.selections.length - 1; i >= 0; i--) {
                const s = page.selections[i];
                if (x >= s.startX && x <= s.startX + s.width && y >= s.startY && y <= s.startY + s.height) {
                    return i;
                }
            }
            return -1;
        }

        canvas.addEventListener('mousedown', function (e) {
            const pos = getMousePos(e);

            const handleInfo = getHandleAt(pos.x, pos.y);
            if (handleInfo) {
                pushHistory();
                const sel = page.selections[handleInfo.selIdx];
                resizing = {
                    selIdx: handleInfo.selIdx,
                    handle: handleInfo.handle,
                    origSel: { startX: sel.startX, startY: sel.startY, width: sel.width, height: sel.height }
                };
                e.preventDefault();
                return;
            }

            if (state.activeTag) {
                pushHistory();
                state.isDrawing = true;
                state.drawStart = { x: pos.x, y: pos.y };
                state.drawPage = pageIdx;
                e.preventDefault();
                return;
            }

            const selIdx = getSelectionAt(pos.x, pos.y);
            if (selIdx >= 0) {
                pushHistory();
                const sel = page.selections[selIdx];
                moving = {
                    selIdx: selIdx,
                    offsetX: pos.x - sel.startX,
                    offsetY: pos.y - sel.startY
                };
                canvas.style.cursor = 'move';
                e.preventDefault();
            }
        });

        canvas.addEventListener('mousemove', function (e) {
            const pos = getMousePos(e);

            if (resizing) {
                const sel = page.selections[resizing.selIdx];
                const orig = resizing.origSel;
                switch (resizing.handle) {
                    case 'se':
                        sel.width = pos.x - sel.startX;
                        sel.height = pos.y - sel.startY;
                        break;
                    case 'sw':
                        sel.width = orig.startX + orig.width - pos.x;
                        sel.startX = pos.x;
                        sel.height = pos.y - sel.startY;
                        break;
                    case 'ne':
                        sel.width = pos.x - sel.startX;
                        sel.height = orig.startY + orig.height - pos.y;
                        sel.startY = pos.y;
                        break;
                    case 'nw':
                        sel.width = orig.startX + orig.width - pos.x;
                        sel.height = orig.startY + orig.height - pos.y;
                        sel.startX = pos.x;
                        sel.startY = pos.y;
                        break;
                }
                redrawPage(pageIdx);
                return;
            }

            if (moving) {
                const sel = page.selections[moving.selIdx];
                sel.startX = pos.x - moving.offsetX;
                sel.startY = pos.y - moving.offsetY;
                redrawPage(pageIdx);
                return;
            }

            if (state.isDrawing && state.drawPage === pageIdx) {
                redrawPage(pageIdx);
                const w = pos.x - state.drawStart.x;
                const h = pos.y - state.drawStart.y;
                page.ctx.strokeStyle = state.activeTag ? state.activeTag.color : '#333';
                page.ctx.lineWidth = 2;
                page.ctx.setLineDash([6, 3]);
                page.ctx.strokeRect(state.drawStart.x, state.drawStart.y, w, h);
                page.ctx.setLineDash([]);
                page.ctx.fillStyle = state.activeTag ? hexToRgba(state.activeTag.color, 0.15) : 'rgba(0,0,0,0.05)';
                page.ctx.fillRect(state.drawStart.x, state.drawStart.y, w, h);
                return;
            }

            // Cursor hover
            const hInfo = getHandleAt(pos.x, pos.y);
            if (hInfo) {
                const cursors = { nw: 'nw-resize', ne: 'ne-resize', sw: 'sw-resize', se: 'se-resize' };
                canvas.style.cursor = cursors[hInfo.handle];
            } else if (getSelectionAt(pos.x, pos.y) >= 0 && !state.activeTag) {
                canvas.style.cursor = 'move';
            } else {
                canvas.style.cursor = state.activeTag ? 'crosshair' : 'default';
            }
        });

        canvas.addEventListener('mouseup', function (e) {
            const pos = getMousePos(e);

            // Finalizar redimensionamiento — BUGFIX: usar resizing.selIdx
            if (resizing) {
                const correctIdx = resizing.selIdx;
                normalizeSelection(page.selections[correctIdx]);
                resizing = null;
                extractTextForSelection(pageIdx, correctIdx);
                redrawPage(pageIdx);
                renderSelections();
                return;
            }

            // Finalizar movimiento — BUGFIX: usar moving.selIdx
            if (moving) {
                const correctIdx = moving.selIdx;
                moving = null;
                canvas.style.cursor = state.activeTag ? 'crosshair' : 'default';
                extractTextForSelection(pageIdx, correctIdx);
                redrawPage(pageIdx);
                renderSelections();
                return;
            }

            // Finalizar dibujo
            if (state.isDrawing && state.drawPage === pageIdx) {
                state.isDrawing = false;
                let w = pos.x - state.drawStart.x;
                let h = pos.y - state.drawStart.y;

                if (Math.abs(w) < 10 || Math.abs(h) < 10) {
                    redrawPage(pageIdx);
                    return;
                }

                let startX = state.drawStart.x;
                let startY = state.drawStart.y;
                if (w < 0) { startX += w; w = Math.abs(w); }
                if (h < 0) { startY += h; h = Math.abs(h); }

                const tag = state.activeTag;
                const selIdx = page.selections.length;

                page.selections.push({
                    objectNum: pageIdx,
                    startX: startX,
                    startY: startY,
                    width: w,
                    height: h,
                    color: tag.color,
                    label: tag.label,
                    text: ''
                });

                state.activeTag = null;
                renderTags();
                updateCanvasCursors();
                redrawPage(pageIdx);
                renderSelections();
                extractTextForSelection(pageIdx, selIdx);
            }
        });

        // mouseleave: completar dibujo en vez de cancelar
        canvas.addEventListener('mouseleave', function (e) {
            if (state.isDrawing && state.drawPage === pageIdx) {
                // Simular mouseup con última posición conocida
                const pos = getMousePos(e);
                const fakeEvent = { clientX: e.clientX, clientY: e.clientY };
                canvas.dispatchEvent(new MouseEvent('mouseup', {
                    clientX: e.clientX,
                    clientY: e.clientY
                }));
            }
        });
    }

    function normalizeSelection(sel) {
        if (sel.width < 0) {
            sel.startX += sel.width;
            sel.width = Math.abs(sel.width);
        }
        if (sel.height < 0) {
            sel.startY += sel.height;
            sel.height = Math.abs(sel.height);
        }
    }

    // ---- Extracción de texto nativo con PDF.js ----
    const textCache = {};

    function getPageTextItems(pageIdx) {
        return new Promise((resolve) => {
            if (textCache[pageIdx]) {
                resolve(textCache[pageIdx]);
                return;
            }
            const page = state.pages[pageIdx];
            page.pdfPage.getTextContent().then(textContent => {
                const scale = state.scale;
                const mapped = textContent.items.map(item => {
                    const tx = item.transform[4] * scale;
                    const ty = page.viewport.height - (item.transform[5] * scale);
                    const tw = (item.width || 0) * scale;
                    const th = Math.abs(item.transform[0]) * scale;
                    return {
                        str: item.str,
                        left: tx,
                        top: ty - th,
                        right: tx + tw,
                        bottom: ty,
                        width: tw,
                        height: th
                    };
                }).filter(m => m.str.trim().length > 0);
                textCache[pageIdx] = mapped;
                resolve(mapped);
            }).catch(() => {
                textCache[pageIdx] = [];
                resolve([]);
            });
        });
    }

    function extractTextForSelection(pageIdx, selIdx) {
        const page = state.pages[pageIdx];
        const sel = page.selections[selIdx];
        if (!sel) return;

        showLoader();

        const selLeft = sel.startX;
        const selTop = sel.startY;
        const selRight = sel.startX + sel.width;
        const selBottom = sel.startY + sel.height;

        getPageTextItems(pageIdx).then(items => {
            const hits = [];
            for (const item of items) {
                const overlapX = Math.max(0, Math.min(item.right, selRight) - Math.max(item.left, selLeft));
                const overlapY = Math.max(0, Math.min(item.bottom, selBottom) - Math.max(item.top, selTop));
                if (overlapX > 0 && overlapY > 0) {
                    if (item.width > 0 && item.str.length > 0) {
                        const charWidth = item.width / item.str.length;
                        let partial = '';
                        for (let ci = 0; ci < item.str.length; ci++) {
                            const charLeft = item.left + charWidth * ci;
                            const charRight = charLeft + charWidth;
                            if (charRight > selLeft && charLeft < selRight) {
                                partial += item.str[ci];
                            }
                        }
                        if (partial) hits.push({ text: partial, top: item.top, left: item.left });
                    } else if (item.str.trim()) {
                        hits.push({ text: item.str, top: item.top, left: item.left });
                    }
                }
            }
            hits.sort((a, b) => {
                const rowDiff = a.top - b.top;
                if (Math.abs(rowDiff) > 5) return rowDiff;
                return a.left - b.left;
            });
            sel.text = hits.map(h => h.text).join(' ').trim();
            hideLoader();
            renderSelections();
        });
    }

    // ---- Zoom ----
    function setZoom(newScale) {
        if (!state.pdfDoc || !state.pdfArrayBuffer) return;
        newScale = Math.max(0.5, Math.min(3, newScale));
        if (newScale === state.scale) return;

        // Guardar selecciones relativas al scale anterior
        const oldScale = state.scale;
        const ratio = newScale / oldScale;
        state.pages.forEach(page => {
            page.selections.forEach(sel => {
                sel.startX *= ratio;
                sel.startY *= ratio;
                sel.width *= ratio;
                sel.height *= ratio;
            });
        });

        state.scale = newScale;

        // Limpiar cache de texto (depende del scale)
        Object.keys(textCache).forEach(k => delete textCache[k]);

        // Actualizar label de zoom
        const zoomLabel = document.getElementById('eo-zoom-label');
        if (zoomLabel) zoomLabel.textContent = Math.round(newScale * 100) + '%';

        // Re-renderizar
        reRenderPages();
    }

    function zoomIn() { setZoom(state.scale + 0.25); }
    function zoomOut() { setZoom(state.scale - 0.25); }

    function reRenderPages() {
        if (!state.pdfDoc) return;
        showLoader();

        const container = document.getElementById('canvas-container');
        container.innerHTML = '';
        let rendered = 0;

        for (let num = 1; num <= state.pdfDoc.numPages; num++) {
            state.pdfDoc.getPage(num).then(function (pdfPage) {
                const viewport = pdfPage.getViewport({ scale: state.scale });
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                canvas.width = viewport.width;
                canvas.height = viewport.height;
                canvas.style.width = viewport.width + 'px';
                canvas.style.height = viewport.height + 'px';
                canvas.dataset.pageIdx = num - 1;
                container.appendChild(canvas);

                const pageObj = state.pages[num - 1];
                pageObj.pdfPage = pdfPage;
                pageObj.viewport = viewport;
                pageObj.canvas = canvas;
                pageObj.ctx = ctx;
                pageObj.baseImage = null;

                pdfPage.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
                    const img = new Image();
                    img.onload = function () {
                        pageObj.baseImage = img;
                        setupCanvasInteraction(num - 1);
                        redrawPage(num - 1);
                    };
                    img.src = canvas.toDataURL();

                    rendered++;
                    if (rendered === state.pdfDoc.numPages) {
                        hideLoader();
                    }
                });
            });
        }
    }

    // ---- Indicador de página activa ----
    function setupPageObserver() {
        const container = document.getElementById('eo-canvas-area');
        const label = document.getElementById('eo-page-indicator');
        if (!label || !container) return;

        container.addEventListener('scroll', function () {
            const canvases = document.querySelectorAll('#canvas-container canvas');
            if (canvases.length === 0) return;

            const containerRect = container.getBoundingClientRect();
            const mid = containerRect.top + containerRect.height / 2;
            let closest = 0;
            let closestDist = Infinity;

            canvases.forEach((c, i) => {
                const rect = c.getBoundingClientRect();
                const center = rect.top + rect.height / 2;
                const dist = Math.abs(center - mid);
                if (dist < closestDist) {
                    closestDist = dist;
                    closest = i;
                }
            });

            label.textContent = 'Pág. ' + (closest + 1) + ' / ' + canvases.length;
            label.style.display = canvases.length > 1 ? '' : 'none';
        });
    }

    // ---- Carga de PDF ----
    function loadPDF(file) {
        showLoader();
        state.file = file;
        state.pages = [];
        state.activeTag = null;
        state.templateId = null;
        history.length = 0;
        Object.keys(textCache).forEach(k => delete textCache[k]);

        document.getElementById('eo-filename').textContent = file.name;
        document.getElementById('eo-empty-state').style.display = 'none';
        document.getElementById('canvas-container').innerHTML = '';

        const reader = new FileReader();
        reader.onload = function () {
            state.pdfArrayBuffer = this.result;
            const typedarray = new Uint8Array(this.result);

            pdfjsLib.getDocument(typedarray).promise.then(function (pdf) {
                state.pdfDoc = pdf;
                let rendered = 0;

                pdf.getMetadata().then(function (meta) {
                    displayPdfMetadata(meta);
                }).catch(function () {
                    displayPdfMetadata(null);
                });

                // Mostrar controles de zoom
                const zoomControls = document.getElementById('eo-zoom-controls');
                if (zoomControls) zoomControls.style.display = 'flex';
                const zoomLabel = document.getElementById('eo-zoom-label');
                if (zoomLabel) zoomLabel.textContent = Math.round(state.scale * 100) + '%';

                for (let num = 1; num <= pdf.numPages; num++) {
                    pdf.getPage(num).then(function (pdfPage) {
                        const viewport = pdfPage.getViewport({ scale: state.scale });
                        const canvas = document.createElement('canvas');
                        const ctx = canvas.getContext('2d');

                        canvas.width = viewport.width;
                        canvas.height = viewport.height;
                        canvas.style.width = viewport.width + 'px';
                        canvas.style.height = viewport.height + 'px';
                        canvas.dataset.pageIdx = num - 1;
                        document.getElementById('canvas-container').appendChild(canvas);

                        const pageObj = {
                            pageNum: num,
                            pdfPage: pdfPage,
                            viewport: viewport,
                            canvas: canvas,
                            ctx: ctx,
                            baseImage: null,
                            selections: []
                        };

                        state.pages[num - 1] = pageObj;

                        pdfPage.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
                            const img = new Image();
                            img.onload = function () {
                                pageObj.baseImage = img;
                                setupCanvasInteraction(num - 1);
                            };
                            img.src = canvas.toDataURL();

                            rendered++;
                            if (rendered === pdf.numPages) {
                                hideLoader();
                                loadInitialData();
                                renderTags();
                                renderSelections();
                                setupPageObserver();
                            }
                        });
                    });
                }
            }).catch(function (err) {
                hideLoader();
                toast('Error al cargar el PDF: ' + err.message, 'error');
            });
        };
        reader.readAsArrayBuffer(file);
    }

    // ---- AJAX: Cargar datos iniciales ----
    function loadInitialData() {
        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: { action: "getDetails" },
            success: function (data) {
                state.suppliersData = data.suppliers || [];
                state.templatesData = data.templates || [];
                state.banksData = data.banks || [];
                state.paymentTypesData = data.payment_types || [];

                const supplierSelect = document.getElementById('eo-supplier');
                const tplSupplierSelect = document.getElementById('eo-template-supplier');
                supplierSelect.innerHTML = '<option value="">Selecciona proveedor</option>';
                tplSupplierSelect.innerHTML = '<option value="">Sin proveedor (genérico)</option>';
                state.suppliersData.forEach(s => {
                    supplierSelect.innerHTML += `<option value="${s.rowid}">${s.nom}</option>`;
                    tplSupplierSelect.innerHTML += `<option value="${s.rowid}">${s.nom}</option>`;
                });

                const tplSelect = document.getElementById('eo-template-select');
                tplSelect.innerHTML = '<option value="">Sin plantilla</option>';
                state.templatesData.forEach(t => {
                    const selected = state.templateId && t.rowid === state.templateId ? ' selected' : '';
                    const suffix = t.supplier_name ? ` (${t.supplier_name})` : '';
                    tplSelect.innerHTML += `<option value="${t.rowid}"${selected} data-fk-soc="${t.fk_soc || ''}">${t.name}${suffix}</option>`;
                });

                // Poblar selector de cuentas bancarias
                const bankSelect = document.getElementById('eo-payment-bank');
                if (bankSelect) {
                    bankSelect.innerHTML = '<option value="">Selecciona cuenta bancaria</option>';
                    state.banksData.forEach(b => {
                        const curr = b.currency_code ? ` (${b.currency_code})` : '';
                        const num = b.number ? ` - ${b.number}` : '';
                        bankSelect.innerHTML += `<option value="${b.rowid}">${b.label}${num}${curr}</option>`;
                    });
                }

                // Poblar selector de tipos de pago
                const paymentTypeSelect = document.getElementById('eo-payment-type');
                if (paymentTypeSelect) {
                    paymentTypeSelect.innerHTML = '<option value="">Selecciona modo de pago</option>';
                    // Usar un Set para evitar duplicados por si acaso
                    const uniquePaymentTypes = new Map();
                    state.paymentTypesData.forEach(pt => {
                        if (!uniquePaymentTypes.has(pt.id)) {
                            uniquePaymentTypes.set(pt.id, pt);
                        }
                    });
                    // Agregar las opciones únicas
                    uniquePaymentTypes.forEach(pt => {
                        paymentTypeSelect.innerHTML += `<option value="${pt.id}">${pt.label}</option>`;
                    });
                }

                initSelect2();
                updateReadiness();
            }
        });
    }

    // ---- Auto-detección de plantilla por proveedor ----
    function onSupplierChange() {
        const supplierId = $('#eo-supplier').val();
        if (!supplierId || state.templateId) return;

        // Buscar plantilla asociada al proveedor
        const match = state.templatesData.find(t => t.fk_soc && String(t.fk_soc) === String(supplierId));
        if (match && state.pages.length > 0) {
            $('#eo-template-select').val(match.rowid).trigger('change');
            toast('Plantilla "' + match.name + '" detectada para este proveedor', 'success');
            loadTemplate();
        }
        updateReadiness();
    }

    // ---- Plantillas ----
    function loadTemplate() {
        const tplId = $('#eo-template-select').val();
        if (!tplId) {
            toast('Selecciona una plantilla primero', 'warn');
            return;
        }

        showLoader();
        pushHistory();
        state.templateId = tplId;

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: { action: "getDetailsTemplate", template_id: tplId },
            success: function (data) {
                if (data.fk_soc) {
                    $('#eo-supplier').val(data.fk_soc).trigger('change.select2');
                }

                if (data.details && data.details.length > 0) {
                    state.pages.forEach(p => p.selections = []);
                    let pending = data.details.length;

                    data.details.forEach(item => {
                        const pageIdx = parseInt(item.objectNum);
                        if (state.pages[pageIdx]) {
                            const page = state.pages[pageIdx];
                            const selIdx = page.selections.length;

                            page.selections.push({
                                objectNum: pageIdx,
                                startX: parseInt(item.startX),
                                startY: parseInt(item.startY),
                                width: parseInt(item.width),
                                height: parseInt(item.height),
                                color: item.color,
                                label: item.label,
                                text: ''
                            });

                            extractTextForSelection(pageIdx, selIdx);
                        }

                        pending--;
                        if (pending <= 0) {
                            state.pages.forEach((p, i) => redrawPage(i));
                            renderTags();
                            renderSelections();
                            updateTemplateButtons();
                            hideLoader();
                        }
                    });
                } else {
                    hideLoader();
                    toast('La plantilla no tiene selecciones', 'warn');
                }
            },
            error: function () {
                hideLoader();
                toast('Error al cargar la plantilla', 'error');
            }
        });
    }

    function clearTemplate() {
        pushHistory();
        state.templateId = null;
        $('#eo-template-select').val('').trigger('change');
        state.pages.forEach((p, i) => {
            p.selections = [];
            redrawPage(i);
        });
        renderTags();
        renderSelections();
        updateTemplateButtons();
    }

    function updateTemplateButtons() {
        const saveBtn = document.getElementById('eo-btn-save-tpl');
        const editBtn = document.getElementById('eo-btn-edit-tpl');
        const clearBtn = document.getElementById('eo-btn-clear-tpl');

        if (state.templateId) {
            saveBtn.style.display = 'none';
            editBtn.style.display = '';
            clearBtn.style.display = '';
        } else {
            saveBtn.style.display = '';
            editBtn.style.display = 'none';
            clearBtn.style.display = 'none';
        }
    }

    function showSaveTemplate() {
        document.getElementById('eo-template-name').value = '';
        const currentSupplier = $('#eo-supplier').val();
        $('#eo-template-supplier').val(currentSupplier).trigger('change');
        showModal('eo-modal-template');
    }

    function hideSaveTemplate() {
        hideModal('eo-modal-template');
    }

    function saveTemplate() {
        const name = document.getElementById('eo-template-name').value.trim();
        if (!name) {
            toast('Ingresa un nombre para la plantilla', 'error');
            return;
        }

        const supplier = $('#eo-template-supplier').val();
        showLoader();
        const details = getAllSelections();

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: {
                action: "addTemplate",
                name: name,
                fk_soc: supplier,
                selections: JSON.stringify(details)
            },
            success: function (data) {
                hideLoader();
                if (data.status === 'ok') {
                    hideSaveTemplate();
                    loadInitialData();
                    toast('Plantilla guardada correctamente');
                }
            },
            error: function () {
                hideLoader();
                toast('Error al guardar la plantilla', 'error');
            }
        });
    }

    function editTemplate() {
        if (!state.templateId) return;

        showLoader();
        const details = getAllSelections();
        const supplier = $('#eo-supplier').val();

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: {
                action: "editTemplate",
                template_id: state.templateId,
                fk_soc: supplier,
                selections: JSON.stringify(details)
            },
            success: function (data) {
                hideLoader();
                if (data.status === 'ok') {
                    toast('Plantilla actualizada correctamente');
                }
            },
            error: function () {
                hideLoader();
                toast('Error al editar la plantilla', 'error');
            }
        });
    }

    function getAllSelections() {
        const all = [];
        state.pages.forEach(page => {
            page.selections.forEach(sel => {
                all.push({ ...sel });
            });
        });
        return all;
    }

    // ---- Generar factura ----
    function generateInvoice() {
        syncSelectionTexts();

        const supplier = $('#eo-supplier').val();
        const supplierName = $('#eo-supplier option:selected').text();
        const factura = getSelectionValue('Factura');
        const fecha = getSelectionValue('Con fecha de');
        const totalTtc = getSelectionValue('Precio total');
        const totalHt = getSelectionValue('HT totales');

        if (!supplier || !factura || !fecha || !totalTtc || !totalHt) {
            toast('Completa todos los campos antes de generar', 'error');
            return;
        }

        // Modal de confirmación
        const confirmHtml = `
            <div class="eo-confirm-grid">
                <div class="eo-confirm-row"><span class="eo-confirm-label">Proveedor:</span><span class="eo-confirm-value">${supplierName}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">Nº Factura:</span><span class="eo-confirm-value">${factura}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">Fecha:</span><span class="eo-confirm-value">${fecha}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">Base imponible:</span><span class="eo-confirm-value">${totalHt}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">Total TTC:</span><span class="eo-confirm-value">${totalTtc}</span></div>
            </div>`;

        document.getElementById('eo-confirm-body').innerHTML = confirmHtml;
        showModal('eo-modal-confirm');
    }

    function confirmGenerateInvoice() {
        // Validar pago si está activado
        const createPayment = document.getElementById('eo-create-payment').checked;
        if (createPayment) {
            const bankId = $('#eo-payment-bank').val();
            const paymentTypeId = $('#eo-payment-type').val();
            if (!bankId) {
                toast('Selecciona una cuenta bancaria para el pago', 'error');
                return;
            }
            if (!paymentTypeId) {
                toast('Selecciona un modo de pago', 'error');
                return;
            }
        }

        hideModal('eo-modal-confirm');
        showLoader();

        const formData = new FormData();
        formData.append('action', 'newInvoice');
        formData.append('file', state.file);
        formData.append('fk_soc', $('#eo-supplier').val());
        formData.append('ref_supplier', getSelectionValue('Factura'));
        formData.append('datef', getSelectionValue('Con fecha de'));
        formData.append('total_ttc', getSelectionValue('Precio total'));
        formData.append('total_ht', getSelectionValue('HT totales'));

        // Datos de pago
        if (createPayment) {
            formData.append('create_payment', '1');
            formData.append('payment_bank_id', $('#eo-payment-bank').val());
            formData.append('payment_type_id', $('#eo-payment-type').val());
        }

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            success: function (data) {
                hideLoader();
                if (data.status === 'ok') {
                    showInvoicePreview(data.id, data.ref || '');
                    toast('Factura creada correctamente — puedes cargar otro PDF', 'success');
                    resetWorkspace();
                } else if (data.status === 'repeat') {
                    toast('Esta factura ya existe en el sistema', 'warn');
                }
            },
            error: function () {
                hideLoader();
                toast('Error al generar la factura', 'error');
            }
        });
    }

    function syncSelectionTexts() {
        document.querySelectorAll('.eo-sel-input').forEach(input => {
            const pageIdx = parseInt(input.dataset.page);
            const selIdx = parseInt(input.dataset.sel);
            if (state.pages[pageIdx] && state.pages[pageIdx].selections[selIdx]) {
                state.pages[pageIdx].selections[selIdx].text = input.value;
            }
        });
    }

    // ---- Toggle opciones de pago ----
    function togglePaymentOptions() {
        const checked = document.getElementById('eo-create-payment').checked;
        document.getElementById('eo-payment-options').style.display = checked ? 'block' : 'none';
    }

    function getSelectionValue(label) {
        for (const page of state.pages) {
            for (const sel of page.selections) {
                if (sel.label === label) return sel.text;
            }
        }
        return '';
    }

    // ---- Inicialización ----
    function init() {
        // Upload
        document.getElementById('pdfInput').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.type !== 'application/pdf') {
                toast('Por favor selecciona un archivo PDF', 'error');
                return;
            }
            loadPDF(file);
        });

        // Hacer clickeable el empty state
        const emptyState = document.getElementById('eo-empty-state');
        if (emptyState) {
            emptyState.addEventListener('click', function() {
                document.getElementById('pdfInput').click();
            });
        }

        // Drag & drop
        const canvasArea = document.getElementById('eo-canvas-area');
        let dragCounter = 0;

        canvasArea.addEventListener('dragenter', function (e) {
            e.preventDefault();
            dragCounter++;
            canvasArea.classList.add('eo-drag-over');
        });
        canvasArea.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        canvasArea.addEventListener('dragleave', function () {
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                canvasArea.classList.remove('eo-drag-over');
            }
        });
        canvasArea.addEventListener('drop', function (e) {
            e.preventDefault();
            dragCounter = 0;
            canvasArea.classList.remove('eo-drag-over');
            const file = e.dataTransfer.files[0];
            if (file && file.type === 'application/pdf') {
                loadPDF(file);
            } else {
                toast('Solo se aceptan archivos PDF', 'error');
            }
        });

        // Atajos de teclado
        document.addEventListener('keydown', function (e) {
            // Ignorar si estamos en un input/textarea/select
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            // 1-4: Seleccionar etiqueta
            if (e.key >= '1' && e.key <= '4') {
                e.preventDefault();
                selectTag(parseInt(e.key) - 1);
                return;
            }

            // Escape: deseleccionar etiqueta o cerrar modal
            if (e.key === 'Escape') {
                if (document.getElementById('eo-modal-confirm').style.display === 'flex') {
                    hideModal('eo-modal-confirm');
                    return;
                }
                if (document.getElementById('eo-modal-template').style.display === 'flex') {
                    hideSaveTemplate();
                    return;
                }
                if (state.activeTag) {
                    state.activeTag = null;
                    renderTags();
                    updateCanvasCursors();
                    return;
                }
            }

            // Ctrl+Z: Deshacer
            if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
                e.preventDefault();
                undo();
                return;
            }

            // Ctrl+S: Guardar plantilla
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                if (state.pages.length > 0 && getAllSelections().length > 0) {
                    e.preventDefault();
                    if (state.templateId) {
                        editTemplate();
                    } else {
                        showSaveTemplate();
                    }
                }
                return;
            }

            // Ctrl+Enter: Generar factura
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                generateInvoice();
                return;
            }
        });

        // Listener para auto-detección de plantilla al cambiar proveedor
        $(document).on('change', '#eo-supplier', function () {
            onSupplierChange();
        });

        renderTags();
        updateTemplateButtons();
        initSelect2();
        updateReadiness();
    }

    // ---- Select2 ----
    function initSelect2() {
        if (typeof $.fn.select2 === 'undefined') return;
        $('.eo-select').each(function () {
            if ($(this).data('select2')) {
                $(this).select2('destroy');
            }
            $(this).select2({
                width: '100%',
                dropdownAutoWidth: true,
                dropdownParent: $(this).closest('.eo-modal') .length ? $(this).closest('.eo-modal') : $(document.body)
            });
        });
    }

    // ---- Metadatos PDF ----
    function displayPdfMetadata(meta) {
        const container = document.getElementById('eo-metadata-content');
        const section = document.getElementById('eo-metadata-section');
        if (!container || !section) return;

        section.style.display = 'block';
        // Start collapsed by default
        const title = section.querySelector('.eo-collapsible');
        if (title && !title.classList.contains('eo-collapsed')) {
            title.classList.add('eo-collapsed');
            container.classList.add('eo-hidden');
        }

        if (!meta || !meta.info) {
            container.innerHTML = '<span class="eo-meta-empty">Sin metadatos disponibles</span>';
            return;
        }

        const info = meta.info;
        const knownFields = [
            { key: 'Title', label: 'Título' },
            { key: 'Author', label: 'Autor' },
            { key: 'Subject', label: 'Asunto' },
            { key: 'Creator', label: 'Creador' },
            { key: 'Producer', label: 'Productor' },
            { key: 'CreationDate', label: 'Fecha creación' },
            { key: 'ModDate', label: 'Fecha modificación' },
            { key: 'Keywords', label: 'Palabras clave' },
            { key: 'Trapped', label: 'Trapped' }
        ];
        const knownKeys = knownFields.map(f => f.key);

        let html = '';
        let hasData = false;

        knownFields.forEach(f => {
            if (info[f.key]) {
                hasData = true;
                let val = String(info[f.key]);
                if ((f.key === 'CreationDate' || f.key === 'ModDate') && val.startsWith('D:')) {
                    val = val.substring(2, 10);
                    val = val.substring(0, 4) + '-' + val.substring(4, 6) + '-' + val.substring(6, 8);
                }
                html += `<div class="eo-meta-row"><span class="eo-meta-label">${f.label}:</span><span class="eo-meta-value">${val}</span></div>`;
            }
        });

        Object.keys(info).forEach(key => {
            if (knownKeys.indexOf(key) === -1 && key !== 'PDFFormatVersion' && key !== 'IsLinearized' && key !== 'IsAcroFormPresent' && key !== 'IsXFAPresent' && key !== 'IsCollectionPresent') {
                hasData = true;
                html += `<div class="eo-meta-row"><span class="eo-meta-label">${key}:</span><span class="eo-meta-value">${String(info[key])}</span></div>`;
            }
        });

        html += `<div class="eo-meta-row"><span class="eo-meta-label">Versión PDF:</span><span class="eo-meta-value">${info.PDFFormatVersion || '—'}</span></div>`;

        if (meta.metadata) {
            const xmpFields = [
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'creator', label: 'XMP Autor' },
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'description', label: 'XMP Descripción' },
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'title', label: 'XMP Título' },
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'subject', label: 'XMP Asunto' }
            ];
            xmpFields.forEach(f => {
                try {
                    const val = meta.metadata.get(f.ns + f.key);
                    if (val) {
                        hasData = true;
                        html += `<div class="eo-meta-row"><span class="eo-meta-label">${f.label}:</span><span class="eo-meta-value">${val}</span></div>`;
                    }
                } catch (e) { /* XMP field not available */ }
            });
        }

        if (state.pdfDoc) {
            html += `<div class="eo-meta-row"><span class="eo-meta-label">Páginas:</span><span class="eo-meta-value">${state.pdfDoc.numPages}</span></div>`;
        }

        container.innerHTML = hasData ? html : '<span class="eo-meta-empty">Sin metadatos relevantes</span>';
    }

    // ---- Preview de factura creada en iframe ----
    function showInvoicePreview(facId, ref) {
        const url = '../../fourn/facture/card.php?mainmenu=billing&facid=' + facId;
        document.getElementById('eo-invoice-iframe').src = url;
        document.getElementById('eo-invoice-link').href = url;
        document.getElementById('eo-invoice-title').textContent = ref ? 'Factura ' + ref + ' creada' : 'Factura creada';
        showModal('eo-modal-invoice');
    }

    function closeInvoicePreview() {
        hideModal('eo-modal-invoice');
        document.getElementById('eo-invoice-iframe').src = 'about:blank';
    }

    // ---- Resetear workspace para siguiente factura ----
    function resetWorkspace() {
        state.file = null;
        state.templateId = null;
        state.pdfDoc = null;
        state.pdfArrayBuffer = null;
        state.activeTag = null;
        state.isDrawing = false;
        state.pages = [];
        history.length = 0;
        Object.keys(textCache).forEach(k => delete textCache[k]);

        // Limpiar UI
        document.getElementById('canvas-container').innerHTML = '';
        document.getElementById('eo-empty-state').style.display = '';
        document.getElementById('eo-filename').textContent = 'Ningún archivo seleccionado';
        document.getElementById('pdfInput').value = '';

        const zoomControls = document.getElementById('eo-zoom-controls');
        if (zoomControls) zoomControls.style.display = 'none';
        const pageInd = document.getElementById('eo-page-indicator');
        if (pageInd) pageInd.style.display = 'none';
        const metaSection = document.getElementById('eo-metadata-section');
        if (metaSection) metaSection.style.display = 'none';

        // Resetear sidebar
        $('#eo-supplier').val('').trigger('change');
        $('#eo-template-select').val('').trigger('change');

        // Resetear opciones de pago
        const paymentCheckbox = document.getElementById('eo-create-payment');
        if (paymentCheckbox) {
            paymentCheckbox.checked = false;
            document.getElementById('eo-payment-options').style.display = 'none';
        }

        renderTags();
        renderSelections();
        updateTemplateButtons();
        updateReadiness();
    }

    // Arrancar
    document.addEventListener('DOMContentLoaded', init);

    // ---- API pública ----
    return {
        selectTag,
        deleteSelection,
        updateSelectionText,
        loadTemplate,
        clearTemplate,
        showSaveTemplate,
        hideSaveTemplate,
        saveTemplate,
        editTemplate,
        generateInvoice,
        confirmGenerateInvoice,
        closeInvoicePreview,
        togglePaymentOptions,
        undo,
        zoomIn,
        zoomOut,
    };

})();
