# ADR-0001: Adoptar arquitectura de skills para instrucciones de agentes

## Status

Accepted

## Date

2026-04-02

## Context

El proyecto tenia un unico archivo `AGENTS.md` monolitico (93 lineas) que se cargaba completo
en el contexto de cualquier agente de IA, independientemente de la tarea. Esto supone:

- **Coste de contexto innecesario**: un agente trabajando en CSS cargaba reglas de PHP y viceversa.
- **Dificultad de mantenimiento**: todas las instrucciones en un solo archivo sin separacion de
  responsabilidades.
- **No hay carga progresiva**: sin forma de cargar solo las instrucciones relevantes para la tarea.

Las especificaciones [agents.md](https://agents.md/) y [agentskills.io](https://agentskills.io/)
definen un patron de skills con carga progresiva: nombre + descripcion para descubrimiento
(~100 tokens), body completo solo al activar (<5000 tokens).

## Decision

Dividir `AGENTS.md` en 8 skills especializados en `.agents/`, siguiendo la especificacion
SKILL.md de agentskills.io:

| Skill | Responsabilidad |
|---|---|
| `php-expert` | Estandares PHP, PSR-12, reglas phpcs/fixer |
| `javascript-expert` | Patrones JS, UMD/IIFE, jQuery, tests Node |
| `facturascripts-plugin` | Arquitectura de plugins FS, controllers, extensions |
| `usability-accessibility` | WCAG 2.1 AA, ARIA, navegacion por teclado |
| `devops-testing` | Docker, CI/CD, PHPUnit, make commands |
| `ai-generative` | Providers IA, prompts, esquema extraccion |
| `bootstrap-jquery-design` | Bootstrap 5, jQuery UI, patrones de modal |
| `documentation-adr` | ADRs, changelog, sincronizacion docs |

`AGENTS.md` se simplifica a un indice ligero (~50 lineas) que mantiene solo:
- Identidad del proyecto
- Tabla de skills con descripcion y trigger
- Workflow de validacion (make format/lint/test)
- Definition of Done

Adicionalmente se establece un framework de ADRs en `docs/adr/` y un changelog en
`docs/CHANGELOG.md` para documentar decisiones arquitectonicas.

## Consequences

### Positive

- Los agentes cargan solo las instrucciones relevantes para su tarea
- Cada skill es mantenible de forma independiente
- Facilita la incorporacion de nuevos expertos sin inflar AGENTS.md
- Las decisiones arquitectonicas quedan documentadas en ADRs
- Compatible con multiples herramientas de IA (Claude, Gemini, Copilot)

### Negative

- Mas archivos que mantener (14 nuevos archivos)
- Los agentes que no soportan la especificacion SKILL.md solo veran el AGENTS.md simplificado
- Requiere disciplina para crear ADRs en cada decision significativa

### Neutral

- CLAUDE.md, GEMINI.md y copilot-instructions.md no requieren cambios (siguen referenciando AGENTS.md)
- `.agents/` y `docs/` se excluyen del ZIP de distribucion
