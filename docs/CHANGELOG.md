# Changelog

All notable changes to AiScan will be documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)

## [Unreleased]

### Fixed

- Forma de pago duplicada (subida + revisión): solo se muestra en el panel de revisión (#57)
- Contado / tarjeta (plazo 0 o `FormaPago.pagado`) dejan la factura y los recibos como **pagados**,
  con vencimiento en la fecha de la factura, aunque el seed de FacturaScripts tenga `pagado=false` (#57)

### Added

- Selector **Importar como proveedor / acreedor** en la pantalla de subida y en el panel de revisión.
  Al crear o reutilizar un tercero se aplica el flag `Proveedor.acreedor` de FacturaScripts, que decide
  la subcuenta contable especial (PROVEE vs ACREED). Cubre la parte contable de #58 y #59.
- Agent skills architecture in `.agents/` with 8 specialized skills (ADR-0001)
- ADR framework in `docs/adr/` for tracking architectural decisions
- This changelog

### Fixed

- Align AiScan image attachments with the core FacturaScripts upload flow so private `myft` URLs for JPG/JPEG
  use the same staging path as manual uploads
