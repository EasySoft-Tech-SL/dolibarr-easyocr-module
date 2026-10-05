<!--
BORRADOR v2.8.0 — propuesta de página para la wiki pública.
OJO: la colección GUIAS PUBLICAS NO tiene hoy ninguna página «FAQ» bajo EASYOCR
(https://wiki.easysoft.es/doc/easyocr-h2MaNFitQp solo tiene CARACTERISTICAS y GUIA USUARIO).
Este fichero es, por tanto, una página NUEVA, no una actualización: al publicarlo se crea
DOLIBARR > MODULOS > EASYOCR > FAQ.

Contenido centrado en la novedad de la 2.8.0 (Documentos recibidos). Si quieres una FAQ más
amplia (instalación, modos, presupuestos de proveedor, gastos del móvil…), dime y añado
preguntas; estas cuatro son las que un cliente plantea de verdad sobre la bandeja.
-->

# Preguntas frecuentes

## ¿De dónde salen los documentos que aparecen en «Documentos recibidos»?

Los entregan otros módulos de EasySoft. Hoy es **EasyScan**: cuando digitaliza papel, en lugar de que tú subas el PDF a EasyOcr, lo deja directamente en la bandeja. Es una función pública del módulo (`easyocr_recibir_documento()`), así que cualquier otro módulo se puede integrar del mismo modo.

## ¿Me cuesta algo que me los envíen? ¿Cuándo se cobra?

Recibirlos no cuesta nada. Al llegar, el documento solo se copia a tu instalación: no se envía al servicio de IA. El consumo de páginas de tu plan —igual que con cualquier PDF— se aplica cuando tú lo procesas, en el visor o en el lote, y solo al pulsar el botón. Mientras el documento esté pendiente en la bandeja, no has pagado nada por él.

## ¿Qué pasa si el módulo que los envía ya no está instalado?

Nada. Cada documento de la bandeja es independiente: una vez recibido, se guarda en EasyOcr con su propio fichero y puedes procesarlo o descartarlo aunque el módulo de origen ya no esté. Lo único que cambia es que no llegarán documentos nuevos desde ese origen.

## ¿Dónde acaban los PDFs y cómo los borro?

Se copian a la carpeta de documentos de tu propia instalación de Dolibarr, dentro del área de la entidad, y no salen de ahí hasta que los procesas. En la lista tienes un botón para descargarlos y otro para **descartarlos**: al descartar, se pide confirmación y se borra el fichero y su registro de la bandeja. Los ya procesados también se pueden descartar; la factura que crearon sigue en Dolibarr.
