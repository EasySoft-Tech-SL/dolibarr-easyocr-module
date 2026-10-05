# Notas del borrador — wiki pública de EasyOcr, v2.8.0

Fichero interno de trabajo. NO se publica. Los tres `.md` que sí se publican son
`caracteristicas.md`, `guia-usuario.md` y `faq.md`.

## Qué hay hoy en la wiki (Outline, colección GUIAS PUBLICAS)

Bajo `DOLIBARR > MODULOS > EASYOCR` (nodo `EASYOCR`, urlId `h2MaNFitQp`) hay **dos** páginas:

- `CARACTERISTICAS` — urlId `zaFPeVKSk2`, revisión de 30-sep-2026.
- `GUIA USUARIO` — urlId `IxiqHbMPye`, revisión de 30-sep-2026.

**No existe** ninguna página `FAQ` bajo EASYOCR. Sí hay FAQ de otros módulos (VeriFactu, takumipos),
por eso una búsqueda amplia las devuelve; el listado filtrado por "EASYOCR" solo devuelve las tres
anteriores (el nodo y sus dos hijos).

## Qué propone cada fichero

### caracteristicas.md — ACTUALIZACIÓN de la página existente
Parte del texto publicado y añade:
- Nuevo apartado «Documentos recibidos de otros módulos» dentro de «Características».
- Dos viñetas nuevas en «Qué incluye el módulo» (página de Documentos recibidos y su contador).
- El apartado «Precio y licencia» pasa a «Precio»: se quitan las referencias a la GPL v3 y a
  «código abierto» (regla de discurso de la casa). Las viñetas de licencia en «Qué incluye el
  módulo» y en la guía también se neutralizan. **Si Alberto prefiere reponer el texto legal en
  la wiki, se restaura tal cual estaba.**

### guia-usuario.md — ACTUALIZACIÓN de la página existente
Parte del texto publicado y añade:
- Nueva viñeta «Documentos recibidos» en «El menú del módulo».
- Nuevo apartado de uso «Documentos recibidos de otros módulos», con tres subapartados:
  «Cómo llegan», «Cómo se procesan en lote» y «Cómo se descartan».
- Nueva entrada en «Solución de problemas» para la bandeja.
- Dos retoques de coherencia: en «Compatibilidad» ya no se dice "las diez pantallas… sobre la
  24.0.1" (ahora hay una pantalla más y la bandeja se probó sobre la 18.0.8); y la frase de
  "un documento que no se llegue a enviar… sigue pendiente" se ajusta al comportamiento real
  (un duplicado que el usuario se salta sigue pendiente).

### faq.md — PÁGINA NUEVA
No existía. Contiene 4 preguntas sobre «Documentos recibidos»: de dónde vienen, si se cobra al
recibirlos, qué pasa si el módulo de origen ya no está, y dónde acaban / cómo se borran.
Si se quiere una FAQ más amplia (instalación, modos, presupuestos, gastos del móvil), hay que
ampliarla antes de publicar.

## Cómo distinguir lo nuevo

Dentro de los `.md` publicables, los bloques nuevos/changed van marcados con comentarios HTML
`<!-- NUEVO v2.8.0 -->` y `<!-- CAMBIO v2.8.0 -->`. Son invisibles al renderizar y se pueden
borrar al publicar sin tocar el texto.

## Empaquetado

El ZIP de release se construye con `rsync` en `.github/workflows/build-release.yml`. No había
`.gitattributes` y la carpeta `dev/` **no** estaba excluida, así que estos borradores habrían
viajado dentro del módulo. Se añadió `--exclude='dev/'` al rsync para que no salgan del repo.

## Verificado contra el código

- La bandeja solo copia y anota; no llama al servicio de IA (`lib/easyocr_inbox.lib.php`).
- Estados: pendiente, enviado en lote, procesado (`EASYOCR_INBOX_PENDING/BATCHED/PROCESSED`).
- Duplicado pendiente: no se encola dos veces; aviso si el mismo fichero ya pasó por el servicio
  (`easyocrLookupProcessedFile`).
- El lote (`batch.php?inbox[]=N`) precarga los documentos; nada sale hasta pulsar «Procesar».
- Descartar borra fichero y registro (con confirmación de Dolibarr).
- Los ya procesados siguen listados y descargables hasta descartarlos.
- Textos de interfaz tomados de `langs/es_ES/easyocr.lang`.
