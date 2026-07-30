# EasyOcr - Módulo Dolibarr v16

## Información del módulo
- **Nombre:** EasyOcr
- **Versión:** 2.7.0
- **Número módulo:** 402020
- **Empresa:** EasySoft Tech S.L. (info@easysoft.es)
- **Autor:** Alberto Luque Rivas (aluquerivasdev@gmail.com)
- **Carpeta:** `/custom/easyocr/`

---

## Descripción
Herramienta de extracción de texto de facturas PDF para Dolibarr ERP. Permite:
- Importar PDFs y visualizarlos en un visor interactivo de 2 paneles
- Seleccionar zonas del PDF con etiquetas (Fecha, Factura, HT totales, Precio total)
- Extraer texto nativo vía PDF.js (sin Tesseract)
- Guardar/cargar plantillas de selección asociadas a proveedores
- Generar facturas de proveedor automáticamente en Dolibarr

---

## Estructura de archivos

```
easyocr/
├── admin/
│   └── setup.php                          # Página "Acerca de" del módulo
├── ajax/
│   └── ajax_easyocr.php                   # Handler AJAX (newInvoice, getDetails, templates)
├── core/
│   └── modules/
│       └── modEasyocr.class.php           # Descriptor del módulo Dolibarr
├── css/
│   ├── easyocr.css                        # Estilos principales (prefijo .eo-*)
│   ├── panel.css                          # Estilos panel de listados
│   ├── styles.css                         # Estilos base listados
│   └── upload.css                         # Estilos upload (legacy)
├── img/
│   ├── invoice.png                        # Icono facturas
│   ├── templates.png                      # Icono plantillas
│   └── ...                                # Otros iconos
├── js/
│   ├── scripts.js                         # Motor principal (namespace EasyOcr, IIFE)
│   └── panel.js                           # JS para paneles de listado
├── libraries/
│   └── notify.min.js                      # Librería de notificaciones
├── sql/
│   ├── llx_easyocr_invoices.sql           # Tabla de facturas procesadas
│   ├── llx_easyocr_invoices.key.sql       # FK → llx_ecm_files
│   ├── llx_easyocr_template.sql           # Tabla de plantillas (con fk_soc)
│   ├── llx_easyocr_template_details.sql   # Detalle de selecciones por plantilla
│   └── llx_easyocr_template_details.key.sql # FK → llx_easyocr_template
├── extract.php                            # Página principal: visor PDF + sidebar
├── templates.php                          # Listado de plantillas
├── templates_view.php                     # Edición de plantilla
├── invoices.php                           # Listado de facturas procesadas
└── claude.md                              # Este archivo
```

---

## Tablas SQL

| Tabla | Descripción |
|-------|-------------|
| `llx_easyocr_invoices` | Registro de facturas creadas (FK a `llx_ecm_files`) |
| `llx_easyocr_template` | Plantillas de selección (nombre, fk_soc, fecha) |
| `llx_easyocr_template_details` | Rectángulos de cada plantilla (posición, color, etiqueta) |

---

## Convenciones de nomenclatura

| Contexto | Prefijo/Namespace |
|----------|-------------------|
| CSS clases | `.eo-*` (eo = EasyOcr) |
| DOM IDs | `eo-*` |
| JS namespace | `EasyOcr` (IIFE) |
| Tablas BD | `easyocr_*` |
| PHP rights_class | `easyocr` |
| Menú mainmenu | `easyocr` |
| Picto | `easyocr@easyocr` |

---

## Dependencias externas
- **PDF.js 2.10.377** (CDN) — Renderizado y extracción de texto nativo de PDFs
- **jQuery** — AJAX (incluido por Dolibarr)
- **notify.min.js** — Notificaciones (local)

---

## Historial de cambios

### v2.7.0 — Fidelidad de línea, vinculación de producto y anti-duplicados
- **Fix CRÍTICO (precios ×1000):** `easyocrParseNumber()` recibía los importes como `float` nativo del JSON del micro, los convertía a cadena y les aplicaba la heurística de formato europeo: con 3 decimales, el separador se leía como millar y **3,434 € pasaba a 3.434 €**. Afectaba a proveedores que facturan con 3+ decimales por unidad, en extracción y en webhook. Ahora `int`/`float` se devuelven tal cual y la heurística solo se aplica a cadenas. Detectado por los tests de integración de esta versión.
- **Fix (líneas descuadradas):** el micro redondea `unit_price` a 2 decimales, así que 1.500 × 3,434 € llegaba como 1.500 × 3,43 = 5.145,00 € frente a los 5.150,50 € del documento → factura con líneas que no suman su total. `easyocrResolveLineUnitPrice()` reconcilia el precio impreso contra `net_amount` y, si no lo reproduce, deriva el precio del neto (recuperando 0,347 y 3,4337). `easyocrResolveLineDiscount()` añade el guardarraíl `qty × 0,005` para no confundir ese redondeo con un descuento (inventaba un 0,857 % en una línea de 1.000 ud). Fijado con las 8 líneas reales del TEST-01.
- **Fix (coma decimal):** la cantidad usaba `floatval()` (`3,5` → `3`) y las tasas/totales editados en el modal usaban `parseFloat()` (`21,5` → `21`). Ahora `easyocrParseNumber()` en PHP y `eoParseNum()` en JS.
- **Fix (datos incorrectos en producción):** el micro devuelve `discount_amount` por línea y el módulo lo ignoraba (solo leía `discount_percent`). En facturas con descuento en euros el descuento se perdía y, además, el `unit_price` reconstruido desde `net_amount` salía mal. Nueva **cascada de descuento** `easyocrResolveLineDiscount()`: `discount_percent` → `discount_amount / (qty × unit_price)` → **hueco implícito** entre `qty × unit_price` y `net_amount`. Doble umbral anti-ruido (absoluto 0,02 € y relativo 0,5 %) y descarte por encima del 90 % (lectura errónea del OCR).
- **Fix (doble descuento latente):** al reconstruir el precio unitario desde `total`, no se "des-descontaba", así que Dolibarr volvía a aplicar `remise_percent` sobre un importe ya neto. Extraído a `easyocrResolveLineUnitPrice()`, que siempre devuelve precio **bruto** (pre-descuento), como espera `addline()`.
- **Guard emisor/receptor** (`easyocrIsOwnCompanyTaxId()`): si el CIF extraído como proveedor es el de tu propia empresa, se aborta en vez de crearte a ti mismo como proveedor en `llx_societe`. También detecta `supplier.tax_id == customer.tax_id`. Escape: ajuste `EASYOCR_ALLOW_SELF_SUPPLIER` (OFF por defecto).
- **Contexto de receptor en el prompt** (`easyocrBuildReceiverContext()` + `easyocrAugmentInstructions()`): se antepone a `custom_instructions` el nombre y los IDs fiscales de `$mysoc` (con y sin prefijo de país) en `aiOcr`, `aiOcrStream` y lotes. Va **después** del gate de plan: es una salvaguarda de corrección del ERP, no una instrucción de usuario.
  - ⚠️ **Reglas del bloque (aprendidas rompiéndolo dos veces, con tests que las fijan):** (1) nada de procedimientos — "verifica antes de devolver", "vuelve a leer el documento" es carísimo con Gemini 2.5 Flash en salida estructurada y `thinking_budget=0`; (2) **nada de afirmaciones sobre el contenido** — decir "el proveedor es la otra empresa del documento" es falso si emisor y receptor coinciden, y ante lo imposible el modelo degeneró repitiendo `\n` en `supplier.address` hasta agotar los 8.192 tokens (~75 s + JSON truncado + `parse_error`). Solo identidad + "extract exactly as printed", en una línea.
  - Interruptor `EASYOCR_AI_RECEIVER_CONTEXT`, **OFF por defecto**: es lo único de v2.7.0 que toca la petición al servicio de IA. Apagado, el payload es idéntico al de v2.6.0 y `easyocrAugmentInstructions()` es un no-op. El guard server-side no depende de él.
- **Preselección de pago por histórico** (`easyocrGetSupplierPaymentDefaults()`): ficha del proveedor primero y, para lo que falte, la moda de las **3 últimas facturas** de ese proveedor (condiciones, modo y `fk_account`). El pago automático del webhook usa esa cuenta cuando no se indica ninguna.
- **Vinculación de producto por línea** en la revisión IA: nueva columna con badge de estado (✔ ref / — sin vincular), resolución automática vía acción `resolveProductCodes` (mismo orden que la creación: `ref_fourn` del proveedor → `ref`/`barcode`) y buscador inline. `searchProducts` ahora busca también por `ref_fourn` acotado al proveedor. Un `fk_product` elegido a mano **gana** sobre cualquier lookup.
- **Aviso de descuadre**: banner en el modal cuando la suma de líneas no cuadra con los totales del documento (tolerancia 0,05); en servidor, `easyocrCheckTotalsConsistency()` deja aviso en el log y devuelve `totals_warnings`. RE e IRPF no cuentan como IVA.
- **Anti-duplicados por huella** (`llx_easyocr_processed_files`, sha256): antes de gastar créditos se comprueba si el documento ya se procesó, en `aiOcr`, `aiOcrStream` y `batchUploadFile`. Nunca descarta en silencio: pregunta y permite `force_reprocess`. El hash se vincula a la factura creada.
  - **Parametrizable**: `EASYOCR_DUPLICATE_CHECK` (ON por defecto) y `EASYOCR_DUPLICATE_WINDOW_DAYS` (0 = sin límite). Con el check OFF se sigue registrando la huella, para que el histórico esté completo al reactivarlo. La ventana se calcula con `dol_now()` en PHP, no con `NOW()` de SQL (las fechas se guardan en GMT). Reprocesar un documento conocido refresca su `date_creation`: la ventana cuenta desde la última vez, no desde la primera.
- **Diálogos propios** (`EasyOcr.confirm()` en `js/scripts.js`): sustituye a `window.confirm()` en el aviso de duplicado (extract + batch), cancelar lote y papelera. Modal con clases del módulo, cierre con Escape/clic fuera, variante `danger`. Al ser asíncrono, `confirmReprocess()` recibe callbacks en vez de devolver booleano. ⚠️ `const EasyOcr = ...` **no** crea `window.EasyOcr`; por eso el IIFE lo asigna explícitamente al final.
- **Visor JSON** (`EoJsonViewer`, botón «JSON» del modal IA): puerto ES5 del componente `jv-*` del panel (`resources/views/partials/_json-viewer.blade.php`), sin la fuente de `fonts.bunny.net` del original. Árbol plegable, búsqueda con resaltado, copiar (con fallback a `execCommand` en HTTP plano) y pantalla completa. Se reconstruye en cada apertura y se destruye al cerrar el modal.
- **Guard de payload inutilizable** (`easyocrAiResultIsUsable()`): el servicio devuelve 200/`success` aunque el modelo no logre emitir JSON (`structured_data = {raw, parse_error}`). Antes se aceptaba y **se registraba la huella**, así que el reintento del mismo PDF se rechazaba por duplicado tras haber gastado créditos para nada. Aplicado en `aiOcr`, en `aiOcrStream` (el proxy SSE vigila el marcador `"parse_error"` en el flujo, con cola de 16 B por si cae partido) y en el cliente (`aiPayloadIsUsable()`). ⚠️ Las huellas registradas antes del arreglo persisten: hay que borrarlas de `llx_easyocr_processed_files` o aceptar el reproceso.
- **`import_key` es `varchar(14)`**: con `STRICT_TRANS_TABLES` un valor más largo no se trunca, revienta la sentencia entera y la factura se queda sin marca de origen. Se trunca a 14 y el fallo del `UPDATE` ya se loguea.
- 44 claves nuevas traducidas a los 8 idiomas (ca/de/en/es/fr/gl/it/pt).
- **Tests** (ninguno con dependencias externas): `php tests/easyocr_lib_test.php` (152, funciones puras) · `node tests/easyocr_jsonviewer_test.js` (26, visor + escapado XSS) · `php tests/easyocr_integration_test.php` (75, crea factura real en Dolibarr y relee la BD, todo en transacción con rollback) · `php tests/easyocr_e2e_test.php --spend-credits` (65, PDF real → IA real → factura real, **gasta créditos**).
- **Rendimiento medido del servicio** (no del módulo): TEST-01 ~10 s / 1.567 tokens de salida. Los PDFs 02 (emisor=receptor) y 03 (descuadre) hacen degenerar al modelo hasta su techo: **~77 s y 19.985 tokens**, con `parse_error`. Reproducible llamando al servicio **sin instrucciones**, con `EASYOCR_AI_RECEIVER_CONTEXT` apagado: no lo causa el módulo.
- **Migración:** requiere reactivar el módulo para crear `llx_easyocr_processed_files`.

### v2.6.0 — Escaneo de tickets de gasto desde el móvil (PWA, solo IA)
- **Feat:** nueva vista **`scan-expense.php`** mobile-first para que un empleado fotografíe un ticket de gasto y quede registrado en Dolibarr. Instalable como **PWA** (`manifest.json.php` + `sw.js.php`) con captura de cámara. **Solo IA**: sin plan con créditos muestra pantalla de bloqueo (reutiliza `easyocr_ajax_check_can_process()` / verdad operativa `/me`), con pre-flight antes de subir la foto y re-chequeo server-side.
- **Diana contable configurable** (`EASYOCR_EXPENSE_TARGET`, por entidad), 3 opciones: **nota de gastos** (`expensereport`, por defecto — correcto para "empleado que adelanta dinero", con reembolso nativo), **factura de compra** (reutiliza `easyocrCreateInvoiceFromOCR()`) o **pago diverso** (`easyocrCreateVariousPaymentFromOCR()` → `PaymentVarious`; requiere cuenta bancaria + método en ajustes; sin vínculo a empleado ni desglose de IVA).
- **Dependencia**: el descriptor declara `$this->depends = array('modExpenseReport')` para auto-activar el módulo Notas de gastos al activar EasyOcr (la diana por defecto lo necesita). Requiere reactivar EasyOcr para que surta efecto.
- Nueva **`easyocrCreateExpenseFromOCR()`** en `lib/easyocr.lib.php`: nota de gastos con `fk_user_author`=empleado, línea TTC→HT/IVA (`calcul_price_total`), foto adjunta al ECM y enlazada por línea (`fk_ecm_files`), proyecto (`fk_project`) y validación opcional.
- **Asociar a proyecto** en ambas dianas: añadido `fk_project` a `easyocrCreateInvoiceFromOCR()` (cabecera) y por línea en la nota de gastos.
- Ajuste **"Permitir validar gasto"** (`EASYOCR_EXPENSE_ALLOW_VALIDATE`): borrador por defecto; si se activa, el empleado puede validar desde el móvil. **Foto obligatoria.**
- Acciones AJAX `expenseOcr` (OCR de la foto: envía la imagen a `/ocr/base64` con `filename` correcto + `preprocess`, el micro la acepta nativa) y `newExpenseAI` (crea con dispatch por diana). Entrada de menú y tarjeta en `index.php` "Escanear gasto", CSS móvil (`css/scan-expense.css`), JS (`js/scan-expense.js` con corrección EXIF + resize en cliente, botón "Instalar app" vía `beforeinstallprompt`, menús Dolibarr ocultos con `dol_hide_topmenu/leftmenu`).
- 36 claves nuevas traducidas a los 8 idiomas (ca/de/en/es/fr/gl/it/pt).
- **Requisito PWA:** la instalación/service worker requiere HTTPS con certificado válido (en local Laragon, confiar la CA en el móvil).

### v2.5.6 — Pago automático de facturas creadas por el webhook
- **Feat:** el webhook (`webhook_batch.php`) puede ahora **marcar la factura como pagada**, registrando el pago en una **cuenta bancaria** y con un **método de pago**. Antes los parámetros `create_payment` / `payment_bank_id` / `payment_type_id` se pasaban vacíos/en cero, por lo que la factura se creaba pero nunca se pagaba (la lógica ya existía en `easyocrCreateInvoiceFromOCR()`, solo faltaba cablearla desde el webhook).
- **Doble fuente de configuración (la URL tiene prioridad):**
  1. **Config del módulo** (`admin/setup.php`, sección "Pago automático (Webhook)"): `EASYOCR_WEBHOOK_MARK_PAID` (toggle), `EASYOCR_WEBHOOK_BANK_ID` (selector de cuenta bancaria), `EASYOCR_WEBHOOK_PAYMENT_TYPE` (selector de método de pago).
  2. **Parámetros de URL** por lote: `?pay=1&bank_id=N&payment_type=N` sobrescriben la config global.
- **Requisito:** el pago solo se registra si la factura se crea **validada** (no borrador). Si `EASYOCR_INVOICE_DRAFT=1`, el pago se omite y se deja aviso en el log del webhook. También se avisa si se pide marcar pagada sin cuenta bancaria.
- Nuevas líneas de trazabilidad en el log del webhook (`create_payment`, `bank_id`, `payment_type`) y en `DEBUG-PARAMS`.
- 7 claves nuevas traducidas a los 8 idiomas (ca/de/en/es/fr/gl/it/pt). Selectores nativos Dolibarr `select_comptes` / `select_types_paiements` (este último con `nooutput=1` para evitar imprimir el contador de cuentas).
- Retrocompatible: con el toggle OFF y sin parámetros de URL, el comportamiento anterior (factura sin pago) se mantiene intacto.

### v2.5.4 — Rattachement de producto por referencia de proveedor (ref_fourn)
- **Fix:** `fk_product` ahora se resuelve buscando el `code` OCR en `llx_product_fournisseur_price.ref_fourn` filtrado por `fk_soc` (el `code` ES la referencia del proveedor). Antes solo se buscaba en `product.ref`/`barcode`, por lo que productos existentes cuya ref interna difiere del `code` (p.ej. `S057` con `ref_fourn` `04810007`) nunca se enlazaban y `fk_product` quedaba `null` (issue cliente AU PETRIN — factura PROV853).
- Nuevo orden de matching en `easyocrCreateInvoiceFromOCR()`: **1)** `product_fournisseur_price.ref_fourn` por proveedor → **2)** `product.ref`/`barcode` (fallback) → **3)** auto-creación.
- **Auto-creación de productos** ahora es opt-in mediante el nuevo ajuste `EASYOCR_AI_AUTOCREATE_PRODUCT` (**OFF por defecto**) para no ensuciar el catálogo con duplicados. Toggle en `admin/setup.php` + 2 claves traducidas a los 8 idiomas (ca/de/en/es/fr/gl/it/pt).
- Log de trazabilidad cuando una línea empareja por `ref_fourn`.
- Retrocompatible: el fallback por `product.ref`/`barcode` se mantiene; con el toggle OFF, las líneas sin coincidencia quedan como línea libre (con su `ref` ya persistida desde 2.5.3). ⚠️ Cambio de comportamiento: la auto-creación, que antes era implícita, ahora requiere activar el ajuste.

### v2.5.3 — Persistencia del CODE OCR en la referencia de línea (Réf. produit fournisseur)
- **Fix:** el `code` extraído por la IA ahora se guarda en `llx_facture_fourn_det.ref` (campo "Réf. produit fournisseur"). Antes solo se usaba para resolver/crear `fk_product` y se perdía en la línea (issue cliente AU PETRIN — Dolibarr 23.0.2 / Nouvelle-Calédonie).
- `easyocrCreateInvoiceFromOCR()` en `lib/easyocr.lib.php`: la llamada a `FactureFournisseur::addline()` se amplía de 14 a 21 argumentos posicionales para pasar el `code` como `$ref_supplier` (parámetro 21). Posición verificada estable en el core Dolibarr v14→v23; el arg 17 `array_options` (`array()`) está protegido por `is_array()` en el core → sin riesgo en PHP 8.
- El `code` se captura ANTES del gate `skipProductMatch`, así se conserva también en líneas service/discount/surcharge/other (no solo product).
- Log del fallo de auto-creación de producto (antes silencioso) + `ref` añadido al `dol_syslog` de cada línea para trazabilidad.
- Retrocompatible: sin `code`, se pasa cadena vacía (comportamiento anterior intacto).

### v2.5.2 — Verdad operativa `/me` (sub overdue / bloqueos)
- Lectura de `status.can_process`, `status.block_code`, `status.block_message`, `subscription.is_overdue`, `quota.pages_available_now` introducidos por `easyOCR-PANEL` v2.5+.
- Botón "AI Extract" en `extract.php` se renderiza `disabled` con banner cuando hay bloqueo. Pantalla LOCKED en `batch.php`. Banner + filas nuevas en `admin/plan.php`.
- Helper compartido `easyocr_ajax_check_can_process()` en `ajax_easyocr.php` con cache estática por petición. Gates pre-flight en `aiOcr`, `aiOcrStream` y `batchCreateFromUploads`.
- Poller JS sincroniza el botón AI en tiempo real (cada 5 s) según `can_process`.
- 14 claves nuevas traducidas a los 8 idiomas (ca/de/en/es/fr/gl/it/pt).
- Retrocompatible: fail-open si la API antigua no expone `status.*`.

### v2.5.1 — Hardening Multiempresa
- Webhook batch lee `?entity=N` y fuerza `$conf->entity` (antes caía a 1 por `NOLOGIN`).
- Cross-tenant write/destrucción en `template_details` cerrado: nueva columna `entity`, queries acotadas y validación de pertenencia en `updateTemplate`.
- Logs/debug del webhook aislados por entidad en `entity_N/...`.
- Búsqueda de admin filtrada por entidad en `easyocrCreateInvoiceFromOCR()`.
- Typo `'atohtml'` → `'alphanohtml'` en GETPOST.
- Migración: `sql/llx_easyocr_template_details.alter2.sql`.

### v2.4.5 — Correcciones de issues (#1, #2, #3)
- Fix radio button estado factura ignoraba `EASYOCR_INVOICE_DRAFT` (#1)
- Fix fallback SSE→AJAX reutilizaba PDF anterior tras error (#2)
- Fix PDF adjuntos inaccesibles en facturas borrador por paréntesis en nombre (#3)
