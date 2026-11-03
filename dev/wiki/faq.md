# Preguntas frecuentes

## ¿De dónde salen los documentos que aparecen en «Documentos recibidos»?

Los entregan otros módulos de EasySoft Tech S.L. Hoy es **EasyScan**: cuando digitaliza papel, en lugar de que tú subas el PDF a EasyOcr, lo deja directamente en la bandeja. Es una función pública del módulo (`easyocr_recibir_documento()`), así que cualquier otro módulo se puede integrar del mismo modo.

## ¿Me cuesta algo que me los envíen? ¿Cuándo se cobra?

Recibirlos no cuesta nada. Al llegar, el documento solo se copia a tu instalación: no se envía al servicio de IA. El consumo de páginas de tu plan —igual que con cualquier PDF— se aplica cuando tú lo procesas, en el visor o en el lote, y solo al pulsar el botón. Mientras el documento esté pendiente en la bandeja, no has pagado nada por él.

## ¿Qué pasa si el módulo que los envía ya no está instalado?

Nada. Cada documento de la bandeja es independiente: una vez recibido, se guarda en EasyOcr con su propio fichero y puedes procesarlo o descartarlo aunque el módulo de origen ya no esté. Lo único que cambia es que no llegarán documentos nuevos desde ese origen.

## ¿Dónde acaban los PDFs y cómo los borro?

Se copian a la carpeta de documentos de tu propia instalación de Dolibarr, dentro del área de la entidad, y no salen de ahí hasta que los procesas. En la lista tienes un botón para descargarlos y otro para **descartarlos**: al descartar, se pide confirmación y se borra el fichero y su registro de la bandeja. Los ya procesados también se pueden descartar; la factura que crearon sigue en Dolibarr.

## He subido una factura y dice que va dirigida a otra empresa. ¿Por qué?

Desde la 2.8.1, EasyOcr compara el CIF del destinatario que aparece en el documento con el de tu empresa. Si no coinciden, la factura no se registra: un documento a nombre de otra empresa (por ejemplo, el seguro de un cliente que te han reenviado para revisar) no es una compra tuya. Si el CIF de tu empresa no está en **Inicio → Configuración → Empresa/Organización**, o el documento no lo trae, se registra como siempre. Si de verdad necesitas registrar facturas a nombre de otra empresa, activa **Permitir facturas dirigidas a otra empresa** en la configuración del módulo.

## Una factura de un lote se ha quedado en borrador. ¿Por qué?

Desde la 2.8.2, las facturas que llegan por lote se quedan en borrador cuando hay algo que una persona debe mirar: un CIF/NIF que no es válido, un proveedor encontrado por el nombre y no por el CIF/NIF, varios terceros posibles, o unos totales que no suman. El motivo está en la nota privada de la factura y en **Registro de facturas recibidas**. Desde la 2.9.0 también se quedan en borrador si no se han leído los totales, si el proveedor está cerrado o si Dolibarr no ha podido validarlas. Si el proveedor no tenía el CIF/NIF guardado, añádeselo en su ficha; si son terceros duplicados, fusiónalos desde **Terceros duplicados** con **Fusionar en este**. Así las siguientes facturas irán solas. Las tienes todas en **Facturas** con el filtro **Pendientes de revisar**.

## La factura del lote no se ha marcado como pagada. ¿Por qué?

Desde la 2.8.3, si el banco rechaza el pago automático, EasyOcr lo deshace y deja la factura pendiente de pago, con el motivo en su nota privada y en **Registro de facturas recibidas**. Tampoco se paga una factura que se queda en borrador, ni la de un lote lanzado por un usuario sin permiso para registrar pagos. Lo más habitual es una cuenta de caja con una forma de pago que no es efectivo: una cuenta de caja solo admite pagos en efectivo. Cambia la cuenta o la forma de pago en la configuración del módulo; si no encajan, la propia configuración te lo avisa.

## Mi proveedor no tiene CIF/NIF y en su ficha puse el nombre. ¿Sigue funcionando?

Sí. Es habitual con proveedores de fuera de la Unión Europea, que no muestran identificación fiscal en la factura. Si en el campo CIF/NIF de su ficha has escrito su nombre, EasyOcr lo reconoce como tal: busca el proveedor por el nombre y le asigna la factura sin crear otro. En un lote, la factura queda en borrador para que confirmes que es el proveedor correcto. Si un día la IA lee un CIF/NIF real en su factura, la asigna igualmente a ese proveedor y te avisa para que lo guardes en su ficha.

## He actualizado a la 2.9.0. ¿Tengo que cambiar algo en los lotes?

No, salvo que llames al webhook desde un sistema propio. Desde la 2.9.0 la dirección de aviso lleva un token de tu empresa, que ves en la configuración del módulo. Los lotes nuevos ya la usan. Los que se enviaron antes de actualizar llevan la dirección antigua y se siguen aceptando durante 30 días, pero sin registrar el pago de esas facturas; la configuración te dice hasta qué día.

## ¿Qué hago si creo que alguien tiene la dirección de aviso?

Pulsa **Regenerar token** en la configuración del módulo. La dirección anterior deja de funcionar al momento. Los lotes que estuvieran en marcha con ella ya no crearán sus facturas: tendrás que crearlas a mano.
