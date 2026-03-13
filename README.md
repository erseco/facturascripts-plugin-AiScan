# AiScan para FacturaScripts

<a href="https://erseco.github.io/facturascripts-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Ferseco%2Ffacturascripts-plugin-AiScan%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/erseco/facturascripts-playground/main/ogimage.png" alt="Try AiScan in your browser" width="220">
</a><br>
<small><a href="https://erseco.github.io/facturascripts-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Ferseco%2Ffacturascripts-plugin-AiScan%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

Plugin para escanear facturas de proveedor con ayuda de IA directamente desde FacturaScripts. AiScan extrae automáticamente proveedor, número de factura, fechas, importes, impuestos y líneas de detalle desde un PDF o una imagen, y te permite revisar los datos antes de aplicarlos a la factura de compra.

## Cómo funciona

Desde la pantalla de edición de facturas de proveedor aparece el botón **Escanear Factura**. Al pulsarlo puedes subir un PDF o una imagen, elegir el proveedor de IA y lanzar el análisis del documento.

El resultado se muestra en una interfaz de revisión para que puedas validar o corregir proveedor, cabecera, impuestos y líneas antes de completar o actualizar la factura.

<p align="center">
  <img src=".github/scan.png" alt="Ventana de escaneo y revisión de factura" width="700">
</p>

## Características

- **Integración en FacturaScripts**: añade el flujo de escaneo directamente en `EditFacturaProveedor`
- **Extracción asistida por IA**: soporta OpenAI, Google Gemini, Mistral y endpoints compatibles con OpenAI
- **IA local en Google Chrome**: también puede usar la `Browser Prompt API` de Chrome para ejecutar el análisis en local, sin enviar la factura a servicios externos
- **Validación y normalización**: revisa el esquema devuelto por la IA antes de mapearlo a la factura
- **Soporte de proveedores y productos**: intenta localizar proveedor y productos existentes antes de crear datos nuevos
- **Adjunta el original**: guarda el PDF o imagen subida como archivo adjunto de la factura
- **Compatibilidad declarada**: FacturaScripts 2025 y PHP 8.1 o superior

## Configuración

Tras instalar el plugin, entra en **AiScan Configuration** desde el panel de administración. Desde ahí puedes configurar:

<p align="center">
  <img src=".github/settings.png" alt="Pantalla de configuración de AiScan" width="700">
</p>

- **Proveedor de IA**: OpenAI, Google Gemini, Mistral, un endpoint compatible con OpenAI o la `Browser Prompt API`
- **Modelo**: el modelo concreto que se usará para la extracción
- **Análisis automático**: analiza el documento nada más subirlo
- **Modo depuración**: guarda más información en logs para diagnosticar respuestas del proveedor
- **Extensiones permitidas**: define qué tipos de archivo se aceptan
- **Prompt de extracción**: permite ajustar el prompt enviado al modelo

## Proveedores compatibles

- **OpenAI**: requiere clave API de OpenAI
- **Google Gemini**: requiere clave API de Google AI Studio
- **Mistral**: requiere clave API de Mistral
- **OpenAI compatible**: válido para servicios como Ollama, LM Studio u otros endpoints con API compatible
- **Browser Prompt API (experimental)**: usa el motor de IA integrado en Google Chrome para procesar el documento en local, sin claves API ni envío de datos a la nube. Para usarlo necesitas una versión compatible de Google Chrome con las funciones de IA activadas. Más información: <https://developer.chrome.com/docs/ai/prompt-api>

## Uso

1. Abre una factura de proveedor en `EditFacturaProveedor`.
2. Pulsa **Escanear Factura**.
3. Sube un PDF o una imagen.
4. Selecciona el proveedor de IA que quieres usar.
5. Revisa los datos extraídos.
6. Corrige lo necesario y confirma para aplicar los datos a la factura.

## Detección de proveedores y productos

AiScan intenta emparejar automáticamente el proveedor extraído con los proveedores existentes en FacturaScripts, primero por NIF/CIF y después por nombre. Si hay varias coincidencias, permite elegir el proveedor correcto.

También intenta reutilizar productos existentes a partir del SKU o de la descripción de las líneas cuando encuentra una coincidencia clara.

## Instalación

1. Descarga el ZIP desde [Releases](../../releases/latest)
2. Ve a **Panel de Admin > Plugins** en FacturaScripts
3. Sube el archivo ZIP y activa el plugin
4. Entra en **AiScan Configuration** y configura el proveedor de IA que quieras usar

## Requisitos

- FacturaScripts 2025 o superior
- PHP 8.1 o superior
- Para extracción local de texto desde PDFs antes de enviarlos al proveedor, es recomendable tener `pdftotext` instalado en el servidor

## Desarrollo

- `make upd`
- `make lint`
- `make test`
- `make format`
- `make package VERSION=1.0.0`

## Licencia

LGPL-3.0. Ver [LICENSE](LICENSE) para más detalles.
