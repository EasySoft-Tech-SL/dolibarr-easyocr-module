# EasyOcr - Módulo Dolibarr v16

## Información del módulo
- **Nombre:** EasyOcr
- **Versión:** 2.4.5
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

### v2.4.5 — Correcciones de issues (#1, #2, #3)
- Fix radio button estado factura ignoraba `EASYOCR_INVOICE_DRAFT` (#1)
- Fix fallback SSE→AJAX reutilizaba PDF anterior tras error (#2)
- Fix PDF adjuntos inaccesibles en facturas borrador por paréntesis en nombre (#3)
