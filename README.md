# EasyOcr - Extracción de Texto y Generación de Facturas desde PDFs para Dolibarr

![EasyOcr Logo](img/easyocr.png)

**EasyOcr** es un potente módulo para Dolibarr ERP que facilita la extracción de datos de facturas PDF y la generación automática de facturas de proveedor. Utiliza la tecnología nativa de PDF.js para extraer texto sin necesidad de OCR, permitiendo una integración fluida con tu sistema Dolibarr.

## Características

- **Visor PDF Interactivo**: Interfaz intuitiva con panel de dos columnas (PDF + datos)
- **Extracción de Texto Nativo**: Utiliza PDF.js para extraer texto sin OCR
- **Selección Visual de Datos**: Dibuja rectángulos en el PDF para seleccionar campos clave:
  - Fecha de factura
  - Número de factura
  - Totales HT (sin impuestos)
  - Precio total
- **Plantillas Reutilizables**: Guarda plantillas de selección por proveedor
- **Generación Automática**: Crea facturas de proveedor automáticamente en Dolibarr
- **Gestión de Historial**: Visualiza todas las facturas procesadas
- **Integración Completa**: Se integra perfectamente con el sistema de terceros y facturas de Dolibarr
- **Multi-idioma**: Soporte para 8 idiomas (español, inglés, francés, alemán, italiano, portugués, catalán, gallego)
- **Interfaz Moderna**: Diseño responsive y amigable con el usuario

## Requisitos

- **Dolibarr ERP**: Versión 16.0 o superior
- **PHP**: Versión 7.4 o superior
- **Base de Datos**: MySQL 5.7+ / MariaDB 10.2+ o PostgreSQL 10+
- **Navegador**: Debe soportar JavaScript (PDF.js requiere ES6)

## Compatibilidad

EasyOcr es compatible con:
- Dolibarr 16.0+
- PHP 7.4 a 8.3+
- MySQL 5.7+, MariaDB 10.2+, PostgreSQL 10+

## Instalación

### Instalación Manual

1. Descarga el módulo desde la página de releases
2. Extrae el archivo en el directorio `htdocs/custom/` de tu Dolibarr
3. El módulo debe quedar en: `htdocs/custom/easyocr/`
4. Inicia sesión en Dolibarr como administrador
5. Ve a **Inicio → Configuración → Módulos/Aplicaciones**
6. Busca "EasyOcr" y haz clic en **Activar**

### Desde Dolistore

1. Inicia sesión en tu instancia de Dolibarr como administrador
2. Ve a **Inicio → Configuración → Módulos/Aplicaciones → Desplegar/Instalar módulos externos**
3. Busca "EasyOcr" e instala

## Uso

### Procesar una Factura PDF

1. Accede a **Herramientas → EasyOcr → Procesar Facturas**
2. **Carga un PDF**: 
   - Haz clic en el botón de carga
   - Selecciona una factura en PDF
   - El PDF se mostrará en el panel izquierdo
3. **Selecciona los Datos**:
   - Dibuja rectángulos en el PDF para marcar:
     - **Fecha**: Rectángulo verde
     - **Número de Factura**: Rectángulo azul
     - **Totales HT**: Rectángulo amarillo
     - **Precio Total**: Rectángulo rojo
   - Añade etiquetas a cada rectángulo
4. **Guarda la Plantilla**:
   - Selecciona un proveedor
   - Nombra la plantilla (ej: "Factura Estándar Proveedor X")
   - Haz clic en **Guardar Plantilla**
5. **Genera la Factura**:
   - El módulo extrae automáticamente el texto
   - Crea una factura de proveedor con los datos extraídos
   - Verifica los datos antes de confirmar

### Reutilizar Plantillas

1. Ve a **Herramientas → EasyOcr → Plantillas**
2. Selecciona una plantilla guardada
3. Carga un nuevo PDF del mismo proveedor
4. La plantilla aplicará automáticamente las selecciones
5. Los datos se extraerán directamente

### Gestionar Facturas Procesadas

1. Ve a **Herramientas → EasyOcr → Facturas Procesadas**
2. Visualiza el historial de todas las facturas extraídas
3. Accede a las facturas creadas en Dolibarr
4. Revisa el estado de procesamiento

## Estructura de Datos

### Tablas de Base de Datos

| Tabla | Descripción |
|-------|-------------|
| `llx_easyocr_invoices` | Registro de facturas procesadas y sus referencias a PDFs (FK a `llx_ecm_files`) |
| `llx_easyocr_template` | Plantillas de selección guardadas por proveedor |
| `llx_easyocr_template_details` | Detalles de rectángulos, posiciones y etiquetas por plantilla |

### Estructura de Carpetas

```
easyocr/
├── admin/
│   └── setup.php                          # Página "Acerca de" del módulo
├── ajax/
│   └── ajax_easyocr.php                   # Handler AJAX para operaciones
├── core/
│   └── modules/
│       └── modEasyocr.class.php           # Descriptor del módulo Dolibarr
├── css/
│   ├── easyocr.css                        # Estilos principales
│   ├── panel.css                          # Estilos de paneles de listado
│   ├── styles.css                         # Estilos base
│   └── upload.css                         # Estilos de carga
├── img/
│   ├── invoice.png                        # Icono de facturas
│   ├── templates.png                      # Icono de plantillas
│   └── easyocr.png                        # Logo del módulo
├── js/
│   ├── pdf.min.js                         # PDF.js (v2.10.377)
│   ├── pdf.worker.min.js                  # Worker de PDF.js
│   ├── scripts.js                         # Motor principal del visor
│   └── panel.js                           # JavaScript para paneles
├── libraries/
│   └── notify.min.js                      # Librería de notificaciones
├── sql/
│   ├── llx_easyocr_invoices.sql
│   ├── llx_easyocr_invoices.key.sql
│   ├── llx_easyocr_template.sql
│   ├── llx_easyocr_template_details.sql
│   └── llx_easyocr_template_details.key.sql
├── tool.php                               # Página principal: visor PDF
├── invoices.php                           # Listado de facturas procesadas
├── templates.php                          # Listado de plantillas
├── templates_view.php                     # Editor de plantillas
├── README.md                              # Este archivo
├── ChangeLog.md                           # Historial de cambios
├── LICENSE                                # Licencia GPL v3
└── claude.md                              # Documentación técnica interna
```

## Permisos

| Permiso | Descripción |
|---------|-------------|
| Lectura | Ver facturas procesadas y plantillas |
| Crear/Modificar | Procesar nuevas facturas y crear plantillas |
| Eliminar | Eliminar plantillas e historial |
| Configurar | Acceder a la configuración del módulo |

## Dependencias Externas

- **PDF.js 2.10.377**: Renderizado y extracción de texto nativo desde PDFs
- **jQuery**: AJAX (incluido por Dolibarr)
- **notify.min.js**: Notificaciones en tiempo real

## Notas Importantes

### Limitaciones

- **Requiere JavaScript**: PDF.js requiere un navegador moderno con soporte ES6
- **PDFs Escaneados**: Funciona mejor con PDFs nativos (generados digitalmente). PDFs escaneados o imágenes requieren OCR externo
- **Extracción de Texto**: La precisión depende de la calidad del PDF y su estructura

### Seguridad

- Las plantillas se asocian a proveedores específicos
- La extracción de datos requiere validación manual antes de crear facturas
- Los PDFs se procesan localmente sin envío a servidores externos

## Solución de Problemas

### "El PDF no se carga"
- Verifica que el archivo sea un PDF válido
- Comprueba los permisos de acceso
- Revisa la consola del navegador (F12) para errores

### "No se extrae el texto"
- El PDF podría ser una imagen o estar escaneado
- Intenta con otro PDF del mismo proveedor
- Comprueba que el PDF contenga una capa de texto

### "El rectángulo no se posiciona correctamente"
- Practica dibujando rectángulos cuidadosamente
- Ajusta el zoom del PDF para mayor precisión
- Las plantillas permiten reajustar las selecciones

## Soporte

Para soporte, preguntas o sugerencias:

- **Email**: info@easysoft.es
- **Web**: https://easysoft.es
- **Incidencias**: Reporta errores por email

## Licencia

Este módulo está licenciado bajo la **GNU General Public License v3.0 o posterior** (GPL-3.0+).

Consulta el archivo [LICENSE](LICENSE) para más detalles.

## Créditos

**Desarrollado por**: [EasySoft Tech S.L.](https://easysoft.es)

**Autor**: Alberto Luque Rivas

---

*EasyOcr no está afiliado al proyecto Dolibarr ERP. Dolibarr es una marca registrada de la Dolibarr Foundation.*
