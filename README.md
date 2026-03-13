# AiScan para FacturaScripts

Plugin que permite escanear facturas de proveedor con ayuda de IA directamente desde la edición de la factura de compra.

## Escaneo asistido de facturas

Desde la pantalla de edición de facturas de proveedor aparece el botón **Scan Invoice**, que permite subir un PDF o una imagen para extraer automáticamente proveedor, cabecera, impuestos y líneas.

El resultado se muestra en una vista de revisión para que puedas validar los datos antes de completar o crear la factura.

## Características

- **Integración en FacturaScripts**: añade el flujo de escaneo directamente en `EditFacturaProveedor`
- **Extracción asistida por IA**: soporta OpenAI, Gemini, Mistral y endpoints compatibles con OpenAI
- **Validación y normalización**: revisa el esquema devuelto por la IA antes de mapearlo a la factura
- **Soporte de proveedores y productos**: intenta localizar proveedor y productos existentes antes de crear datos nuevos
- **Adjunta el original**: guarda el PDF o imagen subida como archivo adjunto de la factura
- **Compatibilidad declarada**: FacturaScripts 2025 y PHP 8.1 o superior

## Instalación

1. Descarga el ZIP desde [Releases](../../releases/latest)
2. Ve a **Panel de Admin > Plugins** en FacturaScripts
3. Sube el archivo ZIP y activa el plugin
4. Entra en **AiScan Configuration** y configura el proveedor de IA que quieras usar

## Desarrollo

- `make upd`
- `make lint`
- `make test`
- `make format`
- `make package VERSION=1.0.0`

## Licencia

LGPL-3.0. Ver [LICENSE](LICENSE) para más detalles.
