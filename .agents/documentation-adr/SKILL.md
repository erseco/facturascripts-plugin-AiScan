---
name: documentation-adr
description: Documentation standards and ADR process for AiScan. Keep-a-Changelog format, ADR template with Status/Context/Decision/Consequences, README/QUICKSTART sync rules, decision tracking workflow.
---

# Documentation & ADR — AiScan Decision Tracking

## ADR (Architecture Decision Records)

### Location
```
docs/adr/
  0000-adr-template.md     # Template reference (never modified)
  0001-*.md                # First real decision
  0002-*.md                # Second decision
  ...
```

### When to Create an ADR
- Adding or removing a dependency
- Changing architectural patterns (new provider, new service layer)
- Security-related decisions (auth, encryption, API key storage)
- Significant trade-offs (performance vs simplicity, compatibility vs features)
- Choosing between alternatives (why provider X over provider Y)

### ADR Format

```markdown
# ADR-NNNN: Title in Imperative Mood

## Status
Proposed | Accepted | Deprecated | Superseded by ADR-NNNN

## Date
YYYY-MM-DD

## Context
What is the problem or need motivating this decision?
Include constraints, requirements, and forces at play.

## Decision
What has been decided and why?
Be specific about what will be done.

## Consequences

### Positive
- Benefit 1
- Benefit 2

### Negative
- Cost or risk 1

### Neutral
- Implication without clear value judgment
```

### ADR Lifecycle
1. **Proposed** — written but not yet agreed upon
2. **Accepted** — agreed and in effect
3. **Deprecated** — no longer relevant (context changed)
4. **Superseded by ADR-NNNN** — replaced by a newer decision

### Rules
- **Numbering:** 4 digits, zero-padded: `0001`, `0002`, ...
- **Filename:** `NNNN-title-in-kebab-case.md`
- **Title:** imperative mood ("Use OpenAI as default provider", not "OpenAI was chosen")
- **Immutable once accepted:** if a decision changes, create a new ADR that supersedes it
- **Consult existing ADRs** before making decisions that could contradict them
- **If superseding:** update the old ADR's Status to "Superseded by ADR-NNNN"

## Changelog

### Location
```
docs/CHANGELOG.md
```

### Format: Keep a Changelog

```markdown
# Changelog

All notable changes to AiScan will be documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)

## [Unreleased]

### Added
- New feature description

### Changed
- Existing feature modification

### Fixed
- Bug fix description

## [1.0.0] - 2024-XX-XX

### Added
- Initial release features
```

### Sections (in this order)
| Section | When to use |
|---|---|
| **Added** | New features or capabilities |
| **Changed** | Changes to existing functionality |
| **Deprecated** | Features marked for future removal |
| **Removed** | Features removed in this release |
| **Fixed** | Bug fixes |
| **Security** | Vulnerability patches |

### Rules
- `[Unreleased]` always at the top — accumulates changes between releases
- On release: move `[Unreleased]` contents to `[X.Y.Z] - YYYY-MM-DD` section
- One line per change, starting with a verb
- Reference ADR when a change stems from an architectural decision

## README & QUICKSTART Sync

### README.md
Update when:
- User-visible behavior changes (new feature, removed feature)
- Configuration options added or changed
- Supported providers list changes
- Installation requirements change

### QUICKSTART.md
Update when:
- Docker setup steps change
- Login credentials change
- Plugin enable/configuration flow changes
- New first-time setup steps needed

### Translation Files
Update `Translation/es_ES.json` and `Translation/en_EN.json` when:
- Adding new user-visible strings (labels, messages, errors)
- Changing existing string meanings
- Keys: lowercase kebab-case (`scan-invoice`, `ai-provider-error`)

## Reference Files
- `docs/adr/0000-adr-template.md` — ADR template
- `docs/CHANGELOG.md` — Project changelog
- `README.md` — User documentation (Spanish)
- `QUICKSTART.md` — Quick start guide
- `Translation/es_ES.json` — Spanish translations
- `Translation/en_EN.json` — English translations
