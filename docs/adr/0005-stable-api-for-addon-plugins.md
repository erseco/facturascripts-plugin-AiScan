# ADR-0005: Dejar la cola y la recepcion fuera de AiScan y ofrecer una API estable a otros plugins

## Status

Accepted

## Date

2026-11-03

## Context

Las issues #151 (analizar en segundo plano y revisar despues) y #152 (API o correo
para enviar facturas) piden una cola persistente, una bandeja de documentos y
entrada por correo o por API. Es lo que ya hace AIScanConnect, un complemento
gratuito de otro desarrollador que depende de AiScan. Con el mismo criterio se
cerro #90 (envio por correo, SFTP o Drive): AiScan se centra en extraer los datos
y crear la factura de proveedor.

AIScanConnect llama directamente a clases de `Lib/` (`ExtractionService`,
`SupplierMatcher`, `ProductMatcher`, `InvoiceMapper`, ...). Lo que no era publico,
el emparejado del analisis (proveedor, articulos y duplicados), vivia en metodos
privados de `Controller/AiScanInvoice.php`, asi que el complemento lo copiaba y
tenia que portar a mano cada mejora. Ademas, hereda de `ProductMatcher` para no
reenlazar las lineas que el revisor deja sin articulo, y quita `_upload` para que
`InvoiceMapper` no haga una segunda copia del documento. Cualquier refactor de
esas clases podia romperlo sin que nada en AiScan lo detectara.

## Decision

- AiScan no incorpora cola, bandeja, lector IMAP ni API de entrada. Se remite a
  AIScanConnect. La API REST de FacturaScripts (`/api/3/uploadFiles` y los modelos
  expuestos automaticamente) cubre una futura integracion sin autenticacion propia.
- El emparejado del analisis pasa a `Lib/InvoiceEnricher` (`enrich()` y
  `findDuplicateInvoice()`), que usan el controlador y cualquier otro plugin.
- `InvoiceMapper::mapToInvoice()` acepta `'unlinked' => true` por linea y
  `'_idfile'` para enlazar un `AttachedFile` existente sin copiarlo.
- Las clases y metodos listados en el README («Integracion con otros plugins»)
  forman la API estable. `Test/main/AddonContractTest.php` los usa igual que
  AIScanConnect: un cambio incompatible rompe el test y se anota en el CHANGELOG.

## Consequences

### Positive

- AiScan sigue pequeno y centrado; no duplica el trabajo de otro desarrollador.
- Los complementos heredan las mejoras del emparejado sin copiar codigo.
- Un refactor que rompa a los complementos falla en CI antes de publicarse.

### Negative

- Las firmas listadas quedan congeladas: cambiarlas exige una nota en el
  CHANGELOG y avisar a los complementos.
- Quien necesite segundo plano o correo depende de un plugin de terceros.

### Neutral

- AIScanConnect puede seguir usando sus adaptaciones actuales; las nuevas
  opciones son opcionales.
