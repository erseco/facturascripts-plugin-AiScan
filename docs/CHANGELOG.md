# Changelog

All notable changes to AiScan will be documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)

## [Unreleased]

### Added

- **Playground / Docker listos para facturar con IGIC**: `blueprint.json` configura empresa
  canaria, impuesto por defecto `IGIC7`, plan contable (`defaultplan`), formas de pago/serie
  y productos con IGIC; `docker/setup-aiscan.php` alinea empresa, defaults, **serie A**,
  **ejercicio abierto del año en curso** y plan contable en el stack local para poder crear
  asientos al importar facturas de compra.
- **Entrada manual ante fallo de IA** (#67): si el escaneo falla (red, HTTP, JSON inválido,
  sin API key) o el usuario elige «Entrada manual», el panel lateral se muestra vacío y
  editable con un aviso no bloqueante. El guardado/importación funciona igual que con
  datos escaneados.

### Fixed

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
