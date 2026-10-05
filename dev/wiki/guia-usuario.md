<!--
BORRADOR v2.8.0 — texto final propuesto (no un diff) para
DOLIBARR > MODULOS > EASYOCR > GUIA USUARIO.
Qué cambia respecto a lo publicado: ver _notas-borrador.md.
Marcadores: <!-- NUEVO v2.8.0 --> y <!-- CAMBIO v2.8.0 --> señalan cada bloque.
-->

# Guía de usuario de EasyOcr

EasyOcr lee las facturas de proveedor en PDF y las registra en Dolibarr sin teclear los datos. Funciona en dos modos:

- **Modo Clásico** (incluido, sin coste): extrae el texto del propio PDF con PDF.js. Tú marcas con rectángulos dónde está cada dato.
- **Modo IA**: el documento se envía al servicio de [easyocr.es](https://easyocr.es), que devuelve proveedor, líneas, impuestos y totales estructurados. Tiene plan gratuito de 10 páginas al mes.

## Instalación

### Instalación manual

1. Descarga el módulo desde la página de releases.
2. Extrae el archivo en el directorio `htdocs/custom/` de tu Dolibarr.
3. El módulo debe quedar en: `htdocs/custom/easyocr/`.
4. Inicia sesión en Dolibarr como administrador.
5. Ve a **Inicio → Configuración → Módulos/Aplicaciones**.
6. Busca "EasyOcr" y haz clic en **Activar**.

### Desde Dolistore

1. Inicia sesión en tu instancia de Dolibarr como administrador.
2. Ve a **Inicio → Configuración → Módulos/Aplicaciones → Desplegar/Instalar módulos externos**.
3. Busca "EasyOcr" e instala.

### Actualización

1. Sustituye la carpeta `htdocs/custom/easyocr/` por la de la nueva versión.
2. Entra en el módulo: los cambios de base de datos se aplican **solos** en el primer acceso.

En versiones anteriores a la 2.7.3 había que **desactivar y volver a activar** el módulo después de actualizar. Dolibarr solo ejecuta los ficheros de `sql/` al activarlo, así que una actualización que solo cambiaba ficheros dejaba el esquema viejo y las funciones nuevas fallaban en silencio. En Dolibarr 14 y 15 conviene la 2.7.4 o posterior: con la 2.7.3 el cambio no llegaba a aplicarse solo.

## Activación

Tras instalar, activa el módulo desde **Inicio → Configuración → Módulos/Aplicaciones**, buscando "EasyOcr" y haciendo clic en **Activar**. Después, entra en la configuración del módulo y da los permisos de EasyOcr a los usuarios que vayan a usarlo (el administrador no hereda los permisos del módulo).

## Configuración

En la pestaña de configuración del módulo:

- **Modo IA**: clave API y URL del servicio. La clave se obtiene en [easyocr.es](https://easyocr.es). Sin clave, el módulo funciona en Modo Clásico.
- **Crear facturas como borrador**: si está activado, las facturas se crean sin validar para que las revises antes.
- **Ventana de detección de duplicados**: durante cuántos días se avisa de que un documento ya se procesó.
- **Crear productos automáticamente**: si una línea trae un código que no existe en el catálogo, el módulo crea el producto.
- **Permitir tu propia empresa como proveedor**: desactivado por defecto, evita que un documento en el que tu empresa es el emisor acabe creándote a ti mismo como proveedor.
- **Automatización por webhook**: cuenta bancaria y forma de pago con las que marcar como pagadas las facturas que llegan por esa vía.
- **Escanear gasto**: si el gasto del móvil se registra como nota de gastos o como factura de compra, y si se permite validarlo.

Hay además dos pestañas informativas: **Telemetría y Protección de Datos** y **Acuerdo de Licencia**. <!-- CAMBIO v2.8.0: se mantiene el nombre real de la pestaña: es la que el usuario ve en la pantalla y sin él no la encuentra. El discurso comercial sin licencia va en la ficha, no en la guía. -->

## Uso

### El menú del módulo

EasyOcr aparece como menú propio en la barra superior. Dentro tiene estas secciones:

- **Cargar Pdf** — el visor para procesar documentos.
- **Envío por lotes** e **Historial de lotes** — procesamiento masivo (Modo IA).
- **Escanear gasto** — vista para el móvil.
- **Documentos recibidos** — los PDFs que han dejado otros módulos. <!-- NUEVO v2.8.0 -->
- **Plantillas** — las zonas guardadas por proveedor.
- **Facturas** — el historial de documentos procesados.

### Procesar una factura paso a paso (Modo Clásico)

1. Entra en **EasyOcr → Cargar Pdf** y sube el PDF (botón de carga o arrastrándolo).
2. En el panel derecho elige la etiqueta y dibuja un rectángulo sobre el dato en el PDF:

| Etiqueta | Para qué |
|----------|----------|
| 1 Con fecha de | Fecha del documento |
| 2 Factura | Número de factura del proveedor |
| 3 HT totales | Base imponible |
| 4 Precio total | Total con impuestos |
| 5 IVA | Cuota de IVA |
| 6 Descripción | Texto de la línea |
| 7 CIF/NIF | Identificación fiscal del proveedor |
| 8 Vencimiento | Fecha de vencimiento |
| 9 Proveedor | Nombre del proveedor |

3. **Etiqueta 9 (Proveedor)**: al marcar el nombre en el PDF, el módulo lo busca entre tus proveedores y lo deja seleccionado en el desplegable. Compara sin tener en cuenta tildes, puntos ni sufijos societarios, así que "S.L." con puntos y sin ellos es lo mismo. Si varios proveedores encajan con ese nombre, no elige ninguno y te avisa para que lo selecciones tú. Nunca crea un proveedor por su cuenta.
4. **Guarda la plantilla** con el nombre que quieras (por ejemplo "Factura Estándar Proveedor X"). Queda asociada al proveedor y se reutiliza en los siguientes documentos.
5. Pulsa **Generar**. En la ventana de confirmación revisa los datos y elige qué documento crear: **factura de proveedor** o **presupuesto de proveedor**. Confirma y el documento queda creado en Dolibarr.

### Procesar con el Modo IA

1. Entra en **EasyOcr → Cargar Pdf**, sube el PDF y pulsa el botón de extracción con IA.
2. El módulo devuelve el documento estructurado: proveedor (con su CIF y dirección), número y fecha, líneas con cantidad, precio e impuestos, y totales.
3. Se abre la ventana de revisión. Ahí puedes corregir cualquier campo, vincular cada línea con un producto del catálogo, elegir si el documento nace validado o en borrador, indicar el diario contable y, si el documento va a ser un presupuesto, cambiar el tipo.
4. Si las líneas no cuadran con los totales impresos, el módulo te avisa antes de crear nada.
5. Confirma y el documento se crea con su PDF adjunto.

### Presupuestos de proveedor

La extracción puede acabar en factura o en presupuesto de proveedor. Para los presupuestos:

- Hace falta el módulo **Presupuestos de proveedor** activo en Dolibarr y el permiso de creación. Si falta alguno de los dos, la opción aparece en gris con el motivo al lado.
- El número que trae el documento del proveedor queda como **referencia externa** del presupuesto, y su nota privada recoge la fecha y la validez del documento, además de los avisos si las líneas no cuadran con los totales.
- Nace en borrador o validado según el ajuste del módulo, igual que las facturas.
- Un presupuesto no se paga: al elegirlo, el bloque de pago desaparece de la ventana.
- Si vuelves a procesar el mismo documento del mismo proveedor, el módulo lo detecta y te lleva al presupuesto que ya existe.

### Reutilizar plantillas

1. Ve a **EasyOcr → Plantillas**.
2. Selecciona una plantilla guardada.
3. Carga un nuevo PDF del mismo proveedor.
4. La plantilla aplica las selecciones automáticamente.

### Envío por lotes (Modo IA)

1. Ve a **EasyOcr → Envío por lotes** y sube varios PDF a la vez.
2. El módulo los procesa en cola; puedes seguir el avance en **Historial de lotes**.
3. Al terminar, revisa el resultado por documento.

<!-- NUEVO v2.8.0: apartado completo -->
### Documentos recibidos de otros módulos

Otros módulos de EasySoft pueden dejarte documentos en EasyOcr en vez de que los subas a mano. Hoy lo hace **EasyScan**, que digitaliza papel y entrega cada documento en esta bandeja.

#### Cómo llegan

No tienes que hacer nada: cuando el otro módulo termina de digitalizar, copia el PDF a la bandeja de EasyOcr. Lo verás en **EasyOcr → Documentos recibidos**, y en el inicio del módulo la tarjeta de esta sección muestra cuántos hay pendientes.

Cada documento aparece en una lista con su origen (por ejemplo EasyScan), quién y cuándo lo entregó, y —si venía junto a una factura de proveedor— cuál es esa factura. Si ese mismo documento ya había pasado antes por el servicio de IA, la lista te lo advierte para que no lo pagues dos veces.

Recibir un documento no cuesta nada: no se envía al servicio al llegar, solo se guarda en tu instalación. El consumo de páginas de tu plan llega cuando tú decides procesarlo.

#### Cómo se procesan en lote

1. En **EasyOcr → Documentos recibidos**, marca con la casilla los que quieras procesar (o usa la casilla de la cabecera para seleccionarlos todos).
2. Pulsa **Procesar en lote**. Los documentos seleccionados se cargan en la pantalla de envío por lotes, como si los hubieras elegido a mano.
3. En esa pantalla, revisa las opciones y pulsa **Procesar**. Hasta ese momento no se ha enviado nada: el coste del lote se aplica aquí, con las mismas páginas de plan que cualquier otro PDF.
4. Cuando el servicio acepta el lote, esos documentos pasan a **Enviado en lote** y puedes seguir su resultado en **Historial de lotes**.

Si prefieres ir de uno en uno, cada fila tiene un botón para **abrir el documento en el visor**; al crear la factura desde ahí, el documento queda como **Procesado**, enlazado a la factura que ha generado.

Un documento que no se llegue a enviar (por ejemplo, un duplicado que el usuario decide saltarse) sigue pendiente, y lo puedes volver a mandar o descartar cuando quieras.

#### Cómo se descartan

En cada fila, el botón de la papelera **descarta** el documento. Te pide confirmación y, al aceptar, borra el fichero de la bandeja de EasyOcr y su registro. Los documentos ya procesados también se pueden descartar: desaparecen de la bandeja, pero la factura que crearon sigue en Dolibarr.
<!-- FIN NUEVO v2.8.0 -->

### Escanear un gasto desde el móvil (Modo IA)

1. Abre **EasyOcr → Escanear gasto** en el móvil y añádelo a la pantalla de inicio si quieres tenerlo a mano.
2. Haz una foto al ticket: la IA extrae los datos y el gasto se registra como nota de gastos o como factura de compra, según la configuración del módulo.

### Ver las facturas procesadas

En **EasyOcr → Facturas** tienes el historial, con filtros por proveedor y por referencia, y el enlace a cada documento creado en Dolibarr.

## Solución de problemas

### El PDF no se carga

- Comprueba que el archivo sea un PDF válido y que tengas permisos de acceso.
- Revisa la consola del navegador (F12) para ver el error.

### No se extrae el texto

- El PDF puede ser una imagen escaneada sin capa de texto. En ese caso usa el Modo IA.
- Comprueba que el PDF contenga texto seleccionable: si puedes seleccionarlo con el ratón, el Modo Clásico puede leerlo.

### El rectángulo no coge el dato

- Ajusta el zoom del PDF para dibujar con más precisión.
- Las plantillas guardadas se pueden editar para recolocar las zonas.

### El desplegable de proveedores sale vacío

- Un usuario solo ve los terceros de los que es comercial asignado: pide que te asignen el proveedor, o usa un usuario con ese tercero asignado.

### Al crear un proveedor da un error técnico

- Actualiza el módulo: desde la 2.7.1 está corregido el fallo de Dolibarr 21.0.x que terminaba en "Call to undefined function getCountry()".

### Un documento recibido no aparece, o sale como "Fichero no encontrado"

<!-- NUEVO v2.8.0: entrada de solución de problemas para la bandeja. -->
- Si el origen acaba de entregarlo, recarga la página: la lista se lee al abrirla.
- El estado "Fichero no encontrado" significa que el PDF ya no está en la carpeta de la bandeja (por ejemplo, porque alguien lo borró a mano del servidor). Puedes descartar la fila.
- Un documento de otra entidad no aparece en la tuya: cada entidad ve solo sus documentos.

## Compatibilidad

- Dolibarr 16 o superior (compatible hasta la 24; las pantallas del módulo, verificadas sobre la 24.0.1, y la bandeja de Documentos recibidos probada sobre la 18.0.8).
- PHP 7.4 o superior.
- MySQL 5.7+ / MariaDB 10.2+ o PostgreSQL 10+.

## Soporte

- Email: [info@easysoft.es](mailto:info@easysoft.es)
- Web: [www.easysoft.es](https://www.easysoft.es)

> Nota: esta guía se irá completando con capturas de pantalla.
