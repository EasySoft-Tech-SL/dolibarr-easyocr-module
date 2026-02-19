# Registro de Cambios

Todos los cambios notables de EasyOcr se documentarán en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [2.3.2] - 2026-02-19

### Añadido
- **Campo `language` en creación de lotes batch**: Nuevo campo opcional en el sidebar de configuración para especificar el idioma principal del documento (código ISO, ej.: `es`, `en`, `fr`). Se envía a la API en el campo `language` de las opciones del batch.
- **Campo `custom_instructions` en creación de lotes batch**: Textarea opcional para incluir instrucciones libres a la IA al procesar el lote. Reutiliza las claves de traducción ya existentes del sistema de plantillas.
- **Selector de plantilla de proveedor en batch**: Si existen plantillas con instrucciones guardadas en BD, aparece un selector desplegable (proveedor — nombre de plantilla) que autorellena el textarea de instrucciones al cambiar la selección. Datos inyectados como JSON en la página para autocompletado sin petición AJAX adicional.
- **Feature flag `custom_instructions`**: Los campos de idioma e instrucciones se muestran deshabilitados con icono de aviso si el plan activo no incluye la característica `custom_instructions`.

### Corregido
- **Bug crítico: creación de proveedores duplicados en webhook** (`lib/easyocr.lib.php`): El campo `siren` de la societe se asignaba con `->siren` en lugar de `->idprof1`, lo que impedía que el NIF/CIF se guardara en BD y causaba que cada webhook creara un proveedor nuevo en vez de reutilizar el existente.
- **Advisory lock reposicionado** (`lib/easyocr.lib.php`): El `GET_LOCK()` por CIF se movída después de la búsqueda del proveedor; ahora se adquiere antes, eliminando la condición de carrera en procesamiento concurrente de facturas del mismo proveedor.
- **`import_key` truncada** (`lib/easyocr.lib.php`, `webhook_batch.php`): El valor `'easyocr-webhook'` (16 chars) superaba el límite `VARCHAR(14)` de la columna, silenciando la comprobación de duplicados. Cambiado a `'easyocr-wh'` (10 chars).
- **`$document` null en webhook** (`webhook_batch.php`): La API envía el payload en `data{}` y no en `document{}`. Añadido fallback: si `$payload['document']` está vacío se usa `$payload['data']`.
- **`;` faltante** (`batch.php`): Punto y coma ausente en `print '</td></tr>'` que habría causado un fatal error PHP en la carga de la página.

### Traducciones
- Añadidas claves `EasyOcrBatchLanguage`, `EasyOcrBatchLanguagePlaceholder`, `EasyOcrBatchLanguageHint`, `EasyOcrBatchTemplateSelect` y `EasyOcrBatchTemplateSelectHint` en los 8 idiomas (es, en, fr, de, ca, gl, pt, it).
- Corregido formato de líneas fusionadas en `en_US/easyocr.lang` e `it_IT/easyocr.lang`.

## [2.3.1] - 2026-02-16

### Añadido
- **Papelera de reciclaje en historial de lotes**: Nuevo sistema de papelera para gestionar lotes eliminados
  - Botón "Papelera" con badge contador de lotes cancelados en el área de filtros
  - Modo papelera: filtra automáticamente por estado `cancelled`, bloquea el selector de estado
  - Botón de eliminar (fa-trash-alt) en todas las filas de lotes no cancelados (incluyendo completados, parciales y fallidos)
  - Botón de eliminar en el panel de detalle del lote
  - Filas canceladas se muestran atenuadas con texto tachado en la vista normal
  - Actualización automática del badge de papelera tras cancelar/eliminar un lote
  - Traducciones en 8 idiomas (es, en, fr, ca, gl, de, it, pt): Papelera, confirmación, vacía, volver al historial
- **Librería PHP EasyOCR SDK** (`lib/easyocr/`): Cliente PHP completo con Guzzle HTTP para la API EasyOCR
  - `EasyOCRClient`: Cliente principal con patrón flyweight para recursos lazy-loaded
  - 9 Resources: `OcrResource`, `BatchResource`, `DocumentResource`, `AccountResource`, `UsageResource`, `KeyResource`, `PlansResource`, `WalletResource`, `BaseResource`
  - 5 Exceptions tipadas: `AuthenticationException`, `NotFoundException`, `RateLimitException`, `ValidationException`, `EasyOCRException`
  - Autoloader PSR-4 (`easyocr_autoload.php`) + Composer autoload para Guzzle 7.x
- **Página de procesamiento Batch** (`batch.php`): Nueva página completa con sistema de pestañas (Nuevo Lote / Historial)
  - Formulario de subida con drag & drop, vista previa de archivos, y opciones de configuración (texto extraído, autocorrección, webhook)
  - **Subida AJAX por archivo individual**: Los archivos se suben uno a uno al servidor (acción `batchUploadFile`) para evitar el límite PHP `max_file_uploads`, y después se crea el batch (acción `batchCreateFromUploads`)
  - Historial de lotes con filtros (estado, nombre, fecha), paginación configurable (10/20/50/100), y detalle expandible por documento
  - Visualización de resultados con 6 secciones: info documento, proveedor/cliente, líneas/items, totales con desglose de impuestos, pago, metadatos
  - Barra de progreso para lotes en procesamiento, badges de estado, cancelación de lotes
  - Selector de elementos por página (10, 20, 50, 100) con valor por defecto 20
- **Widget de suscripción** en `tool.php`: Indicador compacto desplegable con uso de cuota, plan activo, wallet y barra de progreso
- **Página de plan de servicio** (`admin/plan.php`): Nueva pestaña administrativa con detalles del plan contratado
- **Receptor de webhook** (`webhook_batch.php`): Endpoint en raíz del módulo para recibir notificaciones de la API al completar lotes
  - **Seguridad por instance_id**: URL incluye parámetro `instance_id={dolibarr_main_instance_unique_id}` para validar que el webhook es enviado al servidor correcto
  - **Procesamiento automático**: Al recibir evento `batch.document.completed`, crea automáticamente una factura de proveedor con los datos OCR extraídos
    - Busca o crea el proveedor basado en datos OCR (nombre, NIF/CIF, datos de contacto)
    - Crea factura de proveedor con estado validado
    - Agrega líneas de factura desde items OCR con impuestos correctos
    - Guarda URL de documentos (PDF) si están disponibles
    - Manejo automático de duplicados: verifica si la factura ya existe por ref_supplier
  - **Debug completo**: Guarda todos los datos de entrada (GET, POST, headers, raw body) en archivos JSON individuales en `documents/easyocr/webhook_debug/`
  - **Logs estructurados**: Registro diario en `documents/easyocr/webhook_logs/webhook_YYYY-MM-DD.log` con formato JSON línea por línea
  - **Función compartida** (`easyocrCreateInvoiceFromOCR()` en `lib/easyocr.lib.php`): Lógica unificada de creación de factura usada por AJAX (`newInvoiceAI`) y webhook, con helpers `convertFlexibleDate()`, `convertToNumber()`, `calculateIVA()`
- **Tabla SQL de webhook** (`sql/llx_easyocr_webhook_log.sql`): Registro completo de eventos webhook recibidos con campos de factura creada
  - Columnas: batch_id, event, document_id, document_filename, document_status, batch_status, batch_progress
  - Nuevas columnas para rastreo de facturas: invoice_id, invoice_ref, supplier_id, processing_status, processing_message, payload
  - Script de migración `llx_easyocr_webhook_log.alter.sql` para actualizar tabla existente
- **Configuración "Factura como borrador"**: Nueva opción `EASYOCR_INVOICE_DRAFT` en `admin/setup.php` para crear facturas en estado borrador
- **2 acciones AJAX** en `ajax_easyocr.php`:
  - `batchUploadFile`: Sube un archivo individual a directorio temporal con validación MIME y session_id
  - `batchCreateFromUploads`: Crea lote batch desde archivos previamente subidos, con limpieza automática de temporales
- **5 acciones AJAX batch** en `ajax_easyocr.php`: `batchList`, `batchStatus`, `batchResults`, `batchCancel`
- **200+ claves de traducción** en 8 idiomas (es, en, fr, de, it, pt, ca, gl) para batch, suscripción, plan, webhook y configuración
- **Configuración automática de localtax al crear proveedor**: Pre-análisis de líneas de factura AI para detectar recargo de equivalencia (RE) o IRPF y configurar `localtax1_assuj`/`localtax2_assuj`/`localtax2_value` en el tercero creado
- **Estados de suscripción completos**: Añadidos estados `past_due` (cobro fallido) y `paused` (pausada) + descripciones detalladas en 8 idiomas (14 nuevas claves de traducción)
- **Soporte múltiples proveedores con mismo CIF**: La acción AJAX `findSupplierByCIF` ahora busca todos los proveedores con el mismo tax ID y devuelve array con `found_count` y `suppliers[]` si hay más de uno
- **Botón "Crear factura" en detalle de documento batch**: Sistema automático de verificación de existencia de factura por `ref_supplier` con botón condicional:
  - Nueva acción AJAX `checkInvoiceExists` que consulta `llx_facture_fourn` por ref_supplier (opcional filtro por fk_soc)
  - Barra de acción superior en `eoBatchRenderDocDetail()` con indicadores visuales (✓ verde si existe / ℹ️ gris si no)
  - Botón "Crear factura" que abre modal AI pre-llenado con datos del documento batch
  - Enlace directo "Ver factura" si ya existe en Dolibarr (card.php?facid=X)
  - 4 nuevas claves i18n en 8 idiomas: CheckingInvoice, InvoiceExists, CreateInvoice, ViewInvoice
- **Submenú "Historial de lotes"**: Nuevo submenú bajo "Envío por lotes" que apunta directamente al historial (`batch.php?tab=history&frommenu=1`)
  - Traducción `EasyOcrBatchHistory` en 8 idiomas
  - Al acceder desde el menú, las pestañas superiores se ocultan automáticamente para vista simplificada
- **Traducción de estados batch**: Los badges de estado (Completed, Processing, Failed, etc.) ahora se muestran traducidos mediante claves i18n (`statusPending`, `statusProcessing`, etc.) en lugar del texto en inglés crudo
- **Icono de factura en fila de documento**: Se añade un icono `fa-file-invoice` directamente en la columna de acciones de cada documento completado del batch:
  - Verde con enlace: factura ya existe en Dolibarr (abre card.php)
  - Rojo con clic: factura no creada, permite crear directamente sin expandir el detalle
  - Verificación asíncrona automática al cargar la lista de documentos
- **Auto-refresh de suscripción**: El widget de cuota/suscripción en `tool.php` se actualiza automáticamente cada 5 segundos vía polling AJAX
  - Nueva acción `getSubscriptionInfo` en `ajax_easyocr.php` que devuelve datos de plan, cuota, wallet y estado
  - Actualización dinámica de todos los elementos del widget (barra de progreso, contadores, estado, wallet) sin recarga de página
- **Traducción "No creada"**: Añadida clave `EasyOcrBatchInvoiceNotCreated` en 8 idiomas para reemplazar texto hardcoded en español

### Mejorado
- **Rutas con `dol_buildpath()`**: Sustituidas todas las rutas `DOL_URL_ROOT . '/custom/easyocr/...'` por `dol_buildpath('/easyocr/...', 1)` en menús del módulo, JS (pdf.worker, scripts.js.php) y CSS
- **CSS del módulo** (`easyocr.css`): +800 líneas nuevas para batch (dropzone, file list, quota cards, progress bar, detail overlay 80%/1100px, party cards, section styles, responsive)
- **Pestaña "Plan" en administración**: Añadida en `lib/easyocr.lib.php` con icono estrella dorada
- **Selector visual de múltiples proveedores en modal AI**: Campo CIF/Tax ID ahora incluye indicadores de estado con códigos de color:
  - ✓ Verde (`fa-check-circle`): 1 proveedor encontrado, auto-selección
  - ⚠️ Naranja (`fa-exclamation-triangle`): Múltiples proveedores, despliega dropdown selector con fondo ámbar
  - ✗ Rojo (`fa-times-circle`): CIF no encontrado
  - Estado almacenado en `state.selectedSupplierID` con prioridad sobre `$('#eo-supplier').val()` en `createAIInvoice()`
- **Webhook movido a raíz del módulo**: Reubicado de `ajax/webhook_batch.php` → `webhook_batch.php` (raíz) para simplificar la arquitectura y facilitar el acceso externo. El archivo antiguo ha sido eliminado.

### Corregido
- **Error `max_file_uploads` en batch**: Reescrito el envío de archivos de POST multipart tradicional a subida AJAX secuencial archivo por archivo, evitando el límite PHP que causaba `Maximum number of allowable file uploads has been exceeded`

## [2.3.0] - 2026-02-10

### Añadido
- **Cumplimiento Reglamento IA (UE) 2024/1689**: Nueva sección en `telemetry.php` con información sobre el Reglamento Europeo de Inteligencia Artificial, artículo 50 (transparencia), nivel de riesgo, cumplimiento anticipado
- **Aviso de transparencia sobre uso de IA**: Cuadro destacado informando que el módulo puede usar IA para facturación, uso voluntario y resultados revisables
- **Obligaciones del usuario como operador de IA**: Sección con 4 obligaciones (uso conforme, intervención humana, informar afectados, validar datos)
- **Base legal ampliada**: Nueva referencia al Reglamento (UE) 2024/1689 en la sección de base legal
- **20+ claves de traducción** en 8 idiomas (es, en, fr, de, it, pt, ca, gl) para las nuevas secciones de telemetría
- **Constantes por defecto al activar módulo**: `EASYOCR_AI_ENABLED=1` y `EASYOCR_AI_URL=https://app.easyocr.es` se configuran automáticamente en `$this->const`

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
