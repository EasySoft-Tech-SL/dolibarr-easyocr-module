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

    // Translations (set by scripts.js.php before this code)
    const L = window.EasyOcrLang || {};

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
        pdfArrayBuffer: null, // Para re-render en zoom
        aiEnabled: false,     // AI OCR habilitado
        aiResult: null,       // Último resultado AI OCR
        defaultTaxRate: 0,    // Tasa IVA por defecto del documento (de totals.taxes)
        customInstructions: '' // Instrucciones personalizadas para IA (por plantilla/proveedor)
    };

    // Historial para undo
    const history = [];
    const MAX_HISTORY = 30;

    // Etiquetas disponibles
    const tags = [
        { label: L.labelDate || "Invoice date", color: "#8a27b2", key: "Confechade" },
        { label: L.labelInvoice || "Invoice", color: "#1c7cff", key: "Factura" },
        { label: L.labelHT || "Total excl. tax", color: "#e51515", key: "HTtotales" },
        { label: L.labelTTC || "Total price", color: "#e515b3", key: "Preciototal" },
        { label: L.labelIVA || "Tax amount", color: "#ff6b35", key: "IVA" },
        { label: L.labelDesc || "Description", color: "#27ae60", key: "Descripcion" },
        { label: L.labelCIF || "Tax ID", color: "#16a085", key: "CIFNIF" },
        { label: L.labelDueDate || "Due date", color: "#f39c12", key: "Vencimiento" },
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
            toast(L.nothingToUndo, 'warn');
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
        toast(L.actionUndone);
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
        const factura = getSelectionValue(L.labelInvoice);
        const fecha = getSelectionValue(L.labelDate);
        const totalTtc = getSelectionValue(L.labelTTC);
        const totalHt = getSelectionValue(L.labelHT);

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
            container.innerHTML = '<div class="eo-empty-selections">' + (L.noSelectionsYet) + '</div>';
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
                        <span class="eo-sel-page">${(L.page || 'Pág.') + ' '}${sel.pageIdx + 1}</span>
                    </div>
                    <button class="eo-sel-delete" onclick="EasyOcr.deleteSelection(${sel.pageIdx}, ${sel.selIdx})" title="${L.deleteSelection || 'Eliminar'}">✕</button>
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
            const closeW = 18;
            page.ctx.fillStyle = sel.color;
            page.ctx.font = 'bold 11px sans-serif';
            const textW = page.ctx.measureText(sel.label).width + 12;
            // Label background + close button area
            page.ctx.fillRect(sel.startX, sel.startY - labelH, textW + closeW, labelH);
            // Label text
            page.ctx.fillStyle = '#fff';
            page.ctx.fillText(sel.label, sel.startX + 6, sel.startY - 5);
            // Separator line
            page.ctx.strokeStyle = 'rgba(255,255,255,0.45)';
            page.ctx.lineWidth = 1;
            page.ctx.beginPath();
            page.ctx.moveTo(sel.startX + textW, sel.startY - labelH + 3);
            page.ctx.lineTo(sel.startX + textW, sel.startY - 3);
            page.ctx.stroke();
            // Close "✕" glyph
            page.ctx.fillStyle = '#fff';
            page.ctx.font = 'bold 12px sans-serif';
            const xGlyph = '✕';
            const xGlyphW = page.ctx.measureText(xGlyph).width;
            page.ctx.fillText(xGlyph, sel.startX + textW + (closeW - xGlyphW) / 2, sel.startY - 4);

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

        function getCloseButtonAt(x, y) {
            const labelH = 18;
            const closeW = 18;
            page.ctx.font = 'bold 11px sans-serif';
            for (let i = page.selections.length - 1; i >= 0; i--) {
                const s = page.selections[i];
                const textW = page.ctx.measureText(s.label).width + 12;
                const bx = s.startX + textW;
                const by = s.startY - labelH;
                if (x >= bx && x <= bx + closeW && y >= by && y <= by + labelH) {
                    return i;
                }
            }
            return -1;
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

            // Close button on label bar
            const closeIdx = getCloseButtonAt(pos.x, pos.y);
            if (closeIdx >= 0) {
                pushHistory();
                page.selections.splice(closeIdx, 1);
                redrawPage(pageIdx);
                renderSelections();
                renderTags();
                e.preventDefault();
                return;
            }

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
            if (getCloseButtonAt(pos.x, pos.y) >= 0) {
                canvas.style.cursor = 'pointer';
            } else {
                const hInfo = getHandleAt(pos.x, pos.y);
                if (hInfo) {
                    const cursors = { nw: 'nw-resize', ne: 'ne-resize', sw: 'sw-resize', se: 'se-resize' };
                    canvas.style.cursor = cursors[hInfo.handle];
                } else if (getSelectionAt(pos.x, pos.y) >= 0 && !state.activeTag) {
                    canvas.style.cursor = 'move';
                } else {
                    canvas.style.cursor = state.activeTag ? 'crosshair' : 'default';
                }
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

            label.textContent = (L.page || 'Pág.') + ' ' + (closest + 1) + ' / ' + canvases.length;
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
                toast(L.errorLoadingPdf + ': ' + err.message, 'error');
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
                state.journalsData = data.journals || [];

                const supplierSelect = document.getElementById('eo-supplier');
                const tplSupplierSelect = document.getElementById('eo-template-supplier');
                supplierSelect.innerHTML = '<option value="">' + (L.selectSupplier || 'Select supplier') + '</option>';
                tplSupplierSelect.innerHTML = '<option value="">' + (L.noSupplierGeneric || 'No supplier (generic)') + '</option>';
                state.suppliersData.forEach(s => {
                    supplierSelect.innerHTML += `<option value="${s.rowid}">${s.nom}</option>`;
                    tplSupplierSelect.innerHTML += `<option value="${s.rowid}">${s.nom}</option>`;
                });

                const tplSelect = document.getElementById('eo-template-select');
                tplSelect.innerHTML = '<option value="">' + (L.noTemplate || 'No template') + '</option>';
                state.templatesData.forEach(t => {
                    const selected = state.templateId && t.rowid === state.templateId ? ' selected' : '';
                    const suffix = t.supplier_name ? ` (${t.supplier_name})` : '';
                    const displayName = t.name && t.name.trim() ? t.name : 'ID: ' + t.rowid;
                    tplSelect.innerHTML += `<option value="${t.rowid}"${selected} data-fk-soc="${t.fk_soc || ''}">${displayName}${suffix}</option>`;
                });

                // Poblar selector de cuentas bancarias
                const bankSelect = document.getElementById('eo-payment-bank');
                if (bankSelect) {
                    bankSelect.innerHTML = '<option value="">' + (L.selectBankAccount || 'Select bank account') + '</option>';
                    state.banksData.forEach(b => {
                        const curr = b.currency_code ? ` (${b.currency_code})` : '';
                        const num = b.number ? ` - ${b.number}` : '';
                        bankSelect.innerHTML += `<option value="${b.rowid}">${b.label}${num}${curr}</option>`;
                    });
                }

                // Poblar selector de tipos de pago
                const paymentTypeSelect = document.getElementById('eo-payment-type');
                if (paymentTypeSelect) {
                    paymentTypeSelect.innerHTML = '<option value="">' + (L.selectPaymentMode || 'Select payment mode') + '</option>';
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

                // Poblar selectores del modal AI (mismas opciones)
                populateAIPaymentSelects();

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
            const templateName = match.name && match.name.trim() ? match.name : 'ID: ' + match.rowid;
            toast((L.templateDetected || 'Template detected: %s').replace('%s', templateName), 'success');
            loadTemplate();
        }
        updateReadiness();
    }

    // ---- Plantillas ----
    function loadTemplate() {
        const tplId = $('#eo-template-select').val();
        if (!tplId) {
            toast(L.selectTemplateFirst || 'Select a template first', 'warn');
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

                // Load custom instructions from template
                state.customInstructions = data.custom_instructions || '';
                updateCustomInstructionsUI();

                if (data.details && data.details.length > 0) {
                    state.pages.forEach(p => p.selections = []);
                    let pending = data.details.length;
                    // Scale ratio: adapt saved coords to current zoom
                    const savedScale = data.scale || 1.5;
                    const ratio = state.scale / savedScale;

                    data.details.forEach(item => {
                        const pageIdx = parseInt(item.objectNum);
                        if (state.pages[pageIdx]) {
                            const page = state.pages[pageIdx];
                            const selIdx = page.selections.length;

                            page.selections.push({
                                objectNum: pageIdx,
                                startX: parseFloat(item.startX) * ratio,
                                startY: parseFloat(item.startY) * ratio,
                                width: parseFloat(item.width) * ratio,
                                height: parseFloat(item.height) * ratio,
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
                    toast(L.templateNoSelections, 'warn');
                }
            },
            error: function () {
                hideLoader();
                toast(L.errorLoadingTemplate, 'error');
            }
        });
    }

    function clearTemplate() {
        pushHistory();
        state.templateId = null;
        state.customInstructions = '';
        updateCustomInstructionsUI();
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

    /**
     * Update the custom instructions UI elements:
     * - Sidebar textarea (always visible when AI enabled)
     * - Badge indicator on AI section
     * - Modal textarea (when saving template)
     */
    function updateCustomInstructionsUI() {
        // Update sidebar textarea
        var sidebarTA = document.getElementById('eo-custom-instructions');
        if (sidebarTA) {
            sidebarTA.value = state.customInstructions || '';
        }
        // Update badge/indicator
        var badge = document.getElementById('eo-ci-badge');
        if (badge) {
            badge.style.display = state.customInstructions ? '' : 'none';
        }
        // Update modal textarea
        var modalTA = document.getElementById('eo-template-instructions');
        if (modalTA) {
            modalTA.value = state.customInstructions || '';
        }
    }

    function showSaveTemplate() {
        document.getElementById('eo-template-name').value = '';
        const currentSupplier = $('#eo-supplier').val();
        $('#eo-template-supplier').val(currentSupplier).trigger('change');
        // Sync custom instructions from sidebar to modal
        var sidebarTA = document.getElementById('eo-custom-instructions');
        var modalTA = document.getElementById('eo-template-instructions');
        if (modalTA) {
            modalTA.value = sidebarTA ? sidebarTA.value : (state.customInstructions || '');
        }
        showModal('eo-modal-template');
    }

    function hideSaveTemplate() {
        hideModal('eo-modal-template');
    }

    function saveTemplate() {
        const name = document.getElementById('eo-template-name').value.trim();
        if (!name) {
            toast(L.enterTemplateName, 'error');
            return;
        }

        const supplier = $('#eo-template-supplier').val();
        const customInstr = document.getElementById('eo-template-instructions') ? document.getElementById('eo-template-instructions').value.trim() : '';
        showLoader();
        const details = getAllSelections();

        // Update state
        state.customInstructions = customInstr;
        updateCustomInstructionsUI();

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: {
                action: "addTemplate",
                name: name,
                fk_soc: supplier,
                scale: state.scale,
                custom_instructions: customInstr,
                selections: JSON.stringify(details)
            },
            success: function (data) {
                hideLoader();
                if (data.status === 'ok') {
                    hideSaveTemplate();
                    loadInitialData();
                    toast(L.templateSavedOk);
                }
            },
            error: function () {
                hideLoader();
                toast(L.errorSavingTemplate, 'error');
            }
        });
    }

    function editTemplate() {
        if (!state.templateId) return;

        showLoader();
        const details = getAllSelections();
        const supplier = $('#eo-supplier').val();
        // Sync custom instructions from sidebar textarea
        var instrEl = document.getElementById('eo-custom-instructions');
        if (instrEl) {
            state.customInstructions = instrEl.value.trim();
        }

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: {
                action: "editTemplate",
                template_id: state.templateId,
                fk_soc: supplier,
                scale: state.scale,
                custom_instructions: state.customInstructions,
                selections: JSON.stringify(details)
            },
            success: function (data) {
                hideLoader();
                if (data.status === 'ok') {
                    toast(L.templateEditedOk);
                }
            },
            error: function () {
                hideLoader();
                toast(L.errorEditingTemplate, 'error');
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
        const factura = getSelectionValue(L.labelInvoice);
        const fecha = getSelectionValue(L.labelDate);
        const totalTtc = getSelectionValue(L.labelTTC);
        const totalHt = getSelectionValue(L.labelHT);
        const iva = getSelectionValue(L.labelIVA);
        const desc = getSelectionValue(L.labelDesc);
        const cif = getSelectionValue(L.labelCIF);
        const dueDate = getSelectionValue(L.labelDueDate);

        if (!supplier || !factura || !fecha || !totalTtc || !totalHt) {
            toast(L.completeAllFields, 'error');
            return;
        }

        // Modal de confirmación
        let confirmHtml = `
            <div class="eo-confirm-grid">
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.supplierLabel || 'Supplier') + ':'}</span><span class="eo-confirm-value">${supplierName}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.invoiceNumber || 'Invoice No.') + ':'}</span><span class="eo-confirm-value">${factura}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.dateLabel || 'Date') + ':'}</span><span class="eo-confirm-value">${fecha}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.taxableBase || 'Tax base') + ':'}</span><span class="eo-confirm-value">${totalHt}</span></div>
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.totalTTC || 'Total') + ':'}</span><span class="eo-confirm-value">${totalTtc}</span></div>`;

        if (iva) confirmHtml += `
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.labelIVA || 'Tax amount') + ':'}</span><span class="eo-confirm-value">${iva}</span></div>`;
        if (desc) confirmHtml += `
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.labelDesc || 'Description') + ':'}</span><span class="eo-confirm-value eo-confirm-desc">${desc}</span></div>`;
        if (cif) confirmHtml += `
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.labelCIF || 'Tax ID') + ':'}</span><span class="eo-confirm-value">${cif}</span></div>`;
        if (dueDate) confirmHtml += `
                <div class="eo-confirm-row"><span class="eo-confirm-label">${(L.labelDueDate || 'Due date') + ':'}</span><span class="eo-confirm-value">${dueDate}</span></div>`;

        confirmHtml += `
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
                toast(L.selectBankForPayment, 'error');
                return;
            }
            if (!paymentTypeId) {
                toast(L.selectPaymentType, 'error');
                return;
            }
        }

        hideModal('eo-modal-confirm');
        showLoader();

        const formData = new FormData();
        formData.append('action', 'newInvoice');
        formData.append('file', state.file);
        formData.append('fk_soc', $('#eo-supplier').val());
        formData.append('ref_supplier', getSelectionValue(L.labelInvoice));
        formData.append('datef', getSelectionValue(L.labelDate));
        formData.append('total_ttc', getSelectionValue(L.labelTTC));
        formData.append('total_ht', getSelectionValue(L.labelHT));

        // New optional fields
        const ivaVal = getSelectionValue(L.labelIVA);
        const descVal = getSelectionValue(L.labelDesc);
        const dueDateVal = getSelectionValue(L.labelDueDate);
        if (ivaVal) formData.append('total_tva', ivaVal);
        if (descVal) formData.append('description', descVal);
        if (dueDateVal) formData.append('date_echeance', dueDateVal);

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
                    toast(L.invoiceCreatedOk, 'success');
                    resetWorkspace();
                } else if (data.status === 'repeat') {
                    toast(L.invoiceAlreadyExists, 'warn');
                }
            },
            error: function () {
                hideLoader();
                toast(L.errorGeneratingInvoice, 'error');
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

    // ---- Auto-detect supplier by CIF/NIF ----
    function autoDetectSupplierByCIF(cif) {
        if (!cif || state._lastCIFSearch === cif) return;
        state._lastCIFSearch = cif;

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: { action: 'findSupplierByCIF', cif: cif.trim() },
            success: function (data) {
                if (data.status === 'ok' && data.fk_soc) {
                    $('#eo-supplier').val(data.fk_soc);
                    toast(L.supplierAutoDetected || 'Supplier auto-detected by Tax ID', 'success');
                    updateReadiness();
                }
            }
        });
    }

    function getSelectionValue(label) {
        for (const page of state.pages) {
            for (const sel of page.selections) {
                if (sel.label === label) return sel.text;
            }
        }
        return '';
    }

    // ---- Check batch invoice data from localStorage ----
    function checkBatchInvoiceData() {
        // Check if we have fromBatch URL parameter
        var urlParams = new URLSearchParams(window.location.search);
        if (!urlParams.has('fromBatch')) return;

        // Try to read data from localStorage
        try {
            var dataStr = localStorage.getItem('eoBatchInvoiceData');
            var timestamp = localStorage.getItem('eoBatchInvoiceTimestamp');
            
            if (!dataStr) return;
            
            // Check if data is not too old (max 5 minutes)
            if (timestamp) {
                var age = Date.now() - parseInt(timestamp);
                if (age > 300000) { // 5 minutes
                    localStorage.removeItem('eoBatchInvoiceData');
                    localStorage.removeItem('eoBatchInvoiceTimestamp');
                    return;
                }
            }
            
            var data = JSON.parse(dataStr);
            
            // Clean up localStorage
            localStorage.removeItem('eoBatchInvoiceData');
            localStorage.removeItem('eoBatchInvoiceTimestamp');
            
            // Open AI modal with this data
            setTimeout(function() {
                openAIModal(data);
            }, 500); // Wait for DOM to be fully ready
            
        } catch (e) {
            console.error('Error reading batch invoice data:', e);
            localStorage.removeItem('eoBatchInvoiceData');
            localStorage.removeItem('eoBatchInvoiceTimestamp');
        }
    }

    // ---- Open AI modal with provided data (used for batch invoice creation) ----
    function openAIModal(data) {
        if (!data) return;
        
        // Store in state
        state.aiResult = data;
        
        // Display in modal
        displayAIResult(data);
    }

    // ---- Inicialización ----
    function init() {
        // Upload
        document.getElementById('pdfInput').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.type !== 'application/pdf') {
                toast(L.selectPdfFile || 'Select a PDF file', 'error');
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
                toast(L.onlyPdfAccepted, 'error');
            }
        });

        // Atajos de teclado
        document.addEventListener('keydown', function (e) {
            // Ignorar si estamos en un input/textarea/select
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            // 1-8: Seleccionar etiqueta
            if (e.key >= '1' && e.key <= '8') {
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

        // AI enabled state from PHP data attribute
        var aiSection = document.getElementById('eo-ai-section');
        state.aiEnabled = aiSection && aiSection.getAttribute('data-ai-enabled') === '1';

        // Check if redirected from batch with invoice data
        checkBatchInvoiceData();
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
            container.innerHTML = '<span class="eo-meta-empty">' + (L.noMetadata) + '</span>';
            return;
        }

        const info = meta.info;
        const knownFields = [
            { key: 'Title', label: L.metaTitle || 'Título' },
            { key: 'Author', label: L.metaAuthor || 'Autor' },
            { key: 'Subject', label: L.metaSubject || 'Asunto' },
            { key: 'Creator', label: L.metaCreator || 'Creador' },
            { key: 'Producer', label: L.metaProducer || 'Productor' },
            { key: 'CreationDate', label: L.metaCreationDate || 'Fecha creación' },
            { key: 'ModDate', label: L.metaModDate || 'Fecha modificación' },
            { key: 'Keywords', label: L.metaKeywords || 'Palabras clave' },
            { key: 'Trapped', label: L.metaTrapped || 'Trapped' }
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

        html += `<div class="eo-meta-row"><span class="eo-meta-label">${L.pdfVersion || 'Versión PDF'}:</span><span class="eo-meta-value">${info.PDFFormatVersion || '—'}</span></div>`;

        if (meta.metadata) {
            const xmpFields = [
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'creator', label: L.xmpAuthor || 'XMP Autor' },
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'description', label: L.xmpDescription || 'XMP Descripción' },
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'title', label: L.xmpTitle || 'XMP Título' },
                { ns: 'http://purl.org/dc/elements/1.1/', key: 'subject', label: L.xmpSubject || 'XMP Asunto' }
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

        container.innerHTML = hasData ? html : '<span class="eo-meta-empty">' + (L.noRelevantMetadata) + '</span>';
    }

    // ---- Preview de factura creada en iframe ----
    function showInvoicePreview(facId, ref) {
        const url = '../../fourn/facture/card.php?mainmenu=billing&facid=' + facId;
        document.getElementById('eo-invoice-iframe').src = url;
        document.getElementById('eo-invoice-link').href = url;
        document.getElementById('eo-invoice-title').textContent = ref ? (L.invoiceCreatedWithRef || 'Factura %s creada').replace('%s', ref) : (L.invoiceCreatedOk || 'Factura creada');
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
        state.aiResult = null;
        state.activeTag = null;
        state.isDrawing = false;
        state.pages = [];
        history.length = 0;
        Object.keys(textCache).forEach(k => delete textCache[k]);

        // Limpiar UI
        document.getElementById('canvas-container').innerHTML = '';
        document.getElementById('eo-empty-state').style.display = '';
        document.getElementById('eo-filename').textContent = L.noFileSelected || 'Ningún archivo seleccionado';
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

    // ---- AI OCR ----
    function runAIOcr() {
        if (!state.pdfArrayBuffer) {
            toast(L.importPdfFirst || 'Import a PDF first', 'warn');
            return;
        }

        // If we already have AI results, just re-show the modal
        if (state.aiResult) {
            document.getElementById('eo-modal-ai').style.display = 'flex';
            return;
        }

        // Sync custom instructions from sidebar textarea before sending
        var instrEl = document.getElementById('eo-custom-instructions');
        if (instrEl) {
            state.customInstructions = instrEl.value.trim();
        }

        // Convert ArrayBuffer to base64
        var bytes = new Uint8Array(state.pdfArrayBuffer);
        var binary = '';
        var chunkSize = 8192;
        for (var i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
        }
        var base64 = btoa(binary);

        // Try SSE stream via PHP proxy, fallback to classic AJAX
        if (state.aiEnabled && window.fetch && window.ReadableStream) {
            runAIOcrSSE(base64);
        } else {
            runAIOcrClassic(base64);
        }
    }

    /* ---------- SSE via PHP proxy (same origin, no CORS) ---------- */
    function runAIOcrSSE(base64) {
        var btn = document.getElementById('eo-btn-ai-ocr');
        var progressEl = document.getElementById('eo-ai-progress');
        var fillEl = document.getElementById('eo-ai-progress-fill');
        var textEl = document.getElementById('eo-ai-progress-text');

        // Disable button and show progress bar
        if (btn) {
            btn.disabled = true;
            btn.dataset.origText = btn.innerHTML;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eo-spin"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> ' + (L.aiProcessing || 'Procesando...');
        }
        if (progressEl) progressEl.style.display = 'block';
        if (fillEl) fillEl.style.width = '0%';
        if (textEl) textEl.textContent = L.aiStarting || 'Iniciando...';

        // Start simulated progress immediately as fallback
        // (will be replaced by real SSE events if streaming works)
        startSimulatedProgress(fillEl, textEl);

        // POST to PHP SSE proxy — same origin, no CORS issues
        var formData = new FormData();
        formData.append('action', 'aiOcrStream');
        formData.append('base64_data', base64);
        formData.append('filename', state.file ? state.file.name : 'document.pdf');
        if (state.customInstructions) {
            formData.append('custom_instructions', state.customInstructions);
        }

        var gotRealEvent = false;

        fetch('ajax/ajax_easyocr.php', {
            method: 'POST',
            body: formData
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return readSSEStream(response, fillEl, textEl, function() {
                // Called on first real SSE event — stop simulated progress
                if (!gotRealEvent) {
                    gotRealEvent = true;
                    stopSimulatedProgress();
                }
            });
        }).then(function (resultData) {
            stopSimulatedProgress();
            resetAIProgress();
            if (resultData) {
                state.aiResult = resultData;
                displayAIResult(resultData);
                toast(L.aiOcrSuccess || 'AI extraction complete', 'success');
            } else {
                toast(L.aiNoData || 'No data extracted', 'warn');
            }
        }).catch(function (err) {
            console.warn('SSE stream error, falling back to classic:', err.message);
            stopSimulatedProgress();
            resetAIProgress();
            runAIOcrClassic(base64);
        });
    }

    /* ---------- SSE parser — handles both "event: x" and "event:x" ---------- */
    function readSSEStream(response, fillEl, textEl, onFirstEvent) {
        return new Promise(function (resolve, reject) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            var result = null;

            function pump() {
                reader.read().then(function (ref) {
                    var done = ref.done;
                    var value = ref.value;
                    if (done) { resolve(result); return; }

                    buffer += decoder.decode(value, { stream: true });
                    // Split on double newline (SSE event separator)
                    var events = buffer.split('\n\n');
                    buffer = events.pop(); // keep incomplete tail

                    for (var i = 0; i < events.length; i++) {
                        var eventStr = events[i].trim();
                        if (!eventStr) continue;

                        var lines = eventStr.split('\n');
                        var eventType = '', dataLines = [];
                        for (var j = 0; j < lines.length; j++) {
                            var line = lines[j];
                            // Skip SSE comments (lines starting with ':')
                            if (line.indexOf(':') === 0 && line.indexOf('data:') !== 0) continue;
                            // Handle "event: x" or "event:x"
                            if (line.indexOf('event:') === 0) {
                                eventType = line.substring(6).trim();
                            } else if (line.indexOf('data:') === 0) {
                                dataLines.push(line.substring(5).trim());
                            }
                        }
                        var eventData = dataLines.join('\n');
                        if (!eventType || !eventData) continue;

                        try { var data = JSON.parse(eventData); }
                        catch (e) { continue; }

                        // Notify caller on first real event (stops simulated progress)
                        if (onFirstEvent) { onFirstEvent(); onFirstEvent = null; }

                        if (eventType === 'progress') {
                            if (fillEl) fillEl.style.width = (data.percent || 0) + '%';
                            if (textEl) textEl.textContent = data.message || data.step || '';
                        } else if (eventType === 'result') {
                            result = data;
                            if (fillEl) fillEl.style.width = '100%';
                            if (textEl) textEl.textContent = L.aiOcrSuccess || 'Completado';
                        } else if (eventType === 'error') {
                            reject(new Error(data.message || 'SSE error'));
                            return;
                        }
                    }
                    pump();
                }).catch(reject);
            }
            pump();
        });
    }

    /* ---------- Classic AJAX fallback with simulated progress ---------- */
    function runAIOcrClassic(base64) {
        var progressEl = document.getElementById('eo-ai-progress');
        var fillEl = document.getElementById('eo-ai-progress-fill');
        var textEl = document.getElementById('eo-ai-progress-text');
        var btn = document.getElementById('eo-btn-ai-ocr');

        // Show progress bar with simulated stages
        if (btn && !btn.disabled) {
            btn.disabled = true;
            btn.dataset.origText = btn.innerHTML;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="eo-spin"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> ' + (L.aiProcessing || 'Procesando...');
        }
        if (progressEl) progressEl.style.display = 'block';
        if (fillEl) fillEl.style.width = '0%';
        startSimulatedProgress(fillEl, textEl);

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: { action: "aiOcr", base64_data: base64, custom_instructions: state.customInstructions || '' },
            success: function (response) {
                stopSimulatedProgress();
                if (fillEl) fillEl.style.width = '100%';
                if (textEl) textEl.textContent = '';
                resetAIProgress();
                if (response.status === 'ok' && response.data) {
                    state.aiResult = response.data;
                    displayAIResult(response.data);
                    toast(L.aiOcrSuccess || 'AI extraction complete', 'success');
                } else {
                    toast(response.message || (L.aiOcrError || 'AI OCR error'), 'error');
                }
            },
            error: function (xhr) {
                stopSimulatedProgress();
                resetAIProgress();
                toast(L.aiOcrError || 'AI OCR service error', 'error');
            }
        });
    }

    /* ---------- Simulated progress for classic AJAX ---------- */
    function startSimulatedProgress(fillEl, textEl) {
        // Simulated steps aligned with typical OCR processing times (~12-15s)
        var steps = [
            { pct: 5,  msg: L.aiStarting || 'Enviando archivo...',      delay: 300   },
            { pct: 10, msg: 'Validando documento...',                    delay: 1500  },
            { pct: 20, msg: 'Extrayendo texto (OCR)...',                 delay: 2500  },
            { pct: 35, msg: 'Procesando páginas...',                     delay: 4000  },
            { pct: 50, msg: 'OCR completado...',                         delay: 6000  },
            { pct: 65, msg: 'Estructurando datos con IA...',             delay: 7500  },
            { pct: 75, msg: 'Analizando campos...',                      delay: 10000 },
            { pct: 85, msg: 'Finalizando análisis...',                   delay: 13000 },
            { pct: 90, msg: 'Casi listo...',                             delay: 17000 },
            { pct: 93, msg: 'Verificando resultados...',                 delay: 22000 },
            { pct: 95, msg: 'Un momento más...',                         delay: 30000 }
        ];
        state._simTimers = [];
        for (var i = 0; i < steps.length; i++) {
            (function (s) {
                var t = setTimeout(function () {
                    if (fillEl) fillEl.style.width = s.pct + '%';
                    if (textEl) textEl.textContent = s.msg;
                }, s.delay);
                state._simTimers.push(t);
            })(steps[i]);
        }
    }

    function stopSimulatedProgress() {
        if (state._simTimers) {
            for (var i = 0; i < state._simTimers.length; i++) {
                clearTimeout(state._simTimers[i]);
            }
            state._simTimers = [];
        }
    }

    function resetAIProgress() {
        var btn = document.getElementById('eo-btn-ai-ocr');
        var progressEl = document.getElementById('eo-ai-progress');
        if (btn && btn.dataset.origText) {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.origText;
        }
        if (progressEl) {
            setTimeout(function () { progressEl.style.display = 'none'; }, 1200);
        }
    }

    // ========== AI MODAL PREMIUM ==========

    function displayAIResult(data) {
        var sd = data.structured_data || data;

        // --- Meta pills ---
        setMetaPill('eo-ai-meta-confidence', data.confidence != null, (data.confidence != null ? Math.round(data.confidence * 100) + '%' : ''));
        setMetaPill('eo-ai-meta-time', data.processing_time_ms > 0, (data.processing_time_ms > 0 ? (data.processing_time_ms / 1000).toFixed(1) + 's' : ''));
        setMetaPill('eo-ai-meta-tokens', data.tokens && data.tokens.total, (data.tokens ? data.tokens.total + ' tok' : ''));
        var pageCount = sd.metadata && sd.metadata.page_count ? sd.metadata.page_count : null;
        setMetaPill('eo-ai-meta-pages', pageCount, (pageCount ? pageCount + 'p' : ''));

        // --- Document fields ---
        var docFields = [
            { key: 'document_type', label: L.aiDocType || 'Type', half: true },
            { key: 'document_number', label: L.invoiceNumber || 'Invoice No.', half: true },
            { key: 'issue_date', label: L.dateLabel || 'Date', half: true },
            { key: 'due_date', label: L.dueDateLabel || 'Due date', half: true },
            { key: 'currency', label: L.currency || 'Currency', half: true }
        ];
        renderFieldGroup('eo-ai-doc-fields', docFields, sd);

        // --- Supplier fields ---
        var sup = sd.supplier || {};
        var supplierFields = [
            { key: 'name', label: L.aiName || 'Name', half: false },
            { key: 'tax_id', label: L.labelCIF || 'Tax ID', half: true },
            { key: 'address', label: L.aiAddress || 'Address', half: true },
            { key: 'city', label: L.aiCity || 'City', half: true },
            { key: 'postal_code', label: L.aiPostalCode || 'Postal code', half: true },
            { key: 'country', label: L.aiCountry || 'Country', half: true },
            { key: 'phone', label: L.aiPhone || 'Phone', half: true },
            { key: 'email', label: L.aiEmail || 'Email', half: true }
        ];
        var hasSup = renderFieldGroup('eo-ai-supplier-fields', supplierFields, sup);
        toggleCard('eo-ai-card-supplier', hasSup);

        // --- Customer fields ---
        var cust = sd.customer || {};
        var customerFields = [
            { key: 'name', label: L.aiName || 'Name', half: false },
            { key: 'tax_id', label: L.labelCIF || 'Tax ID', half: true },
            { key: 'address', label: L.aiAddress || 'Address', half: true },
            { key: 'city', label: L.aiCity || 'City', half: true },
            { key: 'postal_code', label: L.aiPostalCode || 'Postal code', half: true },
            { key: 'country', label: L.aiCountry || 'Country', half: true }
        ];
        var hasCust = renderFieldGroup('eo-ai-customer-fields', customerFields, cust);
        toggleCard('eo-ai-card-customer', hasCust);

        // --- Line items ---
        var items = sd.items || [];
        var tbody = document.getElementById('eo-ai-lines-tbody');
        var countEl = document.getElementById('eo-ai-lines-count');
        if (tbody) {
            tbody.innerHTML = '';
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="eo-ai-empty-lines">' + (L.aiNoLines || 'No line items') + '</td></tr>';
            } else {
                items.forEach(function (item, idx) {
                    tbody.appendChild(createLineRow(item, idx));
                });
            }
        }
        if (countEl) countEl.textContent = items.length;

        // --- Totals — parse new format with surcharges & withholdings ---
        var totals = sd.totals || {};

        // Extract default tax rate from totals.taxes (use first IVA/TVA/VAT rate found)
        state.defaultTaxRate = 0;
        if (totals.taxes && Array.isArray(totals.taxes)) {
            for (var ti = 0; ti < totals.taxes.length; ti++) {
                var docTax = totals.taxes[ti];
                var docTaxType = String(docTax.tax_type || '').toLowerCase();
                if (docTaxType === 'tva' || docTaxType === 'iva' || docTaxType === 'vat') {
                    state.defaultTaxRate = parseFloat(docTax.tax_rate) || 0;
                    break;
                }
            }
        }

        var totalsMap = {
            subtotal: totals.net_subtotal || totals.subtotal || null,
            tax: totals.tax_total || totals.tax || null,
            discount: totals.discount_total || totals.discount || null,
            surcharge: totals.surcharge_total || null,
            withholding: totals.withholding_total || null,
            total: totals.total || null,
            total_payable: totals.total_payable || null
        };
        var totalsFields = [
            { key: 'subtotal', label: L.taxableBase || 'Subtotal', half: true, money: true },
            { key: 'tax', label: L.aiTaxes || 'Tax', half: true, money: true },
            { key: 'discount', label: L.aiDiscount || 'Discount', half: true, money: true },
            { key: 'surcharge', label: 'RE / Recargo', half: true, money: true },
            { key: 'withholding', label: 'IRPF / Retención', half: true, money: true },
            { key: 'total', label: L.aiTotal || 'Total', half: true, money: true }
        ];
        renderFieldGroup('eo-ai-totals-fields', totalsFields, totalsMap);

        // --- Payment ---
        var pay = sd.payment || {};
        var payFields = [
            { key: 'method', label: L.aiPayMethod || 'Method', half: true },
            { key: 'status', label: L.aiPayStatus || 'Status', half: true },
            { key: 'bank_account', label: L.aiPayBank || 'Bank account', half: false },
            { key: 'reference', label: L.aiPayRef || 'Reference', half: true }
        ];
        var hasPay = renderFieldGroup('eo-ai-payment-fields', payFields, pay);
        toggleCard('eo-ai-card-payment', hasPay);

        // --- Notes ---
        var notesCard = document.getElementById('eo-ai-notes-card');
        var notesContainer = document.getElementById('eo-ai-notes-fields');
        if (notesCard && notesContainer) {
            if (sd.notes) {
                notesCard.style.display = '';
                notesContainer.innerHTML = '';
                var fg = document.createElement('div');
                fg.className = 'eo-ai-field-group full-width';
                var ta = document.createElement('textarea');
                ta.className = 'eo-ai-field-input';
                ta.setAttribute('data-ai-section', 'notes');
                ta.setAttribute('data-ai-key', 'notes');
                ta.value = sd.notes;
                ta.rows = 3;
                fg.appendChild(ta);
                notesContainer.appendChild(fg);
            } else {
                notesCard.style.display = 'none';
            }
        }

        // Show modal
        document.getElementById('eo-modal-ai').style.display = 'flex';
    }

    function toggleCard(cardId, hasData) {
        var card = document.getElementById(cardId);
        if (!card) return;
        if (hasData) {
            card.style.display = '';
            card.classList.remove('collapsed');
        } else {
            card.style.display = 'none';
        }
    }

    function setMetaPill(id, condition, text) {
        var el = document.getElementById(id);
        if (!el) return;
        if (condition) {
            el.textContent = text;
            el.classList.add('visible');
        } else {
            el.classList.remove('visible');
        }
    }

    function renderFieldGroup(containerId, fields, dataObj) {
        var container = document.getElementById(containerId);
        if (!container) return false;
        container.innerHTML = '';
        var hasAnyValue = false;

        fields.forEach(function (f) {
            var val = dataObj[f.key];
            if (val === undefined || val === null) val = '';
            if (String(val).trim() !== '') hasAnyValue = true;

            var fg = document.createElement('div');
            fg.className = 'eo-ai-field-group' + (f.half ? '' : ' full-width');

            var lbl = document.createElement('label');
            lbl.className = 'eo-ai-field-lbl';
            lbl.textContent = f.label;

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'eo-ai-field-input' + (f.money ? ' eo-ai-input-money' : '');
            input.value = String(val);
            input.setAttribute('data-ai-section', containerId);
            input.setAttribute('data-ai-key', f.key);

            fg.appendChild(lbl);

            // Special handling for supplier tax_id field
            if (containerId === 'eo-ai-supplier-fields' && f.key === 'tax_id') {
                var wrapper = document.createElement('div');
                wrapper.style.position = 'relative';
                wrapper.style.display = 'flex';
                wrapper.style.alignItems = 'center';
                wrapper.style.gap = '6px';

                // Status indicator
                var indicator = document.createElement('span');
                indicator.id = 'eo-supplier-status-indicator';
                indicator.className = 'eo-supplier-indicator';
                indicator.style.display = 'none';
                indicator.style.fontSize = '16px';

                wrapper.appendChild(input);
                wrapper.appendChild(indicator);
                fg.appendChild(wrapper);

                // Selector container for multiple suppliers
                var selectorDiv = document.createElement('div');
                selectorDiv.id = 'eo-supplier-selector-container';
                selectorDiv.style.display = 'none';
                selectorDiv.style.marginTop = '6px';
                fg.appendChild(selectorDiv);

                // Auto-search on blur
                input.addEventListener('blur', function() {
                    var cif = this.value.trim();
                    if (cif) checkSupplierByCIF(cif);
                });

                // Initial check if value exists
                if (val) {
                    setTimeout(function() { checkSupplierByCIF(val); }, 300);
                }
            } else {
                fg.appendChild(input);
            }

            container.appendChild(fg);
        });

        return hasAnyValue;
    }

    function createLineRow(item, idx) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-ai-line-idx', idx);

        // Extract tax info — robust multi-source extraction
        var tvaRate = '', tvaAmt = '', reRate = '', irpfRate = '';

        // Source 1: taxes array (handle both parsed array and JSON string)
        var taxesArr = item.taxes;
        if (typeof taxesArr === 'string') {
            try { taxesArr = JSON.parse(taxesArr); } catch (e) { taxesArr = null; }
        }
        if (taxesArr && Array.isArray(taxesArr)) {
            for (var t = 0; t < taxesArr.length; t++) {
                var tax = taxesArr[t];
                if (!tax || typeof tax !== 'object') continue;
                var tt = String(tax.tax_type || '').toLowerCase().trim();
                var rate = parseFloat(tax.tax_rate);
                if (isNaN(rate)) rate = 0;
                var amt = parseFloat(tax.tax_amount);
                if (isNaN(amt)) amt = 0;
                if (tt === 'tva' || tt === 'iva' || tt === 'vat') {
                    if (rate) tvaRate = rate;
                    if (amt) tvaAmt = amt;
                } else if (tt === 're') {
                    if (rate) reRate = rate;
                } else if (tt === 'irpf') {
                    if (rate) irpfRate = rate;
                }
            }
        }

        // Source 2: flat fields as fallback
        if (!tvaRate && item.tax_rate) tvaRate = parseFloat(item.tax_rate) || '';
        if (!tvaAmt && item.tax_amount) tvaAmt = parseFloat(item.tax_amount) || '';
        if (!reRate && item.re_rate) reRate = parseFloat(item.re_rate) || '';
        if (!irpfRate && item.irpf_rate) irpfRate = parseFloat(item.irpf_rate) || '';

        // Source 3: compute IVA from net_amount and total if still missing
        if (!tvaRate && item.net_amount && item.total) {
            var netAmt = parseFloat(item.net_amount);
            var totAmt = parseFloat(item.total);
            if (netAmt !== 0 && totAmt !== 0 && Math.abs(totAmt) > Math.abs(netAmt)) {
                var computedRate = Math.round((totAmt / netAmt - 1) * 100);
                if (computedRate > 0 && computedRate <= 100) tvaRate = computedRate;
            }
        }

        // Source 4: use document's default tax rate from totals.taxes if still missing
        if (!tvaRate && state.defaultTaxRate > 0) {
            tvaRate = state.defaultTaxRate;
        }

        // Normalize: 0 → empty for display
        if (tvaRate === 0) tvaRate = '';
        if (reRate === 0) reRate = '';
        if (irpfRate === 0) irpfRate = '';

        var fields = [
            { key: 'code', cls: '', val: item.code || '' },
            { key: 'description', cls: '', val: item.description || item.label || item.name || '' },
            { key: 'item_type', cls: '', val: item.item_type || 'product' },
            { key: 'quantity', cls: 'eo-ai-td-input-num', val: item.quantity || item.qty || '1' },
            { key: 'unit_price', cls: 'eo-ai-td-input-num', val: item.unit_price || item.price || '' },
            { key: 'discount_percent', cls: 'eo-ai-td-input-num', val: item.discount_percent || '' },
            { key: 'tax_rate', cls: 'eo-ai-td-input-num', val: tvaRate },
            { key: 're_rate', cls: 'eo-ai-td-input-num', val: reRate },
            { key: 'irpf_rate', cls: 'eo-ai-td-input-num', val: irpfRate },
            { key: 'total', cls: 'eo-ai-td-input-num', val: item.net_amount || item.total || item.amount || item.line_total || '' }
        ];

        fields.forEach(function (f) {
            var td = document.createElement('td');
            if (f.key === 'item_type') {
                var sel = document.createElement('select');
                sel.className = 'eo-ai-td-input eo-ai-td-select';
                sel.setAttribute('data-ai-line-key', f.key);
                var types = [
                    { val: 'product', lbl: L.typeProduct || 'Producto' },
                    { val: 'service', lbl: L.typeService || 'Servicio' },
                    { val: 'shipping', lbl: L.typeShipping || 'Envío/Portes' },
                    { val: 'surcharge', lbl: L.typeSurcharge || 'Recargo' },
                    { val: 'fee', lbl: L.typeFee || 'Tasa' },
                    { val: 'discount', lbl: L.typeDiscount || 'Descuento' },
                    { val: 'other', lbl: L.typeOther || 'Otro' }
                ];
                types.forEach(function(t) {
                    var opt = document.createElement('option');
                    opt.value = t.val;
                    opt.textContent = t.lbl;
                    if (t.val === f.val) opt.selected = true;
                    sel.appendChild(opt);
                });
                td.appendChild(sel);
            } else {
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'eo-ai-td-input ' + f.cls;
                input.value = String(f.val);
                input.setAttribute('data-ai-line-key', f.key);
                td.appendChild(input);
            }
            tr.appendChild(td);
        });

        // Delete button
        var tdDel = document.createElement('td');
        var btnDel = document.createElement('button');
        btnDel.className = 'eo-ai-row-delete';
        btnDel.innerHTML = '✕';
        btnDel.title = L.deleteSelection || 'Delete';
        btnDel.onclick = function () {
            tr.style.transition = 'opacity 0.2s, transform 0.2s';
            tr.style.opacity = '0';
            tr.style.transform = 'translateX(20px)';
            setTimeout(function () {
                tr.remove();
                updateLineCount();
            }, 200);
        };
        tdDel.appendChild(btnDel);
        tr.appendChild(tdDel);

        return tr;
    }

    function aiAddLine() {
        var tbody = document.getElementById('eo-ai-lines-tbody');
        if (!tbody) return;

        // Remove "no lines" placeholder if present
        var emptyRow = tbody.querySelector('.eo-ai-empty-lines');
        if (emptyRow) emptyRow.closest('tr').remove();

        var idx = tbody.querySelectorAll('tr').length;
        var newRow = createLineRow({}, idx);
        newRow.style.animation = 'eo-ai-in 0.25s ease-out';
        tbody.appendChild(newRow);
        updateLineCount();

        // Focus on description
        var firstInput = newRow.querySelector('input');
        if (firstInput) firstInput.focus();
    }

    function updateLineCount() {
        var tbody = document.getElementById('eo-ai-lines-tbody');
        var countEl = document.getElementById('eo-ai-lines-count');
        if (!tbody || !countEl) return;
        var rows = tbody.querySelectorAll('tr[data-ai-line-idx]');
        countEl.textContent = rows.length;
    }

    function closeAIModal() {
        var modal = document.getElementById('eo-modal-ai');
        if (modal) modal.style.display = 'none';
        // Close payload panel if open
        var panel = document.getElementById('eo-ai-payload-panel');
        var btn = document.getElementById('eo-btn-show-payload');
        if (panel) panel.style.display = 'none';
        if (btn) btn.classList.remove('active');
    }

    function collectAIModalData() {
        var result = { document: {}, supplier: {}, customer: {}, items: [], totals: {}, payment: {}, notes: '' };

        // Collect simple field groups
        var sections = {
            'eo-ai-doc-fields': 'document',
            'eo-ai-supplier-fields': 'supplier',
            'eo-ai-customer-fields': 'customer',
            'eo-ai-totals-fields': 'totals',
            'eo-ai-payment-fields': 'payment'
        };

        Object.keys(sections).forEach(function (containerId) {
            var sectionKey = sections[containerId];
            var container = document.getElementById(containerId);
            if (!container) return;
            var inputs = container.querySelectorAll('.eo-ai-field-input');
            inputs.forEach(function (input) {
                var key = input.getAttribute('data-ai-key');
                if (key) result[sectionKey][key] = input.value.trim();
            });
        });

        // Collect notes
        var notesInput = document.querySelector('[data-ai-section="notes"]');
        if (notesInput) result.notes = notesInput.value.trim();

        // Collect line items with taxes array reconstruction
        var rows = document.querySelectorAll('#eo-ai-lines-tbody tr[data-ai-line-idx]');
        rows.forEach(function (row) {
            var line = {};
            var inputs = row.querySelectorAll('.eo-ai-td-input');
            inputs.forEach(function (input) {
                var key = input.getAttribute('data-ai-line-key');
                if (key) {
                    // Handle both input and select elements
                    line[key] = (input.tagName === 'SELECT') ? input.value : input.value.trim();
                }
            });
            if (line.description || line.quantity || line.unit_price || line.total) {
                // Reconstruct taxes array from flat columns
                line.taxes = [];
                if (line.tax_rate && parseFloat(line.tax_rate) !== 0) {
                    line.taxes.push({ tax_type: 'iva', tax_rate: parseFloat(line.tax_rate) || 0, tax_amount: 0 });
                }
                if (line.re_rate && parseFloat(line.re_rate) !== 0) {
                    line.taxes.push({ tax_type: 're', tax_rate: parseFloat(line.re_rate) || 0, tax_amount: 0 });
                }
                if (line.irpf_rate && parseFloat(line.irpf_rate) !== 0) {
                    line.taxes.push({ tax_type: 'irpf', tax_rate: parseFloat(line.irpf_rate) || 0, tax_amount: 0 });
                }
                result.items.push(line);
            }
        });

        // Collect journal and invoice status
        var journalSel = document.getElementById('eo-ai-journal');
        if (journalSel) result.journal_code = journalSel.value || '';

        var statusRadio = document.querySelector('input[name="eo-ai-invoice-status"]:checked');
        result.invoice_status = statusRadio ? statusRadio.value : 'validated';

        return result;
    }

    function applyAIResult() {
        // Legacy — now createAIInvoice handles everything
        createAIInvoice();
    }

    function createAIInvoice() {
        var editedData = collectAIModalData();

        // Validate minimum required fields
        if (!editedData.document.document_number) {
            toast(L.aiMissingInvoiceNum || 'Invoice number is required', 'error');
            return;
        }
        if (!editedData.document.issue_date) {
            toast(L.aiMissingDate || 'Invoice date is required', 'error');
            return;
        }

        // Calculate totals
        var subtotal = parseFloat(editedData.totals.subtotal) || 0;
        var totalTax = parseFloat(editedData.totals.tax) || 0;
        var totalFinal = parseFloat(editedData.totals.total) || 0;

        if (editedData.items.length > 0 && subtotal === 0) {
            var computedSubtotal = 0;
            var computedTax = 0;
            editedData.items.forEach(function (item) {
                var qty = parseFloat(item.quantity) || 1;
                var price = parseFloat(item.unit_price) || 0;
                var lineTotal = parseFloat(item.total) || (qty * price);
                var lineTax = parseFloat(item.tax_amount) || 0;
                computedSubtotal += (lineTotal - lineTax) || lineTotal;
                computedTax += lineTax;
            });
            if (computedSubtotal > 0) subtotal = computedSubtotal;
            if (computedTax > 0 && totalTax === 0) totalTax = computedTax;
        }

        if (totalFinal === 0 && subtotal > 0) {
            totalFinal = subtotal + totalTax;
        }
        if (subtotal === 0 && totalFinal > 0) {
            subtotal = totalFinal - totalTax;
        }

        if (subtotal <= 0 && totalFinal <= 0) {
            toast(L.aiMissingTotals || 'Totals are required', 'error');
            return;
        }

        // Check payment options
        var createPayment = document.getElementById('eo-ai-create-payment');
        var doPayment = createPayment && createPayment.checked;
        if (doPayment) {
            var bankId = $('#eo-ai-payment-bank').val();
            var payTypeId = $('#eo-ai-payment-type').val();
            if (!bankId) { toast(L.selectBankForPayment, 'error'); return; }
            if (!payTypeId) { toast(L.selectPaymentType, 'error'); return; }
        }

        // Supplier: use manually selected ID from multi-select, or selector, or let backend resolve by CIF
        var supplierId = state.selectedSupplierID || $('#eo-supplier').val() || '';

        showLoader();
        doCreateAIInvoice(editedData, supplierId, subtotal, totalTax, totalFinal, doPayment);
    }

    function doCreateAIInvoice(editedData, fkSoc, subtotal, totalTax, totalFinal, doPayment) {
        closeAIModal();

        // Parse surcharge (RE) and withholding (IRPF) totals
        var surchargeTotal = parseFloat(editedData.totals.surcharge) || 0;
        var withholdingTotal = parseFloat(editedData.totals.withholding) || 0;

        var postData = {
            action: 'newInvoiceAI',
            fk_soc: fkSoc || '0',
            ref_supplier: editedData.document.document_number,
            datef: editedData.document.issue_date,
            date_echeance: editedData.document.due_date || '',
            total_ht: subtotal.toFixed(2),
            total_ttc: totalFinal.toFixed(2),
            total_tva: totalTax.toFixed(2),
            total_localtax1: surchargeTotal.toFixed(2),   // RE (Recargo Equivalencia)
            total_localtax2: withholdingTotal.toFixed(2), // IRPF (Retención)
            items: JSON.stringify(editedData.items),
            notes: editedData.notes || '',
            // Invoice options
            invoice_status: editedData.invoice_status || 'validated',
            journal_code: editedData.journal_code || '',
            invoice_type: '0', // Standard supplier invoice
            // Default tax rate from document totals (fallback for lines with empty taxes)
            default_tax_rate: state.defaultTaxRate || 0,
            // Supplier data for auto-resolve/create
            supplier_name: editedData.supplier.name || '',
            supplier_tax_id: editedData.supplier.tax_id || '',
            supplier_address: editedData.supplier.address || '',
            supplier_city: editedData.supplier.city || '',
            supplier_zip: editedData.supplier.postal_code || '',
            supplier_country: editedData.supplier.country || '',
            supplier_phone: editedData.supplier.phone || '',
            supplier_email: editedData.supplier.email || ''
        };

        if (doPayment) {
            postData.create_payment = '1';
            postData.payment_bank_id = $('#eo-ai-payment-bank').val();
            postData.payment_type_id = $('#eo-ai-payment-type').val();
        }

        // Attach the PDF file if we have it
        if (state.file) {
            var formData = new FormData();
            formData.append('file', state.file);
            Object.keys(postData).forEach(function (k) { formData.append(k, postData[k]); });

            $.ajax({
                url: "ajax/ajax_easyocr.php",
                type: 'POST',
                dataType: 'json',
                data: formData,
                processData: false,
                contentType: false,
                success: handleAIInvoiceResult,
                error: handleAIInvoiceError
            });
        } else {
            $.ajax({
                url: "ajax/ajax_easyocr.php",
                type: 'POST',
                dataType: 'json',
                data: postData,
                success: handleAIInvoiceResult,
                error: handleAIInvoiceError
            });
        }
    }

    function handleAIInvoiceResult(data) {
        hideLoader();
        if (data.status === 'ok') {
            if (data.supplier_created) {
                toast((L.aiSupplierCreated || 'Proveedor creado: ') + (data.supplier_name || ''), 'success');
            }
            // Show line errors as warnings if any
            if (data.line_errors && data.line_errors.length > 0) {
                toast((L.aiLineErrors || 'Errores en líneas: ') + data.line_errors.join('; '), 'warn');
            }
            if (data.is_draft) {
                toast(L.invoiceDraftOk || 'Factura creada en borrador', 'success');
            } else {
                toast(L.invoiceCreatedOk, 'success');
            }
            showInvoicePreview(data.id, data.ref || '');
            resetWorkspace();
        } else if (data.status === 'repeat') {
            var msg = L.invoiceAlreadyExists || 'La factura ya existe';
            if (data.existing_ref) {
                msg += ': ' + data.existing_ref;
            }
            if (data.existing_ref_supplier) {
                msg += ' (Ref: ' + data.existing_ref_supplier + ')';
            }
            if (data.existing_id) {
                msg += ' <a href="' + DOL_URL_ROOT + '/fourn/facture/card.php?facid=' + data.existing_id + '" target="_blank" style="color:#fff;text-decoration:underline;">Ver factura</a>';
            }
            toast(msg, 'warn');
        } else {
            toast(data.message || L.errorGeneratingInvoice, 'error');
        }
    }

    function handleAIInvoiceError() {
        hideLoader();
        toast(L.errorGeneratingInvoice, 'error');
    }

    function toggleAIPayment() {
        var checked = document.getElementById('eo-ai-create-payment').checked;
        document.getElementById('eo-ai-payment-options').style.display = checked ? 'flex' : 'none';
    }

    function toggleAIPayload() {
        var panel = document.getElementById('eo-ai-payload-panel');
        var content = document.getElementById('eo-ai-payload-content');
        var btn = document.getElementById('eo-btn-show-payload');
        if (!panel) return;
        if (panel.style.display === 'none') {
            if (state.aiResult && content) {
                content.textContent = JSON.stringify(state.aiResult, null, 2);
            }
            panel.style.display = 'block';
            if (btn) btn.classList.add('active');
        } else {
            panel.style.display = 'none';
            if (btn) btn.classList.remove('active');
        }
    }

    function populateAIPaymentSelects() {
        var bankSel = document.getElementById('eo-ai-payment-bank');
        if (bankSel && state.banksData) {
            bankSel.innerHTML = '<option value="">' + (L.selectBankAccount || 'Select bank') + '</option>';
            state.banksData.forEach(function (b) {
                var curr = b.currency_code ? ' (' + b.currency_code + ')' : '';
                var num = b.number ? ' - ' + b.number : '';
                bankSel.innerHTML += '<option value="' + b.rowid + '">' + b.label + num + curr + '</option>';
            });
        }
        var paySel = document.getElementById('eo-ai-payment-type');
        if (paySel && state.paymentTypesData) {
            paySel.innerHTML = '<option value="">' + (L.selectPaymentMode || 'Select mode') + '</option>';
            var seen = {};
            state.paymentTypesData.forEach(function (pt) {
                if (!seen[pt.id]) {
                    seen[pt.id] = true;
                    paySel.innerHTML += '<option value="' + pt.id + '">' + pt.label + '</option>';
                }
            });
        }
        // Populate journal selector
        var journalSel = document.getElementById('eo-ai-journal');
        if (journalSel && state.journalsData) {
            journalSel.innerHTML = '<option value="">' + (L.selectJournal || '-- Diario automático --') + '</option>';
            state.journalsData.forEach(function (j) {
                journalSel.innerHTML += '<option value="' + j.code + '">' + j.code + ' - ' + j.label + '</option>';
            });
        }
    }

    function setSelectionValue(label, value) {
        for (var p = 0; p < state.pages.length; p++) {
            for (var s = 0; s < state.pages[p].selections.length; s++) {
                if (state.pages[p].selections[s].label === label) {
                    state.pages[p].selections[s].text = value;
                    return true;
                }
            }
        }
        return false;
    }

    function getNestedValue(obj, key) {
        if (!obj || typeof obj !== 'object') return undefined;
        if (obj[key] !== undefined) return obj[key];
        var keys = Object.keys(obj);
        for (var i = 0; i < keys.length; i++) {
            if (typeof obj[keys[i]] === 'object' && obj[keys[i]] !== null) {
                if (obj[keys[i]][key] !== undefined) return obj[keys[i]][key];
            }
        }
        return undefined;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
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
        runAIOcr,
        applyAIResult,
        createAIInvoice,
        toggleAIPayment,
        toggleAIPayload,
        closeAIModal,
        aiAddLine,
    };

})();
