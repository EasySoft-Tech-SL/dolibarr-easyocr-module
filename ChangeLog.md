# Registro de Cambios

Todos los cambios notables de EasyOcr se documentarán en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [2.0.0] - 2026-02-07

### Cambio Mayor
- **Renombrado completo de MasterPdf a EasyOcr**
  - Branding EasySoft Tech S.L.
  - Prefijos CSS actualizado: `mp-*` → `eo-*`
  - Namespace JavaScript: `MasterPdf` → `EasyOcr`
  - Class Rights: `masterpdf` → `easyocr`
  - Número de módulo: 402020
  - Todos los archivos .lang actualizados

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
- Versión inicial de MasterPdf (posterior renombrado a EasyOcr)
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
