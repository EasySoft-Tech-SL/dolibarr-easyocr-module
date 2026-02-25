# EasyOcr - Módulo Dolibarr v16

## Información del módulo
- **Nombre:** EasyOcr
- **Versión:** 2.3.1
- **Número módulo:** 402020
- **Empresa:** EasySoft Tech S.L. (info@easysoft.es)
- **Autor:** Alberto Luque Rivas (aluquerivasdev@gmail.com)
- **Carpeta:** `/custom/easyocr/` *(pendiente renombrar carpeta física de `masterpdf` → `easyocr`)*

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

### v2.0.0 — Renombrado completo a EasyOcr
- Renombrado de `masterpdf` → `easyocr` en todo el código
- Branding EasySoft Tech S.L.
- Prefijos CSS/DOM: `mp-` → `eo-`
- Namespace JS: `MasterPdf` → `EasyOcr`
- Tablas SQL: `master_pdf_*` → `easyocr_*`
- AJAX: `ajax_masterPdf.php` → `ajax_easyocr.php`
- Módulo: `modMasterPdf` → `modEasyocr`, numero=402020
- Eliminados todos los archivos antiguos

### v1.x — Desarrollo bajo nombre MasterPdf
- Corrección bug PDF.js worker (deprecation warning)
- Corrección canvas drawImage 0-dimension
- Validación mínimo 5px en selecciones click-sin-drag
- Handles de redimensionamiento en esquinas
- Reescritura completa UX: layout 2 paneles (visor + sidebar)
- Corrección extracción de precios (coordenadas PDF.js)
- Eliminación de Tesseract.js — extracción 100% PDF.js nativo con caché de texto
- Soporte fk_soc en plantillas (proveedor asociado)
- Migración SQL a carpeta `/sql/` (estándar Dolibarr)

---

## Notas de despliegue

1. **Renombrar carpeta:** `custom/masterpdf/` → `custom/easyocr/`
2. **Desactivar módulo viejo** en Dolibarr si estaba activo (`modMasterPdf`)
3. **Activar módulo nuevo** `modEasyocr` desde Inicio > Configuración > Módulos
4. **Renombrar tablas SQL** si ya existen datos:
   ```sql
   RENAME TABLE llx_master_pdf_invoices TO llx_easyocr_invoices;
   RENAME TABLE llx_master_pdf_template TO llx_easyocr_template;
   RENAME TABLE llx_master_pdf_template_details TO llx_easyocr_template_details;
   ```
5. Las tablas nuevas incluyen la columna `fk_soc` en `llx_easyocr_template`
