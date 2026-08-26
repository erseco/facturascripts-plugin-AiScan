# Changelog

All notable changes to AiScan will be documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)

## [Unreleased]

### Added

- **Varios modelos por proveedor** (#89): los campos `openai_model`,
  `gemini_model`, `mistral_model`, `grok_model` y `custom_model` aceptan una
  lista separada por comas; el primer modelo de la lista es el predeterminado.
- La pantalla de escaneo tiene un selector **Modelo de IA** con las
  combinaciones `Proveedor — Modelo` disponibles, preseleccionando la
  predeterminada.
- **Re-analizar** es ahora un botón dividido: el botón repite el modelo usado y
  el desplegable permite re-analizar esa factura con cualquier otro modelo
  configurado, sin volver a subir el documento ni cambiar la configuración.
- Las respuestas de análisis incluyen `_model` además de `_provider`, para que
  la ficha indique con qué modelo se analizó.
- **xAI Grok** como proveedor de primera clase (`grok_api_key` / `grok_model`,
  default `grok-4.5`). Docker acepta `XAI_API_KEY`. También se puede seguir
  usando Grok vía endpoint compatible con OpenAI (`https://api.x.ai/v1`).

### Changed

- Las peticiones Chat Completions adaptan `temperature` / `max_tokens` /
  `max_completion_tokens` al modelo (GPT-5 y o-series no envían `temperature`).
- Gemini construye `generationConfig` según la familia del modelo: Gemini 3.x
  usa `thinkingLevel` y omite `temperature`; Gemini 2.5 Flash sigue pudiendo
  desactivar thinking con `thinkingBudget: 0`.

### Fixed

- **Gemini 3.x HTTP 400 INVALID_ARGUMENT**: el plugin enviaba siempre
  `temperature: 0` y `thinkingConfig.thinkingBudget: 0`, argumentos inválidos
  en `gemini-3.6-flash`, `gemini-3.1-pro` y el resto de Gemini 3. No había
  lista blanca de modelos: el identificador ya era texto libre. También se
  ignoran las partes `thought` de la respuesta para no mezclar el razonamiento
  con el JSON extraído.

- **Convertir imagen a PDF no cambiaba el nombre** (#80): tras marcar la
  casilla, el análisis y el adjunto seguían mostrando `factura.jpeg` porque el
  cliente no aplicaba el `original_name` del servidor. Ahora la revisión, el
  resumen de importación y el archivo adjunto usan `factura.pdf`. También se
  invalida la caché del JS al cambiar el fichero (la versión del plugin sigue
  en 1.0).
- **Cola de archivos en la subida** (#84): al elegir o soltar más ficheros se
  añaden a la lista en vez de sustituirla. Cada uno tiene un botón para
  quitarlo si se ha añadido por error. La cola se bloquea al pulsar
  «Subir y analizar» para no añadir ni quitar durante la subida.
- **Cantidad 0 en líneas de prepago/abono** (#82): al importar, `cantidad = 0`
  (p. ej. línea de «ya pagado» de Leroy Merlin) se conservaba como 1 porque
  `empty(0)` en PHP es verdadero y el mapper forzaba `max(1, cantidad)`.
  Solo se usa 1 si la cantidad falta; 0 y precios negativos se importan tal cual
  (también si la línea no tiene descripción).
- **No importar sin datos vitales** (#78): si faltan número, fecha, nombre del
  proveedor o CIF/NIF (y no hay proveedor emparejado), se desactiva «Marcar como
  lista para importar», se bloquea la aprobación en lote y el servidor rechaza la
  importación. Se muestra un aviso recomendando completar los campos o
  re-analizar (con otro proveedor de IA si conviene).
- **Producto predeterminado del proveedor** (#76): un único control de búsqueda y
  selección (sin campo «Referencia» ni botón de guardado separados), se guarda al
  elegir el producto, se puede limpiar con ×, teclado usable (↑/↓/Enter/Esc) y
  textos traducidos vía `window.aiscanI18n` (ya no se muestran claves internas).

### Added

- **Convertir imagen a PDF** (#80): casilla opcional en la subida. La foto se
  envuelve en un PDF de una página (sin recorte de sombras) para escanearla y
  adjuntarla a la factura como PDF. Se aplica la orientación EXIF de los JPEG
  (fotos de móvil) y, si la conversión falla, se borran el temporal y el PDF
  parcial.
- **Descuento por importe o por %** (#81): en los tres puntos de cada línea se
  puede escribir el descuento en euros o en porcentaje; el otro valor se
  calcula solo. La IA puede devolver `dtoimporte` cuando el ticket descuenta
  dinero (p. ej. Leroy Merlin) y se convierte a `dtopor` para FacturaScripts.
- **Playground / Docker listos para facturar con IGIC**: `blueprint.json` configura empresa
  canaria, impuesto por defecto `IGIC7`, plan contable (`defaultplan`), formas de pago/serie
  y productos con IGIC; `docker/setup-aiscan.php` alinea empresa, defaults, **serie A**,
  **ejercicio abierto del año en curso** y plan contable en el stack local para poder crear
  asientos al importar facturas de compra.
- **Entrada manual ante fallo de IA** (#67): si el escaneo falla (red, HTTP, JSON inválido,
  sin API key) o el usuario elige «Entrada manual», el panel lateral se muestra vacío y
  editable con un aviso no bloqueante. El guardado/importación funciona igual que con
  datos escaneados.

### Added

- **Memoria de alias de proveedor** (#71): si el usuario corrige el proveedor en la revisión,
  se guarda una huella (CIF normalizado / nombre / IBAN / email → `codproveedor`) y en imports
  posteriores se reutiliza **antes** del matching difuso. Solo se escribe en elecciones
  explícitas (búsqueda, desambiguación, crear proveedor); nunca en auto-match.

### Fixed

- Matching de proveedor por CIF/NIF: normalización de formato (mayúsculas, espacios, puntos,
  guiones, prefijo `ES`) al comparar, compartida con las huellas de alias (#70 / #71)
- Producto por defecto del proveedor (#69): se recuerda y aplica en facturas siguientes
  (incluido modo total), se rellena al cargar/guardar el pin en la UI y se expone
  `_product_suggestion` aunque no haya líneas extraídas
- Producto habitual del proveedor (#53): limpia referencias inventadas por la IA, sugiere al
  elegir proveedor en la revisión, y si el histórico no tiene referencias enlazadas intenta
  emparejar por **descripción** de líneas anteriores
- Forma de pago duplicada (subida + revisión): solo se muestra en el panel de revisión (#57)
- Contado / tarjeta (plazo 0 o `FormaPago.pagado`) dejan la factura y los recibos como **pagados**,
  con vencimiento en la fecha de la factura, aunque el seed de FacturaScripts tenga `pagado=false` (#57)
- Badges de confianza: umbrales <50% rojo, 50–80% amarillo, ≥80% verde; si falta el CIF
  (u otro campo vacío) se muestra **0% en rojo** aunque la IA devuelva 70% (#56)
- Sin CIF/NIF del proveedor, el documento pasa a **necesita revisión** con aviso explícito (#56)

### Added

- **Modo mock de depuración** (sin IA): con `debug_mode` activo aparece el proveedor `mock` y
  un panel para elegir/rotar fixtures de `Test/fixtures/responses/`. Documentado en QUICKSTART
  y AGENTS.md. Sirve para validar UI y la sugerencia de producto (#53) sin claves API.
- Selector **Importar como proveedor / acreedor** en la pantalla de subida y en el panel de revisión.
  Al crear o reutilizar un tercero se aplica el flag `Proveedor.acreedor` de FacturaScripts, que decide
  la subcuenta contable especial (PROVEE vs ACREED). Cubre la parte contable de #58 y #59.
- Agent skills architecture in `.agents/` with 8 specialized skills (ADR-0001)
- ADR framework in `docs/adr/` for tracking architectural decisions
- This changelog

### Fixed

- Align AiScan image attachments with the core FacturaScripts upload flow so private `myft` URLs for JPG/JPEG
  use the same staging path as manual uploads
