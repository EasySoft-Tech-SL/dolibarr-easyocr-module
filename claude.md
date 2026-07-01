# EasyOcr - Módulo Dolibarr v16

## Información del módulo
- **Nombre:** EasyOcr
- **Versión:** 2.5.6
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
