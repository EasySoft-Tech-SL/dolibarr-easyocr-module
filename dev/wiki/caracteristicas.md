<!--
BORRADOR v2.8.0 — texto final propuesto (no un diff) para
DOLIBARR > MODULOS > EASYOCR > CARACTERISTICAS.
Qué cambia respecto a lo publicado: ver _notas-borrador.md.
Marcadores: <!-- NUEVO v2.8.0 --> y <!-- CAMBIO v2.8.0 --> señalan cada bloque.
-->

**EasyOcr** es el módulo de Dolibarr que elimina la entrada manual de datos en las facturas de proveedor. Sube el PDF, deja que se extraigan proveedor, NIF, líneas y totales, y Dolibarr crea la factura validada de forma automática.

Está disponible en **dos modos**: el **Modo Clásico**, completamente gratuito e incluido en el módulo, que utiliza la tecnología nativa de PDF.js para extraer texto sin OCR; y el **Modo IA**, que ofrece un plan gratuito de 10 páginas/mes para probarlo sin coste y planes de pago para mayor volumen. Puedes consultar todos los planes en [easyocr.es](https://easyocr.es).

## Características

### Modo Clásico (siempre gratuito, sin servicios externos)

- **Visor PDF interactivo a 2 paneles**: el PDF a la izquierda, los datos a la derecha.
- **Selección visual con rectángulos**: dibuja zonas sobre el PDF para capturar datos clave (fecha, número de factura, totales, IVA, descripción, CIF/NIF, vencimiento y proveedor).
- **Proveedor por el nombre**: al marcar el nombre del proveedor en el PDF, el módulo lo busca entre tus terceros y lo deja seleccionado. Si varios encajan con ese nombre, avisa en lugar de elegir por su cuenta.
- **Plantillas reutilizables por proveedor**: guarda las zonas de selección y aplícalas automáticamente a facturas del mismo proveedor.
- **Extracción nativa con PDF.js**: 100 % local, sin enviar datos a ningún servidor externo.
- **Generación de facturas de proveedor**: crea la factura en Dolibarr con los datos recogidos.
- **Factura o presupuesto de proveedor**: la misma extracción puede registrarse como factura de proveedor o como presupuesto de proveedor, en borrador o validado. El número y la validez del documento del proveedor quedan guardados en el presupuesto, que además no se paga.
- **Historial de procesamiento**: registro completo de todas las facturas procesadas con el módulo.

### Modo IA (impulsado por easyocr.es)

- **Procesamiento en lotes**: sube hasta 200 facturas PDF de una sola vez y procésalas con un clic. Ideal para cierres de mes o altos volúmenes de proveedores.
- **OCR por Inteligencia Artificial avanzada**: extrae automáticamente proveedor, NIF/CIF, dirección, fecha, número de factura, líneas de detalle, impuestos y totales con una precisión muy superior al OCR clásico.
- **Automatización total vía webhook**: una vez procesado el PDF, Dolibarr recibe los datos automáticamente y crea la factura de proveedor validada. Cero intervención humana, cero errores de transcripción.
- **Alta automática de proveedores**: si el proveedor no existe en Dolibarr, el módulo lo crea con todos los datos fiscales extraídos del PDF.
- **Instrucciones IA personalizadas por proveedor**: define reglas específicas para cada proveedor o plantilla (por ejemplo, "el IVA siempre es 21 %", "ignorar líneas de descuento", "la moneda es USD"). La IA las aplica en cada procesamiento.
- **Auto-corrección inteligente**: el motor IA detecta y corrige automáticamente importes mal reconocidos, formatos de fecha inconsistentes y referencias duplicadas.
- **Presupuestos de proveedor con IA**: el documento revisado puede guardarse como factura o como presupuesto, con el mismo control de duplicados que las facturas.
- **Escaneo de gastos desde el móvil (PWA)**: vista instalable para que el empleado fotografíe un ticket y se registre como nota de gastos o factura de compra, con asociación a proyecto.
- **Historial de lotes detallado**: consulta el estado de cada lote — documentos procesados, tasa de éxito, errores y detalle por factura individual.
- **Gestión de suscripción integrada en Dolibarr**: consulta tu plan, cuota restante, historial de consumo y wallet directamente desde el módulo, sin salir de Dolibarr.
- **Privacidad y seguridad**: el tráfico va cifrado con TLS, y los proveedores que intervienen en el procesamiento están publicados en easyocr.es/subencargados, con su país y su garantía. Los documentos y los datos extraídos se conservan durante el plazo de tu plan y después se eliminan solos; puedes borrarlos antes desde el panel o por API.

<!-- NUEVO v2.8.0: apartado completo -->
### Documentos recibidos de otros módulos

Otros módulos pueden entregar sus PDFs a EasyOcr en lugar de tener que subirlos a mano. El caso de hoy es **EasyScan**, que digitaliza papel y deja cada documento en esta bandeja.

- **Bandeja local**: al recibir un documento, EasyOcr solo lo copia a su carpeta local y lo anota. No se envía al servicio de IA en ese momento: se procesa cuando tú quieras, en el visor o en un lote, con el coste normal y solo al pulsar el botón.
- **Ficha de cada documento**: muestra el origen, quién y cuándo lo entregó y, si venía de una factura de proveedor, cuál. Si ese mismo documento ya pasó antes por el servicio, lo avisa antes de que vuelvas a pagar por él.
- **Sin duplicados**: mientras un documento siga pendiente, el mismo PDF no se encola dos veces.
- **Entrada para desarrolladores**: la función pública `easyocr_recibir_documento()` permite integrar cualquier módulo con la bandeja; hoy la usa EasyScan.
<!-- FIN NUEVO v2.8.0 -->

### Qué incluye el módulo

- Página principal con visor PDF interactivo a 2 paneles.
- Gestión completa de plantillas de selección por proveedor.
- Página de procesamiento en lotes (Modo IA).
- **Página de Documentos recibidos, con un contador de pendientes en el inicio del módulo.** <!-- NUEVO v2.8.0 -->
- Historial de facturas procesadas con filtros y búsqueda.
- Panel de administración con configuración de API y suscripción.
- Pestaña de Telemetría y Protección de Datos (transparencia RGPD).
- Pestaña **Acuerdo de Licencia**, con la información legal del módulo. <!-- CAMBIO v2.8.0: el nombre real de la pestaña se mantiene para que se pueda localizar en la pantalla. -->
- Soporte multiempresa (multisociété): cada entidad ve solo sus documentos y sus plantillas.
- Traducciones completas en 8 idiomas: español, inglés, francés, alemán, italiano, portugués, catalán y gallego.
- Motor AJAX: operaciones sin recarga de página.

## Casos de uso

- **Empresas con múltiples proveedores**: una plantilla por proveedor, procesamiento en minutos.
- **E-commerce y distribución**: procesa facturas de decenas de distribuidores en lote.
- **Logística y transporte**: extrae datos de albaranes, facturas de compra y gastos de envío.
- **Despachos y consultoría**: gestiona facturas de subcontratistas y autónomos.
- **Construcción y obra**: facturas de materiales, proveedores de maquinaria y servicios.

## Compatibilidad y requisitos

- **Dolibarr**: V16+ (compatible hasta V24+).
- **PHP**: 7.4 – 8.3+.
- **Base de datos**: MySQL 5.7+ / MariaDB 10.2+ / PostgreSQL 10+.
- **Navegador**: navegador moderno con soporte ES6 y JavaScript (requerido por PDF.js).
- **Modo IA**: suscripción activa en [easyocr.es](https://easyocr.es) — consulta los planes disponibles.

## Precio

<!-- CAMBIO v2.8.0: el apartado se titula ahora «Precio»; se quita la mención legal. -->
El **Modo Clásico** no tiene ningún coste. El **Modo IA** incluye un plan gratuito de 10 páginas/mes para empezar sin coste, sin necesidad de tarjeta de crédito; para mayor volumen hay planes de pago desde uso puntual hasta procesamiento empresarial masivo. Consulta todos los planes y precios en [easyocr.es](https://easyocr.es).

El acceso a actualizaciones es de **365 días**.

## Soporte

- Email: [info@easysoft.es](mailto:info@easysoft.es)
- Web: [www.easysoft.es](https://www.easysoft.es)
- Modo IA: [easyocr.es](https://easyocr.es)
