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
        customInstructions: '', // Instrucciones personalizadas para IA (por plantilla/proveedor)
        selectedSupplierID: null, // Proveedor seleccionado/detectado por CIF en modal AI
        lastFileHash: '',     // Huella del documento procesado (dedupe + vínculo con factura)
        aiPayloadViewer: null // Instancia del visor JSON del modal IA (botón JSON)
    };

    // Historial para undo
    const history = [];
    const MAX_HISTORY = 30;

    // Etiquetas disponibles
    const tags = [
        { label: L.labelDate || "Invoice date", color: "#6c3483" },
        { label: L.labelInvoice || "Invoice", color: "#2980b9" },
        { label: L.labelHT || "Total excl. tax", color: "#c0392b" },
        { label: L.labelTTC || "Total price", color: "#d4458b" },
        { label: L.labelIVA || "Tax amount", color: "#ff6b35" },
        { label: L.labelDesc || "Description", color: "#27ae60" },
        { label: L.labelCIF || "Tax ID", color: "#16a085" },
        { label: L.labelDueDate || "Due date", color: "#f39c12" },
    ];

    // Toast stacking
    let toastCount = 0;

    // ---- Utilidades ----
    function colorWithAlpha(hex, alpha) {
        const num = parseInt(hex.replace('#', ''), 16);
        const r = (num >> 16) & 0xff;
        const g = (num >> 8) & 0xff;
        const b = num & 0xff;
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
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
        // Use innerHTML for warn/error to allow links; textContent for success (safe)
        if (type === 'warn' || type === 'error') {
            el.innerHTML = msg;
        } else {
            el.textContent = msg;
        }
        const offset = 20 + (toastCount * 52);
        el.style.bottom = offset + 'px';
        document.body.appendChild(el);
        toastCount++;
        // Longer display for errors/warnings (6s) vs success (3s)
        var duration = (type === 'error' || type === 'warn') ? 6000 : 3000;
        setTimeout(() => {
            el.classList.add('eo-toast-out');
            setTimeout(() => {
                el.remove();
                toastCount = Math.max(0, toastCount - 1);
            }, 300);
        }, duration);
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
                    <button class="eo-sel-delete" onclick="EasyOcr.removeOcrSelection(${sel.pageIdx}, ${sel.selIdx})" title="${L.deleteSelection || 'Eliminar'}">✕</button>
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
    function removeOcrSelection(pageIdx, selIdx) {
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
            page.ctx.fillStyle = colorWithAlpha(sel.color, 0.25);
            page.ctx.fillRect(sel.pos_x, sel.pos_y, sel.sel_w, sel.sel_h);

            page.ctx.strokeStyle = sel.color;
            page.ctx.lineWidth = 2;
            page.ctx.strokeRect(sel.pos_x, sel.pos_y, sel.sel_w, sel.sel_h);

            const labelH = 18;
            const closeW = 18;
            page.ctx.fillStyle = sel.color;
            page.ctx.font = 'bold 11px sans-serif';
            const textW = page.ctx.measureText(sel.label).width + 12;
            // Label background + close button area
            page.ctx.fillRect(sel.pos_x, sel.pos_y - labelH, textW + closeW, labelH);
            // Label text
            page.ctx.fillStyle = '#fff';
            page.ctx.fillText(sel.label, sel.pos_x + 6, sel.pos_y - 5);
            // Separator line
            page.ctx.strokeStyle = 'rgba(255,255,255,0.45)';
            page.ctx.lineWidth = 1;
            page.ctx.beginPath();
            page.ctx.moveTo(sel.pos_x + textW, sel.pos_y - labelH + 3);
            page.ctx.lineTo(sel.pos_x + textW, sel.pos_y - 3);
            page.ctx.stroke();
            // Close "✕" glyph
            page.ctx.fillStyle = '#fff';
            page.ctx.font = 'bold 12px sans-serif';
            const xGlyph = '✕';
            const xGlyphW = page.ctx.measureText(xGlyph).width;
            page.ctx.fillText(xGlyph, sel.pos_x + textW + (closeW - xGlyphW) / 2, sel.pos_y - 4);

            const hs = 6;
            page.ctx.fillStyle = sel.color;
            [[sel.pos_x, sel.pos_y],
             [sel.pos_x + sel.sel_w, sel.pos_y],
             [sel.pos_x, sel.pos_y + sel.sel_h],
             [sel.pos_x + sel.sel_w, sel.pos_y + sel.sel_h]].forEach(([hx, hy]) => {
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
                    { type: 'nw', cx: s.pos_x, cy: s.pos_y },
                    { type: 'ne', cx: s.pos_x + s.sel_w, cy: s.pos_y },
                    { type: 'sw', cx: s.pos_x, cy: s.pos_y + s.sel_h },
                    { type: 'se', cx: s.pos_x + s.sel_w, cy: s.pos_y + s.sel_h },
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
                const bx = s.pos_x + textW;
                const by = s.pos_y - labelH;
                if (x >= bx && x <= bx + closeW && y >= by && y <= by + labelH) {
                    return i;
                }
            }
            return -1;
        }

        function getSelectionAt(x, y) {
            for (let i = page.selections.length - 1; i >= 0; i--) {
                const s = page.selections[i];
                if (x >= s.pos_x && x <= s.pos_x + s.sel_w && y >= s.pos_y && y <= s.pos_y + s.sel_h) {
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
                    origSel: { pos_x: sel.pos_x, pos_y: sel.pos_y, sel_w: sel.sel_w, sel_h: sel.sel_h }
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
                    offsetX: pos.x - sel.pos_x,
                    offsetY: pos.y - sel.pos_y
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
                        sel.sel_w = pos.x - sel.pos_x;
                        sel.sel_h = pos.y - sel.pos_y;
                        break;
                    case 'sw':
                        sel.sel_w = orig.pos_x + orig.sel_w - pos.x;
                        sel.pos_x = pos.x;
                        sel.sel_h = pos.y - sel.pos_y;
                        break;
                    case 'ne':
                        sel.sel_w = pos.x - sel.pos_x;
                        sel.sel_h = orig.pos_y + orig.sel_h - pos.y;
                        sel.pos_y = pos.y;
                        break;
                    case 'nw':
                        sel.sel_w = orig.pos_x + orig.sel_w - pos.x;
                        sel.sel_h = orig.pos_y + orig.sel_h - pos.y;
                        sel.pos_x = pos.x;
                        sel.pos_y = pos.y;
                        break;
                }
                redrawPage(pageIdx);
                return;
            }

            if (moving) {
                const sel = page.selections[moving.selIdx];
                sel.pos_x = pos.x - moving.offsetX;
                sel.pos_y = pos.y - moving.offsetY;
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
                page.ctx.fillStyle = state.activeTag ? colorWithAlpha(state.activeTag.color, 0.15) : 'rgba(0,0,0,0.05)';
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

                let posX = state.drawStart.x;
                let posY = state.drawStart.y;
                if (w < 0) { posX += w; w = Math.abs(w); }
                if (h < 0) { posY += h; h = Math.abs(h); }

                const tag = state.activeTag;
                const selIdx = page.selections.length;

                page.selections.push({
                    page_index: pageIdx,
                    pos_x: posX,
                    pos_y: posY,
                    sel_w: w,
                    sel_h: h,
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
        if (sel.sel_w < 0) {
            sel.pos_x += sel.sel_w;
            sel.sel_w = Math.abs(sel.sel_w);
        }
        if (sel.sel_h < 0) {
            sel.pos_y += sel.sel_h;
            sel.sel_h = Math.abs(sel.sel_h);
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

        const selLeft = sel.pos_x;
        const selTop = sel.pos_y;
        const selRight = sel.pos_x + sel.sel_w;
        const selBottom = sel.pos_y + sel.sel_h;

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
                sel.pos_x *= ratio;
                sel.pos_y *= ratio;
                sel.sel_w *= ratio;
                sel.sel_h *= ratio;
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
            data: { action: "loadFormData" },
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
            data: { action: "fetchTemplateData", template_id: tplId },
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
                        const pageIdx = parseInt(item.page_index);
                        if (state.pages[pageIdx]) {
                            const page = state.pages[pageIdx];
                            const selIdx = page.selections.length;

                            page.selections.push({
                                page_index: pageIdx,
                                pos_x: parseFloat(item.pos_x) * ratio,
                                pos_y: parseFloat(item.pos_y) * ratio,
                                sel_w: parseFloat(item.sel_w) * ratio,
                                sel_h: parseFloat(item.sel_h) * ratio,
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
        const details = collectCurrentSelections();

        // Update state
        state.customInstructions = customInstr;
        updateCustomInstructionsUI();

        $.ajax({
            url: "ajax/ajax_easyocr.php",
            type: 'POST',
            dataType: 'json',
            data: {
                action: "saveNewTemplate",
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

    function updateCurrentTemplate() {
        if (!state.templateId) return;

        showLoader();
        const details = collectCurrentSelections();
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
                action: "updateTemplate",
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

    function collectCurrentSelections() {
        return state.pages.flatMap(page => page.selections.map(sel => Object.assign({}, sel)));
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
        formData.append('action', 'createSupplierInvoice');
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
                    var msg = L.invoiceAlreadyExists || 'La factura ya existe';
                    if (data.existing_ref) {
                        msg += ': ' + data.existing_ref;
                    }
                    if (data.existing_ref_supplier) {
                        msg += ' (Ref: ' + data.existing_ref_supplier + ')';
                    }
                    if (data.existing_id) {
                        msg += ' <a href="../../fourn/facture/card.php?facid=' + data.existing_id + '" target="_blank" style="color:#fff;text-decoration:underline;">' + (L.viewInvoice || 'Ver factura') + '</a>';
                    }
                    toast(msg, 'warn');
                } else {
                    toast(data.message || L.errorGeneratingInvoice, 'error');
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
                if (state.pages.length > 0 && collectCurrentSelections().length > 0) {
                    e.preventDefault();
                    if (state.templateId) {
                        updateCurrentTemplate();
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
            var inTemplateRow = $(this).closest('.eo-template-row').length > 0;
            $(this).select2({
                width: inTemplateRow ? 'resolve' : '100%',
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
        state.lastFileHash = '';
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

        // Bloqueo operativo desde /me (sub overdue, monedero vacío, etc.).
        // El botón ya queda disabled por PHP/poller, pero si se invoca por
        // teclado o programáticamente, abortamos con un toast claro.
        var btnAiCheck = document.getElementById('eo-btn-ai-ocr');
        if (btnAiCheck && btnAiCheck.dataset.canProcess === '0') {
            var msg = btnAiCheck.dataset.blockMessage
                || L.processingBlocked
                || 'No puedes procesar ahora. Revisa el estado de tu suscripción.';
            toast(msg, 'warn');
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
    function runAIOcrSSE(base64, force) {
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
        if (force) {
            formData.append('force_reprocess', '1');
        }

        var gotRealEvent = false;

        fetch('ajax/ajax_easyocr.php', {
            method: 'POST',
            body: formData
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            // Fingerprint comes back as a header so older cached clients ignore it
            state.lastFileHash = response.headers.get('X-EasyOcr-File-Hash') || '';
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
            if (resultData && resultData.__duplicate) {
                // Already processed: re-run only if the user accepts the cost
                var info = resultData.info || {};
                confirmReprocess(info, function () {
                    runAIOcrSSE(base64, true);
                }, function () {
                    state.lastFileHash = info.file_hash || '';
                    toast(info.message || (L.aiDuplicateFile || 'Document already processed'), 'warn');
                });
                return;
            }
            if (resultData && !aiPayloadIsUsable(resultData)) {
                // The model answered but could not produce JSON. Showing an empty
                // review modal would look like the document had no data in it.
                toast(L.aiUnreadable || 'The service could not read this document. Try again.', 'error');
                return;
            }
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
            // Re-encode from current pdfArrayBuffer in case the user loaded a new PDF
            // since the SSE request was initiated (base64 in closure may be stale)
            if (state.pdfArrayBuffer) {
                var freshBytes = new Uint8Array(state.pdfArrayBuffer);
                var freshBinary = '';
                var chunkSize = 8192;
                for (var i = 0; i < freshBytes.length; i += chunkSize) {
                    freshBinary += String.fromCharCode.apply(null, freshBytes.subarray(i, i + chunkSize));
                }
                runAIOcrClassic(btoa(freshBinary));
            } else {
                toast(L.aiOcrError || 'AI OCR service error', 'error');
            }
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
                        } else if (eventType === 'duplicate') {
                            // Server refused to spend credits on a known document
                            resolve({ __duplicate: true, info: data });
                            return;
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

    /**
     * Ask the user whether to re-run the AI on a document already processed.
     * Re-processing spends credits again, so it is never automatic.
     * Asynchronous: the answer arrives through onAccept / onDecline.
     */
    function confirmReprocess(info, onAccept, onDecline) {
        eoConfirm({
            title: L.aiDuplicateTitle || 'Document already processed',
            message: info.message || (L.aiDuplicateFile || 'This document was already processed.'),
            meta: [
                { label: L.aiDuplicateFileLabel || 'File', value: info.filename },
                { label: L.aiDuplicateProcessedOn || 'Processed on', value: info.processed_on },
                { label: L.aiLinkedInvoice || 'Invoice', value: info.invoice_ref }
            ],
            question: L.aiReprocessAnyway || 'Process it again anyway? This will consume AI credits.',
            confirmLabel: L.aiReprocessConfirm || 'Process again',
            cancelLabel: L.cancel || 'Cancel',
            onConfirm: onAccept,
            onCancel: onDecline
        });
    }

    /* ---------- Classic AJAX fallback with simulated progress ---------- */
    function runAIOcrClassic(base64, force) {
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
            data: {
                action: "aiOcr",
                base64_data: base64,
                custom_instructions: state.customInstructions || '',
                file_name: (state.file && state.file.name) || '',
                force_reprocess: force ? 1 : 0
            },
            success: function (response) {
                stopSimulatedProgress();
                if (fillEl) fillEl.style.width = '100%';
                if (textEl) textEl.textContent = '';
                resetAIProgress();
                if (response.status === 'duplicate') {
                    // Already processed: re-run only if the user accepts the cost
                    confirmReprocess(response, function () {
                        runAIOcrClassic(base64, true);
                    }, function () {
                        state.lastFileHash = response.file_hash || '';
                        toast(response.message || (L.aiDuplicateFile || 'Document already processed'), 'warn');
                    });
                    return;
                }
                if (response.status === 'ok' && response.data) {
                    state.aiResult = response.data;
                    state.lastFileHash = response.file_hash || '';
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
                tbody.innerHTML = '<tr><td colspan="12" class="eo-ai-empty-lines">' + (L.aiNoLines || 'No line items') + '</td></tr>';
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

        // Resolve OCR codes against the catalogue so the user sees which lines
        // will actually be linked to a product, and can fix the ones that won't.
        resolveLineProducts();

        // Surface any gap between the line items and the document totals
        refreshTotalsMismatch();

        // Show modal
        document.getElementById('eo-modal-ai').style.display = 'flex';
    }

    /**
     * Parse a number the user may have typed with a comma as decimal mark.
     * parseFloat("21,5") returns 21, silently dropping the decimals.
     */
    function eoParseNum(value) {
        if (typeof value === 'number') return value;
        if (value === null || value === undefined) return 0;
        var n = parseFloat(String(value).trim().replace(',', '.'));
        return isNaN(n) ? 0 : n;
    }

    // ---- Line ↔ product linking ----

    function currentSupplierId() {
        return state.selectedSupplierID || $('#eo-supplier').val() || '';
    }

    function resolveLineProducts() {
        var rows = document.querySelectorAll('#eo-ai-lines-tbody tr[data-ai-line-idx]');
        if (!rows.length) return;

        var codes = [];
        rows.forEach(function (row) {
            var codeInput = row.querySelector('[data-ai-line-key="code"]');
            var code = codeInput ? codeInput.value.trim() : '';
            if (code && codes.indexOf(code) === -1) codes.push(code);
        });
        if (!codes.length) {
            rows.forEach(function (row) { paintProductCell(row, null); });
            return;
        }

        $.ajax({
            url: 'ajax/ajax_easyocr.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'resolveProductCodes',
                fk_soc: currentSupplierId(),
                codes: JSON.stringify(codes)
            },
            success: function (res) {
                var matches = (res && res.matches) || {};
                rows.forEach(function (row) {
                    var codeInput = row.querySelector('[data-ai-line-key="code"]');
                    var code = codeInput ? codeInput.value.trim() : '';
                    paintProductCell(row, (code && matches[code]) ? matches[code] : null);
                });
            },
            error: function () {
                // Lookup is advisory: leave the cells in their "unknown" state
            }
        });
    }

    function paintProductCell(row, match) {
        var cell = row.querySelector('.eo-ai-td-product');
        if (!cell) return;
        var hidden = cell.querySelector('[data-ai-line-key="fk_product"]');
        var badge = cell.querySelector('.eo-ai-prod-badge');
        if (!hidden || !badge) return;

        // Never overwrite a product the user picked by hand
        if (cell.getAttribute('data-manual') === '1') return;

        if (match && match.rowid) {
            hidden.value = match.rowid;
            badge.textContent = match.ref || '✔';
            badge.title = (L.aiProductLinked || 'Linked to') + ' ' + (match.ref || '') + ' — ' + (match.label || '');
            cell.setAttribute('data-state', 'linked');
        } else {
            hidden.value = '';
            badge.textContent = '—';
            badge.title = L.aiProductNotLinked || 'No product linked — the line will be created as a free-text line';
            cell.setAttribute('data-state', 'none');
        }
    }

    function buildProductCell(row) {
        var td = document.createElement('td');
        td.className = 'eo-ai-td-product';
        td.setAttribute('data-state', 'none');

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.className = 'eo-ai-td-input';
        hidden.setAttribute('data-ai-line-key', 'fk_product');
        hidden.value = '';
        td.appendChild(hidden);

        var badge = document.createElement('button');
        badge.type = 'button';
        badge.className = 'eo-ai-prod-badge';
        badge.textContent = '—';
        badge.title = L.aiProductNotLinked || 'No product linked';
        td.appendChild(badge);

        var box = document.createElement('div');
        box.className = 'eo-ai-prod-search';
        box.style.display = 'none';

        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'eo-ai-prod-input';
        search.placeholder = L.aiProductSearch || 'Search product…';
        box.appendChild(search);

        var results = document.createElement('ul');
        results.className = 'eo-ai-prod-results';
        box.appendChild(results);

        td.appendChild(box);

        badge.addEventListener('click', function (e) {
            e.preventDefault();
            var open = box.style.display !== 'none';
            closeAllProductSearches();
            if (!open) {
                box.style.display = 'block';
                // Seed the search with the OCR code so the common case is one click
                var codeInput = row.querySelector('[data-ai-line-key="code"]');
                search.value = codeInput ? codeInput.value.trim() : '';
                search.focus();
                runProductSearch(search, results, td, row);
            }
        });

        var debounce = null;
        search.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                runProductSearch(search, results, td, row);
            }, 250);
        });

        return td;
    }

    function closeAllProductSearches() {
        document.querySelectorAll('.eo-ai-prod-search').forEach(function (b) {
            b.style.display = 'none';
        });
    }

    function runProductSearch(searchInput, resultsEl, cell, row) {
        var term = searchInput.value.trim();
        resultsEl.innerHTML = '';
        if (term.length < 2) return;

        $.ajax({
            url: 'ajax/ajax_easyocr.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'searchProducts', term: term, fk_soc: currentSupplierId() },
            success: function (list) {
                resultsEl.innerHTML = '';
                if (!list || !list.length) {
                    var none = document.createElement('li');
                    none.className = 'eo-ai-prod-none';
                    none.textContent = L.aiProductNoResults || 'No products found';
                    resultsEl.appendChild(none);
                    return;
                }
                list.forEach(function (p) {
                    var li = document.createElement('li');
                    li.className = 'eo-ai-prod-item';
                    var label = p.ref + ' — ' + (p.label || '');
                    if (p.ref_fourn) label += ' [' + p.ref_fourn + ']';
                    li.textContent = label;
                    li.title = label;
                    li.addEventListener('click', function () {
                        applyProductToLine(cell, row, p);
                        closeAllProductSearches();
                    });
                    resultsEl.appendChild(li);
                });

                // Let the user detach a product they linked by mistake
                var clear = document.createElement('li');
                clear.className = 'eo-ai-prod-item eo-ai-prod-clear';
                clear.textContent = L.aiProductUnlink || 'Unlink product';
                clear.addEventListener('click', function () {
                    cell.removeAttribute('data-manual');
                    paintProductCell(row, null);
                    closeAllProductSearches();
                });
                resultsEl.appendChild(clear);
            },
            error: function () {
                resultsEl.innerHTML = '';
            }
        });
    }

    function applyProductToLine(cell, row, product) {
        var hidden = cell.querySelector('[data-ai-line-key="fk_product"]');
        var badge = cell.querySelector('.eo-ai-prod-badge');
        if (!hidden || !badge) return;

        hidden.value = product.rowid;
        badge.textContent = product.ref || '✔';
        badge.title = (L.aiProductLinked || 'Linked to') + ' ' + (product.ref || '') + ' — ' + (product.label || '');
        cell.setAttribute('data-state', 'linked');
        cell.setAttribute('data-manual', '1');

        // Fill an empty description, never overwrite what the OCR read
        var descInput = row.querySelector('[data-ai-line-key="description"]');
        if (descInput && descInput.value.trim() === '' && product.label) {
            descInput.value = product.label;
        }
    }

    // ---- Totals coherence ----

    function refreshTotalsMismatch() {
        var banner = document.getElementById('eo-ai-totals-warning');
        if (!banner) return;

        var rows = document.querySelectorAll('#eo-ai-lines-tbody tr[data-ai-line-idx]');
        if (!rows.length) {
            banner.style.display = 'none';
            return;
        }

        var sumNet = 0, sumTax = 0;
        rows.forEach(function (row) {
            var has = function (key) {
                var el = row.querySelector('[data-ai-line-key="' + key + '"]');
                return el && String(el.value).trim() !== '';
            };
            var get = function (key) {
                var el = row.querySelector('[data-ai-line-key="' + key + '"]');
                return el ? eoParseNum(el.value) : 0;
            };
            var qty = has('quantity') ? get('quantity') : 1;
            var price = get('unit_price');
            var disc = get('discount_percent');
            var net = has('total') ? get('total') : qty * price * (1 - disc / 100);
            sumNet += net;
            sumTax += net * get('tax_rate') / 100;
        });

        var docNetRaw = getTotalsFieldValue('subtotal');
        var docTaxRaw = getTotalsFieldValue('tax');
        var docNet = docNetRaw === '' ? NaN : eoParseNum(docNetRaw);
        var docTax = docTaxRaw === '' ? NaN : eoParseNum(docTaxRaw);

        var msgs = [];
        // 0.05 absorbs per-line rounding; anything larger is a real misread
        if (!isNaN(docNet) && Math.abs(docNet) > 0.005 && Math.abs(sumNet - docNet) > 0.05) {
            msgs.push((L.aiTotalsMismatchHT || 'Net') + ': ' + sumNet.toFixed(2) + ' ≠ ' + docNet.toFixed(2));
        }
        if (!isNaN(docTax) && Math.abs(docTax) > 0.005 && Math.abs(sumTax - docTax) > 0.05) {
            msgs.push((L.aiTotalsMismatchTVA || 'Tax') + ': ' + sumTax.toFixed(2) + ' ≠ ' + docTax.toFixed(2));
        }

        if (msgs.length) {
            banner.innerHTML = '<strong>' + (L.aiTotalsMismatch || 'Lines do not match document totals') + '</strong> ' + msgs.join(' · ');
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    }

    function getTotalsFieldValue(key) {
        var container = document.getElementById('eo-ai-totals-fields');
        if (!container) return '';
        var input = container.querySelector('[data-ai-key="' + key + '"]');
        return input ? String(input.value).trim() : '';
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

    function checkSupplierByCIF(cif) {
        var indicator = document.getElementById('eo-supplier-status-indicator');
        var selectorDiv = document.getElementById('eo-supplier-selector-container');

        // Reset state
        state.selectedSupplierID = null;
        if (selectorDiv) { selectorDiv.style.display = 'none'; selectorDiv.innerHTML = ''; }

        if (!cif || !cif.trim()) {
            if (indicator) indicator.style.display = 'none';
            return;
        }

        // Loading
        if (indicator) {
            indicator.style.display = '';
            indicator.textContent = '⏳';
            indicator.title = '';
        }

        $.ajax({
            url: 'ajax/ajax_easyocr.php',
            type: 'POST',
            data: { action: 'findSupplierByCIF', cif: cif.trim() },
            dataType: 'json',
            success: function(data) {
                if (data && data.status === 'ok') {
                    if (data.found_count > 1 && data.suppliers && data.suppliers.length > 1) {
                        // Multiple suppliers — show selector
                        if (indicator) indicator.style.display = 'none';
                        if (selectorDiv) {
                            var sel = document.createElement('select');
                            sel.className = 'eo-select eo-ai-field-input';
                            sel.style.width = '100%';
                            for (var i = 0; i < data.suppliers.length; i++) {
                                var opt = document.createElement('option');
                                opt.value = data.suppliers[i].id;
                                opt.textContent = data.suppliers[i].name;
                                sel.appendChild(opt);
                            }
                            state.selectedSupplierID = String(data.suppliers[0].id);
                            sel.addEventListener('change', function() {
                                state.selectedSupplierID = this.value || null;
                                // Supplier article refs are per-supplier: re-resolve
                                resolveLineProducts();
                            });
                            resolveLineProducts();
                            selectorDiv.innerHTML = '';
                            selectorDiv.appendChild(sel);
                            selectorDiv.style.display = '';
                        }
                    } else {
                        // Single supplier
                        state.selectedSupplierID = String(data.fk_soc);
                        if (indicator) {
                            indicator.style.display = '';
                            indicator.textContent = '✅';
                            indicator.title = (L.supplierAutoDetected || 'Proveedor detectado') + ': ' + (data.name || '');
                        }
                        if (selectorDiv) selectorDiv.style.display = 'none';
                        // Now that the supplier is known, its ref_fourn codes can match
                        resolveLineProducts();
                    }
                } else {
                    state.selectedSupplierID = null;
                    if (indicator) {
                        indicator.style.display = '';
                        indicator.textContent = '❌';
                        indicator.title = L.noSupplierFoundByCIF || 'Ningún proveedor encontrado con ese CIF/NIF';
                    }
                }
            },
            error: function() {
                state.selectedSupplierID = null;
                if (indicator) {
                    indicator.style.display = '';
                    indicator.textContent = '❌';
                    indicator.title = L.noSupplierFoundByCIF || 'Ningún proveedor encontrado con ese CIF/NIF';
                }
            }
        });
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
                // Any edit can change whether the lines still add up
                if (['quantity', 'unit_price', 'discount_percent', 'tax_rate', 'total'].indexOf(f.key) !== -1) {
                    input.addEventListener('change', refreshTotalsMismatch);
                }
                td.appendChild(input);
            }
            tr.appendChild(td);

            // Product column sits right after the OCR code it is resolved from
            if (f.key === 'code') {
                tr.appendChild(buildProductCell(tr));
            }
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
                refreshTotalsMismatch();
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
        destroyAIPayloadViewer();
        if (panel) panel.style.display = 'none';
        if (btn) btn.classList.remove('active');
        // Reset supplier selection from CIF lookup
        state.selectedSupplierID = null;
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
                // Reconstruct taxes array from flat columns. eoParseNum, not
                // parseFloat: a hand-typed "21,5" would otherwise become 21.
                line.taxes = [];
                if (line.tax_rate && eoParseNum(line.tax_rate) !== 0) {
                    line.taxes.push({ tax_type: 'iva', tax_rate: eoParseNum(line.tax_rate), tax_amount: 0 });
                }
                if (line.re_rate && eoParseNum(line.re_rate) !== 0) {
                    line.taxes.push({ tax_type: 're', tax_rate: eoParseNum(line.re_rate), tax_amount: 0 });
                }
                if (line.irpf_rate && eoParseNum(line.irpf_rate) !== 0) {
                    line.taxes.push({ tax_type: 'irpf', tax_rate: eoParseNum(line.irpf_rate), tax_amount: 0 });
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
        // eoParseNum throughout: these fields are hand-editable in the modal
        var subtotal = eoParseNum(editedData.totals.subtotal);
        var totalTax = eoParseNum(editedData.totals.tax);
        var totalFinal = eoParseNum(editedData.totals.total);

        if (editedData.items.length > 0 && subtotal === 0) {
            var computedSubtotal = 0;
            var computedTax = 0;
            editedData.items.forEach(function (item) {
                var qty = eoParseNum(item.quantity) || 1;
                var price = eoParseNum(item.unit_price);
                var lineTotal = eoParseNum(item.total) || (qty * price);
                var lineTax = eoParseNum(item.tax_amount);
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
        var surchargeTotal = eoParseNum(editedData.totals.surcharge);
        var withholdingTotal = eoParseNum(editedData.totals.withholding);

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
            supplier_email: editedData.supplier.email || '',
            // Lets the backend detect that the AI could not tell the two apart
            customer_tax_id: (editedData.customer && editedData.customer.tax_id) || '',
            // Ties the source document fingerprint to the invoice it creates
            file_hash: state.lastFileHash || ''
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
                msg += ' <a href="../../fourn/facture/card.php?facid=' + data.existing_id + '" target="_blank" style="color:#fff;text-decoration:underline;">' + (L.viewInvoice || 'Ver factura') + '</a>';
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
                // Rebuilt on every open: the payload changes when lines are
                // re-extracted, and the old instance holds a keydown listener.
                destroyAIPayloadViewer();
                state.aiPayloadViewer = new EoJsonViewer(content, state.aiResult, { expandDepth: 3 });
            }
            panel.style.display = 'block';
            if (btn) btn.classList.add('active');
        } else {
            destroyAIPayloadViewer();
            panel.style.display = 'none';
            if (btn) btn.classList.remove('active');
        }
    }

    function destroyAIPayloadViewer() {
        if (state.aiPayloadViewer) {
            state.aiPayloadViewer.destroy();
            state.aiPayloadViewer = null;
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

    /**
     * Whether an AI response carries usable structured data.
     *
     * The service reports success even when the model failed to emit valid JSON;
     * in that case structured_data is {raw, parse_error}. The non-streaming path
     * is filtered server-side, but the SSE proxy is a pass-through, so the check
     * has to exist here too. Accepts either the full response or structured_data.
     */
    function aiPayloadIsUsable(payload) {
        if (!payload || typeof payload !== 'object') return false;
        if (payload.error_code || payload.structuring_error) return false;

        var data = payload.structured_data || payload;
        if (!data || typeof data !== 'object') return false;
        if (data.parse_error !== undefined) return false;

        var signals = ['document_number', 'issue_date', 'supplier', 'items', 'totals'];
        for (var i = 0; i < signals.length; i++) {
            if (data[signals[i]]) return true;
        }
        return false;
    }

    /* ==========================================================
     * Confirm modal
     * ----------------------------------------------------------
     * window.confirm() is synchronous and looks nothing like the
     * module, so decisions that carry a cost (spending AI credits,
     * cancelling a batch) get a real modal instead. Callback-based,
     * because there is nothing to block on.
     *
     * opts: title, message, meta [{label,value}], question, note,
     *       confirmLabel, cancelLabel, danger, onConfirm, onCancel
     * ========================================================== */
    function eoConfirm(opts) {
        opts = opts || {};

        var overlay = document.createElement('div');
        overlay.className = 'eo-confirm-overlay' + (opts.danger ? ' eo-confirm-danger' : '');

        var icon = opts.danger
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>';

        var html = '';
        html += '<div class="eo-modal">';
        html += '  <div class="eo-modal-header">';
        html += '    <h4>' + escapeHtml(opts.title || L.confirmTitle || 'Confirm') + '</h4>';
        html += '    <button type="button" class="eo-modal-close" data-eo-confirm="cancel">&times;</button>';
        html += '  </div>';
        html += '  <div class="eo-confirm-body">';
        html += '    <div class="eo-confirm-icon">' + icon + '</div>';
        html += '    <div class="eo-confirm-text">';
        if (opts.message) {
            html += '<p class="eo-confirm-msg">' + escapeHtml(opts.message) + '</p>';
        }
        if (opts.meta && opts.meta.length) {
            html += '<ul class="eo-confirm-meta">';
            for (var i = 0; i < opts.meta.length; i++) {
                // Only empty values are dropped — a numeric 0 is information
                if (!opts.meta[i] || opts.meta[i].value == null || opts.meta[i].value === '') continue;
                html += '<li><span class="eo-confirm-meta-label">' + escapeHtml(opts.meta[i].label) + '</span>';
                html += '<span class="eo-confirm-meta-value">' + escapeHtml(String(opts.meta[i].value)) + '</span></li>';
            }
            html += '</ul>';
        }
        if (opts.question) {
            html += '<p class="eo-confirm-question">' + escapeHtml(opts.question) + '</p>';
        }
        if (opts.note) {
            html += '<p class="eo-confirm-note">' + escapeHtml(opts.note) + '</p>';
        }
        html += '    </div>';
        html += '  </div>';
        html += '  <div class="eo-modal-footer">';
        html += '    <button type="button" class="eo-btn eo-btn-outline" data-eo-confirm="cancel">' + escapeHtml(opts.cancelLabel || L.cancel || 'Cancel') + '</button>';
        html += '    <button type="button" class="eo-btn eo-btn-primary" data-eo-confirm="ok">' + escapeHtml(opts.confirmLabel || L.confirmLabel || 'Confirm') + '</button>';
        html += '  </div>';
        html += '</div>';
        overlay.innerHTML = html;

        var done = false;
        function close(accepted) {
            if (done) return;
            done = true;
            document.removeEventListener('keydown', onKey, true);
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            if (accepted) {
                if (typeof opts.onConfirm === 'function') opts.onConfirm();
            } else if (typeof opts.onCancel === 'function') {
                opts.onCancel();
            }
        }
        function onKey(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                close(false);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                close(true);
            }
        }

        overlay.addEventListener('click', function (e) {
            // Clicking the backdrop cancels; clicks inside the card do not
            if (e.target === overlay) {
                close(false);
                return;
            }
            var btn = e.target.closest ? e.target.closest('[data-eo-confirm]') : null;
            if (btn) close(btn.getAttribute('data-eo-confirm') === 'ok');
        });
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(overlay);
        var okBtn = overlay.querySelector('[data-eo-confirm="ok"]');
        if (okBtn) okBtn.focus();

        return overlay;
    }

    /* ==========================================================
     * EoJsonViewer — collapsible JSON tree
     * ----------------------------------------------------------
     * Port of the easyOCR panel's viewer (Catppuccin Mocha), kept
     * in ES5 like the rest of this file and with no webfont fetch:
     * the monospace stack in the CSS resolves locally.
     * ========================================================== */
    function EoJsonViewer(el, data, opts) {
        this.el = typeof el === 'string' ? document.getElementById(el) : el;
        this.data = data;
        this.opts = { expandDepth: (opts && opts.expandDepth != null) ? opts.expandDepth : 3 };
        if (this.el && data !== null && data !== undefined) this.render();
    }

    EoJsonViewer.prototype.render = function () {
        var stats = this.countStats(this.data, { keys: 0, values: 0 });
        var self = this;

        // esc() also escapes double quotes, so a translation containing one
        // cannot break out of the title/placeholder attributes below.
        function t(key, fallback) {
            return self.esc(L[key] || fallback);
        }

        this.el.classList.add('eo-jv');
        this.el.innerHTML = '';

        var toolbar = document.createElement('div');
        toolbar.className = 'jv-toolbar';
        toolbar.innerHTML = ''
            + '<button type="button" class="jv-btn jv-btn-expand" title="' + t('jvExpand', 'Expand') + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>'
            + t('jvExpand', 'Expand') + '</button>'
            + '<button type="button" class="jv-btn jv-btn-collapse" title="' + t('jvCollapse', 'Collapse') + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 4v1.5M9 4H4m5 0L4 9m11-5v1.5m0-1.5h5m-5 0l5 5M9 20v-1.5M9 20H4m5 0l-5-5m11 5v-1.5m0 1.5h5m-5 0l5-5"/></svg>'
            + t('jvCollapse', 'Collapse') + '</button>'
            + '<button type="button" class="jv-btn jv-btn-copy" title="' + t('jvCopy', 'Copy') + ' JSON">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>'
            + t('jvCopy', 'Copy') + '</button>'
            + '<span class="jv-stats">' + stats.keys + ' ' + t('jvKeys', 'keys') + ' &middot; ' + stats.values + ' ' + t('jvValues', 'values') + '</span>'
            + '<span class="jv-spacer"></span>'
            + '<span class="jv-match-count" style="display:none"></span>'
            + '<input type="text" class="jv-search" placeholder="' + t('jvSearch', 'Search...') + '">'
            + '<button type="button" class="jv-btn jv-btn-fs" title="' + t('jvFullscreen', 'Fullscreen') + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>'
            + '</button>';

        var body = document.createElement('div');
        body.className = 'jv-body';
        body.innerHTML = this.renderValue(this.data, 0);
        this.body = body;

        this.el.appendChild(toolbar);
        this.el.appendChild(body);
        this.bindEvents(toolbar);
    };

    EoJsonViewer.prototype.countStats = function (val, s) {
        if (val === null || val === undefined || typeof val !== 'object') {
            s.values++;
            return s;
        }
        var self = this;
        if (Object.prototype.toString.call(val) === '[object Array]') {
            for (var i = 0; i < val.length; i++) self.countStats(val[i], s);
        } else {
            Object.keys(val).forEach(function (k) {
                s.keys++;
                self.countStats(val[k], s);
            });
        }
        return s;
    };

    EoJsonViewer.prototype.renderValue = function (val, depth) {
        if (val === null) return '<span class="jv-null">null</span>';
        if (val === undefined) return '<span class="jv-null">undefined</span>';
        switch (typeof val) {
            case 'boolean': return '<span class="jv-bool">' + val + '</span>';
            case 'number': return '<span class="jv-num">' + val + '</span>';
            case 'string': return this.renderString(val);
            case 'object':
                return Object.prototype.toString.call(val) === '[object Array]'
                    ? this.renderArray(val, depth)
                    : this.renderObject(val, depth);
            default: return '<span class="jv-str">' + this.esc(String(val)) + '</span>';
        }
    };

    EoJsonViewer.prototype.renderString = function (str) {
        var escaped = this.esc(str).replace(/\n/g, '\\n').replace(/\t/g, '\\t').replace(/\r/g, '\\r');
        return '<span class="jv-str">"' + escaped + '"</span>';
    };

    EoJsonViewer.prototype.renderArray = function (arr, depth) {
        if (arr.length === 0) return '<span class="jv-bracket">[ ]</span>';
        var coll = depth >= this.opts.expandDepth ? ' jv-collapsed' : '';
        var self = this;
        var items = arr.map(function (item, i) {
            var comma = i < arr.length - 1 ? '<span class="jv-comma">,</span>' : '';
            return '<div class="jv-row"><span class="jv-idx">' + i + '</span> ' + self.renderValue(item, depth + 1) + comma + '</div>';
        }).join('');
        return '<div class="jv-node' + coll + '"><span class="jv-toggle"><span class="jv-arrow">&#9662;</span> '
            + '<span class="jv-bracket">[</span><span class="jv-ellipsis">' + arr.length + ' ' + this.esc(L.jvItems || 'items') + '</span></span>'
            + '<div class="jv-children">' + items + '</div><span class="jv-end jv-bracket">]</span></div>';
    };

    EoJsonViewer.prototype.renderObject = function (obj, depth) {
        var keys = Object.keys(obj);
        if (keys.length === 0) return '<span class="jv-bracket">{ }</span>';
        var coll = depth >= this.opts.expandDepth ? ' jv-collapsed' : '';
        var self = this;
        var items = keys.map(function (key, i) {
            var comma = i < keys.length - 1 ? '<span class="jv-comma">,</span>' : '';
            return '<div class="jv-row"><span class="jv-key">"' + self.esc(key) + '"</span><span class="jv-colon">: </span>'
                + self.renderValue(obj[key], depth + 1) + comma + '</div>';
        }).join('');
        return '<div class="jv-node' + coll + '"><span class="jv-toggle"><span class="jv-arrow">&#9662;</span> '
            + '<span class="jv-bracket">{</span><span class="jv-ellipsis">' + keys.length + ' ' + this.esc(L.jvProps || 'properties') + '</span></span>'
            + '<div class="jv-children">' + items + '</div><span class="jv-end jv-bracket">}</span></div>';
    };

    EoJsonViewer.prototype.esc = function (s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    EoJsonViewer.prototype.bindEvents = function (toolbar) {
        var self = this;

        this.body.addEventListener('click', function (e) {
            var toggle = e.target.closest ? e.target.closest('.jv-toggle') : null;
            if (toggle) {
                toggle.parentNode.classList.toggle('jv-collapsed');
                e.stopPropagation();
            }
        });
        toolbar.querySelector('.jv-btn-expand').addEventListener('click', function () {
            var nodes = self.body.querySelectorAll('.jv-node');
            for (var i = 0; i < nodes.length; i++) nodes[i].classList.remove('jv-collapsed');
        });
        toolbar.querySelector('.jv-btn-collapse').addEventListener('click', function () {
            var nodes = self.body.querySelectorAll('.jv-node');
            for (var i = 0; i < nodes.length; i++) nodes[i].classList.add('jv-collapsed');
        });
        toolbar.querySelector('.jv-btn-copy').addEventListener('click', function () {
            self.copyToClipboard(JSON.stringify(self.data, null, 2));
        });
        toolbar.querySelector('.jv-btn-fs').addEventListener('click', function () {
            self.el.classList.toggle('jv-fullscreen');
            document.body.style.overflow = self.el.classList.contains('jv-fullscreen') ? 'hidden' : '';
        });

        var debounce = null;
        var searchInput = toolbar.querySelector('.jv-search');
        var matchLabel = toolbar.querySelector('.jv-match-count');
        searchInput.addEventListener('input', function (e) {
            var value = e.target.value;
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                var count = self.search(value);
                if (value.length >= 2) {
                    matchLabel.style.display = 'inline';
                    matchLabel.textContent = count + ' ' + (L.jvResults || 'results');
                } else {
                    matchLabel.style.display = 'none';
                }
            }, 250);
        });

        // Escape leaves fullscreen. Registered in the capture phase so the AI
        // modal does not close underneath while the viewer is expanded.
        this.onKey = function (e) {
            if (e.key === 'Escape' && self.el.classList.contains('jv-fullscreen')) {
                e.stopPropagation();
                self.el.classList.remove('jv-fullscreen');
                document.body.style.overflow = '';
            }
        };
        document.addEventListener('keydown', this.onKey, true);
    };

    EoJsonViewer.prototype.search = function (query) {
        var previous = this.body.querySelectorAll('.jv-match');
        for (var i = 0; i < previous.length; i++) previous[i].classList.remove('jv-match');
        if (!query || query.length < 2) return 0;

        var lower = query.toLowerCase();
        var count = 0;
        var rows = this.body.querySelectorAll('.jv-row');
        for (var r = 0; r < rows.length; r++) {
            if (rows[r].textContent.toLowerCase().indexOf(lower) === -1) continue;
            rows[r].classList.add('jv-match');
            count++;
            // Un-collapse every ancestor so the hit is actually visible
            var p = rows[r].parentNode;
            while (p && p !== this.body) {
                if (p.classList && p.classList.contains('jv-node')) p.classList.remove('jv-collapsed');
                p = p.parentNode;
            }
        }
        var first = this.body.querySelector('.jv-match');
        if (first && first.scrollIntoView) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return count;
    };

    EoJsonViewer.prototype.copyToClipboard = function (text) {
        var self = this;
        function fallback() {
            // navigator.clipboard needs a secure context; plain HTTP installs
            // fall back to the legacy path rather than silently doing nothing.
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (err) { /* nothing else to try */ }
            document.body.removeChild(ta);
            self.showToast(L.jvCopied || 'Copied to clipboard');
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                self.showToast(L.jvCopied || 'Copied to clipboard');
            }).catch(fallback);
        } else {
            fallback();
        }
    };

    EoJsonViewer.prototype.showToast = function (msg) {
        var t = document.createElement('div');
        t.className = 'eo-jv-toast';
        t.textContent = '✓ ' + msg;
        document.body.appendChild(t);
        setTimeout(function () {
            if (t.parentNode) t.parentNode.removeChild(t);
        }, 2200);
    };

    EoJsonViewer.prototype.destroy = function () {
        if (this.onKey) document.removeEventListener('keydown', this.onKey, true);
        if (this.el) {
            this.el.classList.remove('jv-fullscreen', 'eo-jv');
            this.el.innerHTML = '';
        }
        document.body.style.overflow = '';
    };

    // Arrancar
    document.addEventListener('DOMContentLoaded', init);

    // ---- API pública ----
    return {
        selectTag,
        removeOcrSelection,
        updateSelectionText,
        loadTemplate,
        clearTemplate,
        showSaveTemplate,
        hideSaveTemplate,
        saveTemplate,
        updateCurrentTemplate,
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
        // Compartidos con batch.php / otras vistas del módulo
        confirm: eoConfirm,
        JsonViewer: EoJsonViewer,
    };

})();

// `const` at top level does not create a property on window, and other views
// (batch.php) feature-detect the shared helpers before using them.
window.EasyOcr = EasyOcr;
