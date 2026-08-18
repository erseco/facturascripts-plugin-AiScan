# ADR-0004: Adaptar parametros de peticion por familia de modelo

## Status

Accepted

## Date

2026-08-18

## Context

El campo de modelo es texto libre: no hay whitelist. Gemini 2.5 Flash acepta
`temperature` y `thinkingBudget: 0`. Gemini 3.x (`gemini-3.6-flash`,
`gemini-3.1-pro`, …) rechaza ambos con HTTP 400 `INVALID_ARGUMENT` y usa
`thinkingLevel` (`low` / `medium` / `high`; `minimal` no existe en todos).

OpenAI GPT-5 / o-series usan `max_completion_tokens` y no aceptan `temperature`.
xAI Grok (Chat Completions en `https://api.x.ai/v1`) sigue usando `max_tokens`
y `temperature`.

Un payload unico por proveedor rompe al cambiar de generacion.

## Decision

Construir el cuerpo de la peticion segun el identificador del modelo:

- Gemini 3.x: `thinkingLevel: low` (valido en Flash y Pro), sin `temperature`
  ni `thinkingBudget`. Extraer el texto saltando partes `thought`.
- Gemini 2.5 Flash / Flash-Lite: `temperature: 0` y `thinkingBudget: 0`.
- Gemini 2.5 Pro: `temperature: 0` y sin `thinkingConfig` (no se puede
  desactivar thinking).
- GPT-5 / o-series: `max_completion_tokens`, sin `temperature`.
- Grok y el resto de Chat Completions: `max_tokens` + `temperature: 0`.

Anadir Grok como proveedor de primera clase (misma API compatible con OpenAI)
para no exigir configurar a mano `https://api.x.ai/v1`.

## Consequences

### Positive

- Gemini 3.x, GPT-5.x y Grok 4.x funcionan sin cambiar codigo por cada slug.
- El usuario puede pegar el identificador oficial del modelo.

### Negative

- La deteccion por prefijo (`gemini-3`, `gpt-5`, `o1`…) puede quedar corta
  si un proveedor cambia el esquema de nombres.

### Neutral

- Los defaults (`gemini-2.5-flash-lite`, `gpt-5-nano`) no cambian.
- Grok sigue siendo usable como endpoint compatible con OpenAI.
