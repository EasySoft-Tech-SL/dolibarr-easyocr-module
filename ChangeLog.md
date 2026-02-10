# Registro de Cambios

Todos los cambios notables de EasyOcr se documentarán en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [2.2.0] - 2026-02-10

### Añadido
- **Proveedor editable en plantillas**: El campo proveedor en `templates_view.php` ahora se puede cambiar desde el modo edición mediante un desplegable filtrado a proveedores (`select_company`)
- **Instrucciones personalizadas en plantillas**: Campo `custom_instructions` editable en `templates_view.php` (visible solo cuando IA está habilitada)
- **8 nuevas claves de traducción** en 8 idiomas (es, en, fr, de, it, pt, ca, gl): `EasyOcrNumFields`, `EasyOcrScale`, `EasyOcrTemplateFields`, `EasyOcrFieldLabel`, `EasyOcrWidth`, `EasyOcrHeight`, `EasyOcrOrigin`, `EasyOcrRemoveMark`

### Mejorado
- **Rediseño de `templates.php`**: Reescrito siguiendo el patrón estándar de listados Dolibarr (`print_barre_liste`, `print_liste_field_titre`, columnas ordenables, filtros en cabecera, acciones masivas con `selectMassAction`, paginación nativa)
- **Rediseño de `invoices.php`**: Reescrito con el mismo patrón estándar Dolibarr; incluye fila de totales HT/TTC, badges de origen (OCR/IA OCR), enlaces a factura y tercero
- **Rediseño de `templates_view.php`**: Reescrito como ficha Dolibarr (`load_fiche_titre`, `BackToList`, `formconfirm`, modo vista/edición separados, tabla de detalle de campos de plantilla)

### Corregido
- **Error regex `preg_replace()`**: Patrón inválido `'/^SELECT[^]*FROM/Ui'` con clase de carácter `[^]` vacía cambiado a `'/^SELECT[\s\S]*FROM/Ui'` en `templates.php` e `invoices.php`
- **Duplicación de proveedores en facturas AI**: `$newSoc->siren = $cif;` no funcionaba porque `Societe::create()` lee de `$this->idprof1`, no del alias legacy `$this->siren`. Corregido a `$newSoc->idprof1 = $cif;` en `ajax_easyocr.php` para que el CIF se guarde correctamente en la columna `siren` de la base de datos y la búsqueda posterior encuentre al proveedor existente

## [2.1.1] - 2026-02-09

### Corregido
- **IVA 0% en líneas de factura AI**: Las líneas con array `taxes: []` vacío de la API ahora heredan el tipo impositivo del documento (ej. 21%) en lugar de quedar a 0%
  - Frontend: extracción de `defaultTaxRate` desde `totals.taxes` del documento y fallback en `createLineRow()`
  - Backend: nuevo parámetro `default_tax_rate` y fallback final en el bucle de líneas
- **Líneas de descuento no insertadas**: Corregida la inserción de líneas tipo descuento que no se guardaban en la factura
- **Checkbox "Seleccionar Todo" en invoices.php**: Eliminado `})();` duplicado en `scripts.js` que impedía el funcionamiento del selector masivo
- **Facturas AI no visibles en invoices.php**: Añadido filtro `import_key = 'easyocr-ai'` además de `'easyocr'` para mostrar facturas creadas por el modal AI
- **Pérdida de país del tercero al upgradear**: Al convertir un cliente existente a proveedor, se usaba `$existingSoc->update()` que sobrescribía campos como el país. Ahora se usa SQL directo actualizando solo `fournisseur` y `code_fournisseur`

### Añadido
- **Botón "Show Payload"** en el modal AI para visualizar la respuesta JSON completa de la API
- **Parámetro `include_text: false`** en las llamadas a la API de OCR para optimizar el payload
- **Fallback de tipo impositivo en 4 capas**: (1) array taxes, (2) campos planos, (3) cálculo desde total/net_amount, (4) tasa por defecto del documento

### Mejorado
- **Botón "Abrir" en preview de factura**: Rediseño visual del botón de apertura de factura en el modal de previsualización
- **Preservación de datos del tercero**: Las operaciones de upgrade de cliente a proveedor ahora preservan todos los campos existentes (país, dirección, etc.) usando SQL directo en lugar de `update()` completo

## [2.1.0] - 2025-01-19

### Añadido
- **Pestaña de Acuerdo de Licencia**: Nueva sección administrativa que muestra información sobre la licencia GPL v3 y el uso de servicios de IA de terceros
- **Pestaña de Telemetría y Protección de Datos**: Sección completa de transparencia sobre el procesamiento de datos mediante servicios de IA
  - Descripción detallada de qué datos se envían al servicio de IA (contenido PDF, idioma, dominio)
  - Listado explícito de qué datos NUNCA se transmiten (datos del ERP, clientes, facturas, contraseñas, información personal)
  - Medidas de seguridad implementadas (HTTPS, servidores EU, control de acceso, cumplimiento GDPR)
  - Base legal y derechos del usuario (acceso, rectificación, supresión, portabilidad)
- **Advertencia de Consentimiento GDPR**: Mensaje informativo durante la activación del módulo sobre el uso de servicios de IA y procesamiento de datos por terceros
- **Traducciones Multiidioma para Contenido Legal**: Más de 30 nuevas claves de traducción en 8 idiomas (español, inglés, francés, alemán, italiano, portugués, catalán, gallego) cubriendo todo el contenido legal y de privacidad
- **Iconos en Todas las Pestañas Administrativas**: Añadidos iconos Font Awesome a las pestañas "Acerca de" (info-circle azul) y "Historial de Cambios" (list-ul verde) para mantener consistencia visual

### Mejorado
- **Rediseño Visual de Sección IA Inactiva**: Nueva presentación con enfoque de marketing cuando el servicio de IA está deshabilitado
  - Gradiente púrpura moderno y profesional (#f3f0ff → #ebe5ff)
  - Icono de estrella brillante con animación de pulso (2.5s infinite)
  - Marca "easyOCR AI" con enlace a portal web (https://easyocr.easysoft.es/)
  - Tres puntos destacados de beneficios con viñetas de verificación:
    - Extracción automática de proveedor, CIF, fechas y totales
    - Detección inteligente de todas las líneas de factura
    - Creación automática de facturas y proveedores en Dolibarr
  - Subtítulo llamativo: "Potencia tu extracción de datos"
  - Pista informativa sobre activación desde configuración (sin enlace directo para evitar distracciones)
- **Identidad de Marca**: Integración del nombre "easyOCR AI" (hardcoded, no traducido) y enlace al sitio web del producto en la interfaz principal
- **Mensajería Positiva**: Eliminación de etiquetas negativas ("INACTIVO"), reemplazadas por comunicación orientada a beneficios y valor

### Cumplimiento y Legal
- **GDPR**: Implementación completa de requisitos de transparencia y consentimiento para procesamiento de datos por terceros mediante servicios de IA
- **Transparencia de Datos**: Divulgación exhaustiva de todas las prácticas de procesamiento de datos relacionadas con servicios de IA
  - Qué se envía: contenido PDF, idioma de procesamiento, dominio del ERP
  - Qué NUNCA se envía: datos del ERP, información de clientes, facturas internas, datos bancarios, contraseñas, información personal
- **Derechos del Usuario**: Documentación clara de los derechos GDPR del usuario (acceso, rectificación, supresión, portabilidad) y cómo ejercerlos
- **Licenciamiento**: Presentación formal de la licencia GPL v3 con información de contacto y autoría (EasySoft Tech S.L.)

### Técnico
- **5 Pestañas Administrativas**: Reorganización de la configuración administrativa:
  1. Configuración / Setup
  2. Acuerdo de Licencia / License Agreement (icono file-contract gris)
  3. Telemetría y Protección de Datos / Telemetry & Data Protection (icono shield-alt azul)
  4. Acerca de / About (icono info-circle azul)
  5. Historial de Cambios / ChangeLog (icono list-ul verde)
- **Archivos de Soporte Legal**: Nuevos archivos `admin/copying.php` (154 líneas) y `admin/telemetry.php` (266 líneas)
- **Función de Navegación de Pestañas**: `easyocr_admin_prepare_head()` actualizada en `lib/easyocr.lib.php` con todos los iconos Font Awesome
- **Estilos CSS Nuevos**: Añadidas clases para estado inactivo de IA en `css/easyocr.css` (líneas 1140-1195):
  - `.eo-ai-disabled`: Contenedor con gradiente púrpura
  - `.eo-ai-icon-promo`: Icono con gradiente y animación de pulso
  - `.eo-ai-cta-subtitle`: Subtítulo con color púrpura
  - `.eo-ai-features`: Lista de beneficios con viñetas de verificación
  - `.eo-ai-activate-hint`: Pista de activación con separador superior
  - `.eo-ai-link`: Enlaces en negro con efecto hover púrpura
- **Claves de Traducción Añadidas** (por idioma):
  - 3 claves para características de IA: `EasyOcrAIFeat1`, `EasyOcrAIFeat2`, `EasyOcrAIFeat3`
  - 2 claves para marketing: `EasyOcrAICtaHeadline`, `EasyOcrAIActivateHint`
  - 11 claves para licencia: `EasyOcrCopying*`
  - 30+ claves para telemetría: `EasyOcrTelemetry*`
  - 1 clave para advertencia GDPR: `EasyOcrGDPRInformation`

### Seguridad
- Comunicación cifrada (HTTPS) con servicios de IA
- Servidores ubicados en la Unión Europea
- Control de acceso mediante API Key
- Cumplimiento GDPR completo
- No se comparten ni venden datos a terceros
- No se almacenan documentos procesados

## [2.0.0] - 2026-02-07

### Cambio Mayor

### Añadido
- Soporte multi-idioma (8 idiomas):
  - Español (es_ES)
  - Inglés (en_US)
  - Francés (fr_FR)
  - Alemán (de_DE)
  - Italiano (it_IT)
  - Portugués (pt_PT)
  - Catalán (ca_ES)
  - Gallego (gl_ES)
- Nuevo módulo descriptor `modEasyocr.class.php` con mejor documentación
- Documentación técnica interna (`claude.md`)
- Sistema de permisos completo integrado
- Notas sobre limitaciones y casos de uso

### Mejorado
- Interfaz de usuario más intuitiva
- Mejor gestión de errores en AJAX
- Validación mejorada de plantillas
- Compatibilidad con Dolibarr 16 confirmada
- Estilos CSS refactorizados con nomenclatura consistente

### Corregido
- Problemas de compatibilidad con PHP 7.4+
- Gestión correcta de rutas en documentos
- Selección de archivos en navegadores modernos

### Documentado
- Archivo README.md completo en español, inglés y francés
- Instrucciones detalladas de instalación y uso
- Ejemplos de casos de uso
- Solución de problemas

### Técnico
- Cumplimiento de estilo de código PSR-12
- Documentación PHPDoc completa
- Archivos SQL optimizados
- JavaScript modularizado con IIFE

## [1.0.0] - 2025-06-15

### Añadido
- Visor PDF interactivo de dos paneles
- Extracción de texto nativo con PDF.js
- Selección visual de datos mediante rectángulos
- Guardado de plantillas por proveedor
- Generación automática de facturas de proveedor
- Gestión de historial de facturas procesadas
- Interfaz de administración básica
- Sistema de base de datos con 3 tablas principales
- Notificaciones en tiempo real

### Detalles Técnicos
- ID del Módulo: 402020
- Versión mínima de Dolibarr: 16.0
- Versión mínima de PHP: 7.4
- Tablas de base de datos: 
  - llx_easyocr_invoices
  - llx_easyocr_templates
  - llx_easyocr_template_details
- Cumplimiento de estilo de código PSR-12

---

*Desarrollado por [EasySoft Tech S.L.](https://easysoft.es)*
