# Registro de Cambios

Todos los cambios notables de EasyOcr se documentarán en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

## [2.7.0] - 2026-07-29

### Corregido — Precios unitarios multiplicados por 1000 (crítico)

- **`easyocrParseNumber()` inflaba los números nativos del JSON.** El microservicio devuelve los importes como números JSON, no como texto. Al decodificarlos, PHP entrega un `float` que la función convertía a cadena y volvía a interpretar con sus heurísticas de formato europeo: como tres dígitos tras el separador se leen como separador de millar, un precio unitario de **3,434 € se convertía en 3.434 €**.
- Afectaba a los proveedores que facturan con 3 o más decimales por unidad (el propio prompt del microservicio contempla ese caso explícitamente) y llegaba tanto a la creación desde la vista de extracción como al webhook.
- Los valores numéricos nativos (`int`, `float`) se devuelven ahora tal cual; la interpretación de formatos europeos sigue aplicándose únicamente a las cadenas. `null` y booleanos devuelven 0.
- Detectado por los tests de integración de línea añadidos en esta versión.

### Corregido — Líneas que no cuadraban con su propio importe

- El servicio de IA devuelve los precios unitarios **redondeados a 2 decimales**: un artículo facturado a 3,434 €/ud llega como 3,43. Multiplicado por la cantidad, ese redondeo abre un hueco que crecía con el tamaño de la línea: 1.500 × 3,43 = 5.145,00 € frente a los 5.150,50 € impresos en el documento. Como los totales de la factura se fuerzan con los del documento, el resultado era una factura cuyas líneas no sumaban su propio total.
- `easyocrResolveLineUnitPrice()` reconcilia ahora el precio impreso con el importe neto de la línea: si no lo reproduce, deriva el precio desde el neto (que es la cifra con la que cuadran los totales). De paso se recupera la precisión perdida — 0,35 vuelve a ser 0,347 y 3,43 vuelve a ser 3,4337.
- `easyocrResolveLineDiscount()` incorpora un tercer guardarraíl, `cantidad × 0,005`, que es el hueco máximo que el redondeo del precio unitario puede explicar por sí solo. Sin él se inventaba un descuento del 0,857 % en una línea de 1.000 unidades que no lleva ninguno. Un descuento comercial real sobre esa misma línea se sigue detectando.
- Ambos comportamientos están fijados con las ocho líneas reales que el servicio devolvió para el documento de prueba: cada una debe reproducir su importe neto y las ocho deben sumar la base imponible impresa.

### Corregido — Cantidades y tasas con coma decimal

- La cantidad de línea se leía con `floatval()`, de modo que una cantidad corregida a mano como `3,5` se quedaba en `3`. Pasa a usar `easyocrParseNumber()`.
- En el modal de revisión, las tasas de IVA/RE/IRPF y los totales editados a mano se leían con `parseFloat()`, que trunca en la coma (`21,5` → `21`). Nueva función `eoParseNum()` en el cliente, aplicada a todos los campos editables.

### Corregido — Descuentos de línea y precio unitario

- **`discount_amount` se ignoraba.** El microservicio devuelve el descuento de línea en euros además de en porcentaje, pero `easyocrCreateInvoiceFromOCR()` solo leía `discount_percent`. En las facturas cuyo descuento se imprime en euros, el descuento se perdía por completo y el precio unitario reconstruido desde `net_amount` quedaba mal.
- Nueva `easyocrResolveLineDiscount()` con tres fuentes en orden de fiabilidad: porcentaje explícito → importe absoluto sobre la línea bruta → hueco implícito entre `qty × unit_price` y `net_amount`.
- Doble umbral anti-ruido: absoluto (0,02 €) para líneas pequeñas y relativo (0,5 %) para líneas grandes con precios unitarios de 3+ decimales. Se descartan descuentos > 90 %: casi siempre significan que el OCR leyó mal la cantidad o el importe.
- Las líneas de descuento independientes (importes negativos) nunca reciben descuento inferido.
- **Doble descuento latente.** Al reconstruir el precio unitario desde `total`, el importe resultante era neto pero se pasaba a `addline()` junto a `remise_percent`, que Dolibarr aplica de nuevo. Extraído a `easyocrResolveLineUnitPrice()`, que garantiza precio bruto (pre-descuento) en todas las ramas.

### Añadido — Salvaguarda emisor / receptor

- `easyocrIsOwnCompanyTaxId()` bloquea la creación de la factura cuando el CIF/NIF extraído como proveedor coincide con el de la propia empresa: el fallo clásico del OCR al leer al receptor como emisor, que hasta ahora daba de alta tu empresa como proveedor en `llx_societe`.
- También se detecta que `supplier.tax_id` y `customer.tax_id` sean el mismo.
- Ajuste `EASYOCR_ALLOW_SELF_SUPPLIER` (desactivado por defecto) como escape para quien factura entre entidades con el mismo CIF.
- `easyocrBuildReceiverContext()` antepone a las instrucciones enviadas a la IA la identidad del receptor (nombre y IDs fiscales de `$mysoc`, con y sin prefijo de país) en `aiOcr`, `aiOcrStream` y lotes. Se añade después del filtro por plan: es una salvaguarda del ERP, no una instrucción del usuario.
- El bloque se limita a **declarar quién eres** (una línea, ~176 caracteres) y cierra con «extrae proveedor y cliente exactamente como aparecen en el documento». Dos redacciones anteriores resultaron dañinas y ambas están cubiertas por tests que impiden reintroducirlas:
  1. Pedir al modelo *verificar antes de devolver el JSON* y *volver a leer el documento*. Contra un modelo de salida estructurada con el razonamiento desactivado, un procedimiento es mucho más caro que un hecho.
  2. Afirmar que *«el proveedor es la otra empresa del documento»*. Es **falso** cuando emisor y receptor son la misma parte: ante una instrucción imposible de cumplir el modelo degeneró, repitiendo saltos de línea dentro de `supplier.address` hasta agotar los 8.192 tokens de salida. Resultado: ~75 s de proceso y JSON truncado que el servicio ya no puede parsear (`parse_error`), con el documento perdido.
- Regla que se deriva de esto: el bloque **nunca debe afirmar nada sobre el contenido del documento**, solo sobre la identidad del receptor, y siempre debe dejar al modelo una instrucción satisfacible.
- Nuevo ajuste `EASYOCR_AI_RECEIVER_CONTEXT`, **desactivado por defecto**. Es la única función de esta versión que altera la petición enviada al servicio de IA, y su redacción ya ha provocado dos regresiones, así que la configuración por defecto no la incluye: con el ajuste apagado el contenido enviado al servicio es **idéntico al de la v2.6.0**. La salvaguarda que impide dar de alta tu propia empresa como proveedor vive en el servidor y funciona con el ajuste apagado.

### Añadido — Preselección de forma de pago por histórico

- `easyocrGetSupplierPaymentDefaults()` completa lo que falta en la ficha del proveedor con la moda de las **3 últimas facturas** de ese proveedor: condiciones de pago, modo de pago y cuenta bancaria.
- El pago automático del webhook usa esa cuenta cuando no se ha indicado ninguna en la configuración ni en la URL.
- La acción `getSupplierPaymentInfo` devuelve además `payment_account_id`, `payment_account_label` y el origen del dato (`supplier`, `history`, `mixed`).

### Añadido — Vinculación de producto por línea en la revisión IA

- Columna **Producto** en la tabla de líneas con badge de estado: referencia vinculada o `—` cuando la línea se creará como línea libre. Hasta ahora el emparejamiento por `ref_fourn` era invisible y no se podía corregir, lo que dolía especialmente con `EASYOCR_AI_AUTOCREATE_PRODUCT` desactivado.
- Nueva acción `resolveProductCodes`, que replica el orden de resolución de la creación de factura (`ref_fourn` del proveedor → `ref`/`barcode`) para que el badge sea fiable.
- Buscador inline por línea, precargado con el código OCR. `searchProducts` busca ahora también por `ref_fourn` acotado al proveedor y devuelve esa referencia.
- Un `fk_product` seleccionado a mano prevalece sobre cualquier búsqueda automática (se valida que pertenezca a la entidad).
- La resolución se repite al detectarse o cambiarse el proveedor, porque las referencias de artículo son por proveedor.

### Añadido — Aviso de descuadre entre líneas y totales

- Los totales del documento se fuerzan por SQL, de modo que una línea mal leída quedaba oculta: Dolibarr mostraba totales correctos sobre líneas incorrectas.
- Banner en el modal de revisión cuando la suma de líneas no cuadra con la base imponible o el IVA declarados (tolerancia 0,05), recalculado al editar o borrar líneas.
- En servidor, `easyocrCheckTotalsConsistency()` deja aviso en `dol_syslog` y devuelve `totals_warnings` en el resultado de la creación. RE e IRPF no se cuentan como IVA.

### Añadido — Anti-duplicados por huella de documento

- Nueva tabla `llx_easyocr_processed_files`: huella sha256 por documento y entidad, con la factura que generó.
- Antes de enviar nada al microservicio se comprueba la huella en `aiOcr`, `aiOcrStream` y `batchUploadFile`, evitando pagar créditos dos veces por el mismo fichero.
- Nunca se descarta en silencio: en la vista individual se pide confirmación; en los lotes se retienen los duplicados y se pregunta una sola vez al terminar la subida, con opción de incluirlos igualmente (`force_reprocess`).
- La huella viaja al cliente por la cabecera `X-EasyOcr-File-Hash` en el flujo SSE, para que un JS cacheado antiguo no la interprete como un evento de progreso.
- **Parametrizable** desde los ajustes del módulo, con dos controles:
  - `EASYOCR_DUPLICATE_CHECK` (**activado por defecto**): desactivarlo salta la comprobación en los tres puntos de entrada. El registro de huellas se sigue guardando, de modo que al reactivarlo el histórico está completo.
  - `EASYOCR_DUPLICATE_WINDOW_DAYS` (**0 = sin límite**, por defecto): días hacia atrás que se consideran. Pensado para facturas recurrentes byte a byte idénticas, que de otro modo quedarían marcadas como duplicadas para siempre. El límite se calcula en PHP con `dol_now()`, no con `NOW()` de SQL, porque las fechas se guardan en GMT y el servidor de base de datos puede estar en otra zona.
  - Volver a ver un documento ya conocido **refresca su `date_creation`**: la ventana se mide desde la última vez que se procesó, no desde la primera.

### Cambiado — Diálogos propios en lugar de los del navegador

- Las decisiones que cuestan dinero o destruyen trabajo ya no se preguntan con `window.confirm()`. Nuevo `EasyOcr.confirm()` en `js/scripts.js`: modal construido con las clases del propio módulo (`eo-modal`, `eo-btn`), con título, tabla de datos, pregunta, botones etiquetados según la acción, cierre con Escape o clic fuera, y foco inicial en el botón de confirmar.
- El aviso de documento duplicado muestra ahora **archivo, fecha de proceso y factura vinculada** en lugar de un párrafo concatenado con `\n`. Al ser un modal, el flujo pasa a ser asíncrono: `confirmReprocess()` recibe callbacks en vez de devolver un booleano.
- `batch.php` lo reutiliza para los duplicados del lote, para cancelar un lote y para enviarlo a la papelera (estos dos en variante `danger`). Si `scripts.js` no llegara a cargarse, `eoBatchConfirm()` recae en el diálogo del navegador: una decisión nunca se pierde en silencio.
- `window.EasyOcr` se expone explícitamente al final del IIFE: `const EasyOcr = ...` en el ámbito global **no** crea la propiedad en `window`, así que las otras vistas no podían detectarlo.

### Cambiado — Visor JSON del modal de revisión

- El botón «JSON» abría un `<pre>` con el volcado plano. Ahora abre el **mismo visor de árbol del panel de easyOCR** (tema Catppuccin Mocha), portado a `css/easyocr.css` y `js/scripts.js`: plegado y desplegado por nodo, expandir/contraer todo, búsqueda con resaltado que abre los nodos donde hay coincidencias, contador de claves y valores, copiar al portapapeles y pantalla completa.
- Portado en ES5 como el resto del archivo y **sin dependencias externas**: se elimina la carga de la fuente desde `fonts.bunny.net` del original (la pila `JetBrains Mono → Fira Code → Consolas → monospace` resuelve en local) para no filtrar peticiones a terceros desde el ERP.
- El copiado recae en `document.execCommand('copy')` cuando `navigator.clipboard` no está disponible, cosa que ocurre en instalaciones servidas por HTTP plano.
- El visor se reconstruye en cada apertura y se destruye al cerrar el modal, para no acumular escuchadores de teclado.
- El panel ocupa ahora aproximadamente media altura del modal (`52vh`, `46vh` en pantallas bajas). El modal de revisión es un contenedor flex en columna y el panel, sin `flex: 0 0 auto`, quedaba aplastado a un par de líneas por el formulario de abajo. El cuerpo de revisión recibe `min-height: 0` para que su `overflow-y` siga funcionando cuando el visor está abierto.

### Corregido — Un documento que la IA no logra estructurar quedaba marcado como procesado

- El servicio responde **HTTP 200 con `status: "success"` aunque el modelo no haya conseguido emitir JSON**: en ese caso `structured_data` no trae los campos del documento sino `{raw, parse_error}`. El módulo lo tomaba por bueno, así que (a) abría el modal de revisión vacío y (b) —lo grave— **registraba la huella del fichero**. Resultado: se gastaban créditos, no se obtenía nada, y al reintentar el mismo PDF el anti-duplicados lo rechazaba por «ya procesado».
- Nueva `easyocrAiResultIsUsable()`: descarta `parse_error`, `error_code`, `structuring_error`, `structured_data` vacío y payloads sin ningún campo identificativo. Basta un campo (p. ej. solo el número de documento) para considerar la extracción revisable — una extracción parcial sigue siendo útil.
- Aplicada en `aiOcr` (mensaje de error claro en lugar de un modal vacío) y en `aiOcrStream`. El proxy SSE es *pass-through*, así que vigila el flujo en busca del marcador `"parse_error"` sin dejar de reenviar los chunks, con una cola de 16 bytes por si el marcador cae partido entre dos. En ambos casos, **si el resultado no es utilizable no se registra la huella**, de modo que el reintento siempre es posible.
- El cliente (`aiPayloadIsUsable()` en `js/scripts.js`) hace la misma comprobación sobre el evento `result` del SSE, que no pasa por el filtro de servidor.
- ⚠️ Las huellas registradas **antes** de este arreglo siguen ahí. Un documento que falló con `parse_error` seguirá dando «ya procesado» hasta que se borre su fila de `llx_easyocr_processed_files` (o se acepte reprocesar desde el aviso).

### Corregido — `import_key` demasiado largo rompía la sentencia en silencio

- `facture_fourn.import_key` es `varchar(14)`. Con `STRICT_TRANS_TABLES` (el modo por defecto de MySQL 5.7+), un valor más largo no se trunca: **la sentencia falla entera** y la factura se quedaba sin marca de origen. El `UPDATE` no comprobaba el resultado, así que no quedaba rastro.
- El valor se trunca ahora a 14 caracteres y el fallo del `UPDATE` se registra en el log. Los valores que usa el módulo (`easyocr`, `easyocr-ai`, `easyocr-exp`, `easyocr-wh`) ya cabían; esto protege a quien pase el suyo desde el webhook.

### Cambiado

- `eoBatchNotify()` distingue ahora avisos (`warn`) de éxitos: un lote omitido ya no se pinta en verde.

### Notas de actualización

- **Requiere reactivar el módulo** para crear `llx_easyocr_processed_files` (`sql/llx_easyocr_processed_files.sql` y su `.key.sql`).
- 44 claves nuevas traducidas a los 8 idiomas (ca/de/en/es/fr/gl/it/pt).
- Cuatro suites de tests, ninguna con dependencias externas:
  | Suite | Qué cubre | Coste |
  |---|---|---|
  | `php tests/easyocr_lib_test.php` | 152 aserciones sobre funciones puras (números, descuentos, precios, guards) | ninguno |
  | `node tests/easyocr_jsonviewer_test.js` | 26 aserciones sobre el visor JSON, extraídas del `scripts.js` real (incluye escapado de payloads hostiles) | ninguno |
  | `php tests/easyocr_integration_test.php` | 75 aserciones: crea proveedor y factura en Dolibarr y relee las líneas de la BD | BD (todo en transacción con *rollback*) |
  Las dos que necesitan base de datos comprueban primero que el puerto responde y salen con código 1 si no: `master.inc.php` imprime su página de error y termina con código **0**, de modo que sin base de datos la suite parecía haber pasado sin comprobar nada.
  | `php tests/easyocr_e2e_test.php --spend-credits` | 65 aserciones: PDF real → servicio de IA real → factura real | **gasta créditos** |

### Notas sobre el rendimiento del servicio (medido, no del módulo)

- Con el módulo v2.7.0 y `EASYOCR_AI_RECEIVER_CONTEXT` apagado —es decir, con la petición byte a byte idéntica a la de v2.6.0— el documento de prueba completo se procesa en **~10 s / 1.567 tokens de salida**.
- Dos documentos de prueba (emisor = receptor, y el de descuadre) hacen que el modelo **corra hasta su techo de salida: ~77 s y 19.985 tokens**, devolviendo `parse_error`. Ocurre igual llamando al servicio **sin ninguna instrucción**, así que no lo provoca el módulo: es comportamiento del servicio ante esos documentos. Lo único que le toca al módulo es fallar limpiamente y no cobrar dos veces, que es lo que arregla esta versión.
- Retrocompatible: sin `discount_amount` ni hueco implícito el cálculo de líneas no cambia; con `EASYOCR_ALLOW_SELF_SUPPLIER` activado se recupera el comportamiento anterior de la creación de proveedor.

## [2.6.0] - 2026-07-01

### Añadido — Escaneo de tickets de gasto desde el móvil (vista PWA, solo IA)
- **Nueva vista `scan-expense.php`** pensada para el **empleado en el móvil**: hace una foto del ticket, la IA extrae los datos y se registra el gasto en Dolibarr. Instalable como **PWA** (manifest + service worker) y con captura directa de **cámara** (`<input capture="environment">`).
- **Diana contable CONFIGURABLE** (decisión de negocio, ajuste por entidad `EASYOCR_EXPENSE_TARGET`):
  - **Nota de gastos** (`expensereport`) — **por defecto**; es lo correcto cuando el empleado adelanta el dinero (se vincula al empleado vía `fk_user_author`, flujo de aprobación y reembolso nativo, IVA y proyecto por línea).
  - **Factura de compra** (factura de proveedor) — reutiliza `easyocrCreateInvoiceFromOCR()`.
  - **Pago diverso** (`PaymentVarious`) — opción simple sin depender del módulo de gastos; requiere configurar cuenta bancaria + método de pago (y opcionalmente cuenta contable) en ajustes. No vincula al empleado ni desglosa IVA.
- **Dependencia de módulo**: `$this->depends = array('modExpenseReport')` en el descriptor → al activar EasyOcr se auto-activa el módulo Notas de gastos (la diana por defecto). Requiere reactivar EasyOcr para aplicarse.
- **Función nueva `easyocrCreateExpenseFromOCR()`** en `lib/easyocr.lib.php`: crea la nota de gastos (línea con base TTC → HT/IVA derivados por `calcul_price_total`), adjunta la **foto** al ECM y la enlaza a la línea (`fk_ecm_files`), soporta **proyecto** (`fk_project`) y validación opcional.
- **Asociación a PROYECTO** en ambas dianas: añadido `fk_project` a `easyocrCreateInvoiceFromOCR()` (cabecera) y por línea en la nota de gastos; el empleado elige el proyecto en el móvil.
- **Solo IA (gated):** sin plan con créditos la vista muestra pantalla de bloqueo (reutiliza la verdad operativa `/me` `can_process`); pre-flight de créditos antes de subir la foto y re-chequeo server-side en la creación. **Foto obligatoria**.
- Ajuste **"Permitir validar gasto"** (`EASYOCR_EXPENSE_ALLOW_VALIDATE`): si está activo, el empleado puede validar desde el móvil; si no, el gasto se crea en **borrador** para revisión.
- Nuevas acciones AJAX: `expenseOcr` (OCR de la **foto** — envía la imagen al microservicio con el `filename` correcto y `preprocess=1` para que la acepte de forma nativa) y `newExpenseAI` (crea el objeto con dispatch por diana). Pre-flight de créditos vía `getSubscriptionInfo`. Nueva entrada de menú y tarjeta en el dashboard "Escanear gasto".

### Traducciones
- **36 claves nuevas en los 8 idiomas** (ca/de/en/es/fr/gl/it/pt) para la vista, el ajuste de diana y los mensajes.

### Notas / pendientes
- La instalación PWA real (service worker) requiere **HTTPS con certificado válido** (en local Laragon usa cert self-signed: hay que confiar la CA en el móvil para pruebas).
- OCR de imágenes confirmado con el microservicio: `/ocr/base64` acepta fotos si se envía `filename` (valida por magic bytes); el módulo manda `receipt.jpg` + `preprocess`. (El endpoint solo asumía `.pdf` cuando no se pasaba nombre, de ahí el 415 inicial.)

## [2.5.6] - 2026-07-01

### Añadido — Pago automático de facturas creadas por el webhook
- El receptor `webhook_batch.php` puede ahora **marcar como pagada** la factura que crea, registrando el pago en una **cuenta bancaria** y con un **método de pago** concretos (con su asiento en el libro de banco vía `addPaymentToBank`). La lógica ya existía en `easyocrCreateInvoiceFromOCR()`; hasta ahora el webhook pasaba `create_payment`/`payment_bank_id`/`payment_type_id` vacíos, por lo que la factura se creaba pero nunca se pagaba.
- **Doble fuente de configuración (la URL tiene prioridad):**
  - **Config del módulo** (`admin/setup.php` → nueva sección "Pago automático (Webhook)"): `EASYOCR_WEBHOOK_MARK_PAID` (Sí/No), `EASYOCR_WEBHOOK_BANK_ID` (selector de cuenta bancaria), `EASYOCR_WEBHOOK_PAYMENT_TYPE` (selector de método de pago).
  - **Parámetros de URL** por lote que sobrescriben la config: `?pay=1&bank_id=N&payment_type=N`.
- **Trazabilidad**: nuevas líneas en el log del webhook (`create_payment`, `bank_id`, `payment_type`) y en `DEBUG-PARAMS`. Se registra un aviso explícito cuando se pide marcar pagada pero no hay cuenta bancaria, o cuando el módulo está configurado para crear borradores (`EASYOCR_INVOICE_DRAFT=1`), caso en el que el pago no puede registrarse.

### Requisito
- El pago **solo se registra si la factura se crea validada** (no borrador). Con `EASYOCR_INVOICE_DRAFT=1` el pago se omite y queda constancia en el log.

### Traducciones
- **7 claves nuevas en los 8 idiomas** (ca_ES, de_DE, en_US, es_ES, fr_FR, gl_ES, it_IT, pt_PT): `EasyOcrWebhookPaymentConfig`, `EasyOcrWebhookMarkPaid(+Desc)`, `EasyOcrWebhookBankAccount(+Desc)`, `EasyOcrWebhookPaymentType(+Desc)`.

### Compatibilidad
- Retrocompatible: con el ajuste OFF y sin parámetros de URL, el comportamiento previo (factura sin pago) se mantiene intacto.

## [2.5.5] - 2026-06-11

### Corregido — CSS/JS con doble ruta en instalaciones sobre subcarpeta
- En instalaciones donde Dolibarr cuelga de una **subcarpeta** (p. ej. `https://host/vecamarti23/`), `extract.php` y `batch.php` cargaban su CSS/JS con la ruta **duplicada** (`/vecamarti23/vecamarti23/custom/easyocr/...`) → **404**, página sin estilos ni JS (visor PDF / batch inservibles).
- **Causa:** ambos pasaban a `llxHeader` los assets ya resueltos con `dol_buildpath(...,1)`, y el core (`top_htmlhead`) **vuelve a aplicar `dol_buildpath`** a cada entrada de los arrays css/js → segundo prefijo. En instalación raíz (`DOL_URL_ROOT` vacío) no se notaba; solo en subcarpeta.
- **Arreglo:** los arrays `$arrayofjs`/`$arrayofcss` pasan ahora la **ruta relativa** (`/easyocr/...`) y dejan que el core la resuelva **una sola vez** (relativa al host). Correcto en raíz, subcarpeta y `easydoli`. (`invoices.php`/`templates.php` ya usaban arrays vacíos; no afectados.)

## [2.5.2] - 2026-05-04

### Añadido — Verdad operativa de la API `/me`
- **Lectura de `status.can_process` / `status.block_code` / `status.block_message`** en `extract.php`, `batch.php`, `admin/plan.php` y `ajax/ajax_easyocr.php`. Estos tres campos son la fuente única de verdad introducida en el panel `easyOCR-PANEL` v2.5+: indican si el usuario puede procesar AHORA, independientemente de que el plan tenga cuota teórica. Antes la UI confiaba solo en `quota.pages_remaining > 0`, lo que no detectaba el caso "suscripción `active` pero `current_period_end` vencido sin renovar".
- **Lectura de `subscription.is_overdue`** para distinguir entre `active` real y `active` con periodo vencido (renovación bloqueada).
- **Lectura de `quota.pages_available_now`** (páginas que el usuario realmente puede consumir ahora, respeta bloqueos) en lugar de `pages_remaining` (cálculo aritmético) en los indicadores de la UI. Se mantiene `pages_remaining` como info secundaria para no romper la lectura existente.
- **Banner de bloqueo en `admin/plan.php`**: cuando `!can_process` o `is_overdue`, se renderiza un banner superior rojo/ámbar con el motivo traducido (`SUBSCRIPTION_OVERDUE`, `WALLET_EMPTY`, `QUOTA_EXCEEDED`, `ACCOUNT_DISABLED`). Nuevas filas en la tabla "Suscripción": `Estado de renovación` (badge "Vencida sin renovar") y `¿Puede procesar ahora?`. Nueva fila en "Cuota": `Disponibles ahora`.
- **Banner de bloqueo en `extract.php`**: bajo el botón "AI Extract" cuando `!can_process`. El botón sale ya con `disabled` desde el render PHP, con `data-block-code` / `data-block-message` para coordinar con el JS.
- **Pantalla LOCKED en `batch.php`**: si `!can_process`, el formulario de batch se sustituye por un panel con el motivo específico. La card "Disponibles" muestra `pages_available_now` (puede ser 0 aunque haya cuota teórica) con sub-leyenda "Bloqueado · cuota teórica: X pág." cuando hay disonancia.
- **Helper compartido `easyocr_ajax_check_can_process()`** en `ajax/ajax_easyocr.php` con cache estática por petición. Consulta `/me` una sola vez aunque varios endpoints lo necesiten. Fail-open si la API antigua no expone el campo (retrocompatibilidad).

### Cambiado
- **Gate de `$canBatch` en `batch.php`**: era `$batchEnabled && $pagesRemaining > 0`. Ahora es `$batchEnabled && $apiCanProcess` (verdad operativa). El cálculo aritmético mentía cuando la suscripción estaba vencida pero la cuota mensual fresca tenía páginas disponibles.
- **Iconos/alertas de cuota en `extract.php`**: la lógica de `$statusClass` priorizaba `usage_percentage` sobre cualquier otro estado. Ahora prioriza `!can_process` y `is_overdue` antes que el porcentaje, evitando mostrar "✓ OK" cuando la suscripción estaba vencida con 1 % de uso.
- **Poller JS `getSubscriptionInfo` en `extract.php`**: cada 5 s sincroniza el botón AI con el estado de la API (añade/quita `disabled` y `data-block-*` en tiempo real). Muestra `pages_available_now` en el indicador de "restantes".
- **`getSubscriptionInfo` (AJAX)**: además de los campos antiguos, devuelve `can_process`, `block_code`, `block_message`, `is_overdue`, `pages_available_now` para que el JS reaccione sin recargar la página.

### Bloqueado en endpoints AJAX (defensa en profundidad)
- **`aiOcr` (modo síncrono)**: pre-flight `easyocr_ajax_check_can_process()`. Si bloqueado → `{status:error, message, block_code}` y aborta antes de subir el PDF al microservicio. Antes solo verificaba `custom_instructions`.
- **`aiOcrStream` (SSE)**: refactorizado al helper compartido. Si bloqueado → `event: error` SSE con `block_code` y aborta. Una sola llamada a `/me` en lugar de las dos previas (gate + features).
- **`batchCreateFromUploads`**: pre-flight con helper antes de crear el batch en el panel. Evita trabajo en vano cuando los ficheros ya se han subido pero la suscripción acaba de cambiar de estado.

### Traducciones
- **14 claves nuevas en los 8 idiomas** (ca_ES, de_DE, en_US, es_ES, fr_FR, gl_ES, it_IT, pt_PT): `EasyOcrBlock{Generic,Overdue,WalletEmpty,QuotaExceeded,AccountDisabled}`, `EasyOcrSubscriptionOverdue{Warn,Desc}`, `EasyOcrPlanRenewal{Status,Overdue,OverdueHint}`, `EasyOcrPlanCanProcess`, `EasyOcrPlanPagesAvailableNow`, `EasyOcrPlanPagesRemainingMathHint`, `EasyOcrPlanPagesAvailableNowBlockedHint`, `EasyOcrBatchBlockedButQuotaRemains`.

### Compatibilidad
- **Retrocompatible con paneles `easyOCR-PANEL` antiguos**: si la API no expone `status.can_process`, el helper hace fail-open (asume `true`) y la UI usa el cálculo aritmético antiguo. La actualización del módulo no exige actualizar el panel; solo aprovecha la nueva información cuando está disponible.

## [2.5.1] - 2026-05-04

### Corregido (Multiempresa / Multisociété)
- **Webhook batch ignoraba la entidad de origen**: El receptor `webhook_batch.php` se ejecuta en modo `NOLOGIN`, por lo que `$conf->entity` caía a 1 y todas las facturas del webhook aterrizaban en la entidad maestra. Ahora `batch.php` añade `&entity=N` a la URL del webhook (solo cuando el módulo Multicompany está activo) y `webhook_batch.php` lo lee para forzar `$conf->entity` antes del procesamiento.
- **Cross-tenant write/destrucción en plantillas**: Las operaciones de borrado y actualización sobre `llx_easyocr_template_details` (en `templates.php`, `templates_view.php` y la acción AJAX `updateTemplate`) sólo filtraban por `fk_template`, sin entidad. Un usuario podía borrar/sobrescribir las selecciones de plantillas de otra entidad enviando un `template_id` ajeno. Se añade columna `entity` a la tabla y todas las queries filtran por ella. La acción `updateTemplate` valida pertenencia antes de cualquier DELETE/INSERT destructivo.
- **Cross-tenant read en `fetchTemplateData`**: El SELECT de `template_details` no filtraba por entidad, lo que devolvía las coordenadas de las selecciones de plantillas ajenas aunque `fk_soc` viniera vacío. Corregido.
- **Recuento global en listado de plantillas**: La query `GROUP BY fk_template` en `templates.php` agregaba contadores de todas las entidades. Ahora filtra por `entity = $conf->entity`.
- **Logs/debug del webhook compartidos entre entidades**: Los directorios `DOL_DATA_ROOT/easyocr/{webhook_debug,webhook_logs,temp}/` eran únicos para toda la instalación, exponiendo payloads (proveedores, NIF, totales) entre entidades a quien tuviera acceso al filesystem. Ahora se usan rutas `entity_N/...` aisladas.
- **Búsqueda de admin por entidad**: La función `easyocrCreateInvoiceFromOCR()` cogía el primer admin global. Ahora filtra por `entity IN (getEntity('user'))` para no mezclar usuarios entre entidades.
- **Typo en `GETPOST`**: `'atohtml'` (no es un tipo válido y devolvía la cadena `BadFirstParameterForGETPOST` como valor) corregido a `'alphanohtml'` en `batch.php` y `ajax_easyocr.php`.

### Migración
- Nuevo `sql/llx_easyocr_template_details.alter2.sql`: añade columna `entity` y la rellena por JOIN con la plantilla padre. Se ejecuta automáticamente al actualizar el módulo (desactivar + activar).

### ⚠️ Acción requerida tras actualizar
- Si ya tienes batches activos en la API de EasyOCR con la URL de webhook antigua (sin `&entity=N`), regenera la URL desde el panel del batch. De lo contrario las facturas seguirán aterrizando en la entidad 1.

## [2.5.0] - 2026-03-25

### Añadido
- **Soporte completo Multiempresa / Multisociété**: Todas las tablas propias del módulo (`llx_easyocr_template`, `llx_easyocr_invoices`, `llx_easyocr_webhook_log`) incluyen ahora la columna `entity` para aislar datos por entidad. Todas las consultas SELECT, INSERT, UPDATE y DELETE filtran por `$conf->entity`. Configuración del módulo ya se guardaba por entidad. Compatible con el módulo Multicompany de Dolibarr (inodbox.com).

## [2.4.5] - 2026-03-17

### Corregido
- **Estado de factura no respetaba configuración del módulo** ([#1](https://github.com/EasySoft-Tech-SL/dolibarr-easyocr-module/issues/1)): El radio button de estado (Validada/Borrador) en el modal AI de `extract.php` tenía "Validada" siempre marcado por defecto, ignorando la configuración `EASYOCR_INVOICE_DRAFT`. Afectaba tanto a la subida manual como al batch interactivo. Ahora el valor por defecto del radio respeta la configuración del módulo.
- **OCR leía PDF anterior tras error** ([#2](https://github.com/EasySoft-Tech-SL/dolibarr-easyocr-module/issues/2)): Cuando el stream SSE fallaba y se recurría al fallback AJAX clásico, se reutilizaba el `base64` capturado en el closure original en lugar del PDF actualmente cargado. Si el usuario había cambiado de PDF durante la petición, el fallback procesaba el archivo anterior. Ahora se re-codifica desde `state.pdfArrayBuffer` actual.
- **PDF adjuntos no accesibles en facturas borrador** ([#3](https://github.com/EasySoft-Tech-SL/dolibarr-easyocr-module/issues/3)): El nombre del PDF adjunto se prefijaba con el ref provisional `(PROVx)`, cuyos paréntesis causaban problemas de encoding en URLs de Dolibarr. Para facturas borrador, ahora se guarda el archivo con su nombre original sin prefijo. Para facturas validadas se mantiene el prefijo con el ref limpio.

## [2.4.4] - 2026-03-04

### Corregido
- **Respuesta JSON con BOM de la API**: La API de `app.easyocr.es` devuelve respuestas con UTF-8 BOM (`EF BB BF`), lo que causaba que `json_decode()` fallara con "JSON malformado". Se añadió strip de BOM en `BaseResource::decode()` y `BaseResource::safeDecodeBody()`.
- **Clase `EasyOCRClient` no encontrada en `aiOcrStream`**: Faltaba cargar el autoloader antes de instanciar `EasyOCRClient` en la acción `aiOcrStream` de `ajax_easyocr.php`.

### Mejorado
- **Autoload centralizado en AJAX handler**: Se reemplazaron 7 `require_once` sueltos del autoloader por un único `dol_include_once('/easyocr/lib/easyocr_autoload.php')` al inicio de `ajax_easyocr.php`.
- **Diagnóstico de errores JSON mejorado**: El mensaje de excepción en `BaseResource::decode()` ahora incluye `json_last_error_msg()`, código HTTP y preview del body para facilitar depuración.
- **Logging en `getSubscriptionInfo`**: Se añadió `dol_syslog` en el catch de errores para registrar fallos en el log de Dolibarr.

## [2.4.3] - 2026-03-04

### Corregido
- **Falso positivo de antivirus ClamAV**: La firma heurística `{HEX}Malware.Expert...UNOFFICIAL` bloqueaba la instalación del módulo en servidores con ClamAV y firmas no oficiales. Se reemplazó `move_uploaded_file()` por `dol_move_uploaded_file()` (función nativa de Dolibarr) en `ajax_easyocr.php`, y `dol_move()` + `copy()` en `easyocr.lib.php`. Se encapsularon los accesos a `$_FILES` en variables locales para reducir la densidad de palabras clave que disparaban la heurística.

### Mejorado
- **Seguridad en subida de archivos**: El uso de `dol_move_uploaded_file()` añade las verificaciones de seguridad nativas de Dolibarr (hooks, control de errores) al proceso de subida de PDFs.

## [2.4.2] - 2026-02-27

### Añadido
- **Columna «Creación» en listado de facturas** (`invoices.php`): Nueva columna que muestra la fecha y hora de creación de la factura en Dolibarr (`datec`), ordenable, junto a la columna de fecha de factura existente.

## [2.4.1] - 2026-02-26

### Corregido
- **Error 403 en AI OCR con instrucciones personalizadas**: La API devolvía HTTP 403 cuando el plan activo no incluía la feature `custom_instructions`. Ahora se verifica el plan antes de enviar las instrucciones; si no está permitido, se procesan sin ellas en lugar de fallar.
- **Protección backend `aiOcrStream`**: Antes de llamar a la API externa, se consulta `/me` para verificar que `features.custom_instructions` está activo. Si no lo está, las instrucciones se descartan silenciosamente y el OCR continúa normalmente.

### Añadido
- **Textarea deshabilitado si plan no lo permite**: En `extract.php`, el textarea de instrucciones personalizadas se muestra deshabilitado con mensaje de upgrade cuando el plan no incluye `custom_instructions`.
- **Debug detallado en `aiOcrStream`**: 3 puntos de `dol_syslog` (ENTRY, PRE-CURL, POST-CURL) para diagnóstico de problemas con la API IA.
- **Clave de traducción `EasyOcrCustomInstructionsUpgrade`**: Añadida en los 8 idiomas (es, en, fr, de, it, pt, ca, gl).

### Mejorado
- **Contador de métricas en `index.php`**: Corregida consulta de facturas procesadas para contar desde `llx_facture_fourn` con `import_key IN ('easyocr','easyocr-ai','easyocr-wh')` en lugar de la tabla auxiliar vacía.
- **Listado de facturas (`invoices.php`)**: Incluido el tipo `easyocr-wh` (webhook) en el filtro SQL y en el selector desplegable.

## [2.4.0] - 2026-02-25

### Añadido
- **Dashboard principal** (`index.php`): Nueva página de inicio del módulo con tarjetas de acceso rápido, contadores de facturas/plantillas y diseño Material Design con iconos Font Awesome

### Mejorado
- **Rediseño visual de cabeceras**: Estilo de página unificado en todas las vistas del módulo (batch, facturas, plantillas) con cabecera consistente, iconografía y paleta de colores por sección
- **Navegación simplificada**: El menú superior dirige al nuevo dashboard; los accesos laterales se reemplazan por tarjetas visuales con permisos integrados

### Cambiado
- Renombrado `tool.php` → `extract.php` para mayor claridad semántica

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
- **Widget de suscripción** en `extract.php`: Indicador compacto desplegable con uso de cuota, plan activo, wallet y barra de progreso
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
- **Auto-refresh de suscripción**: El widget de cuota/suscripción en `extract.php` se actualiza automáticamente cada 5 segundos vía polling AJAX
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
