---
title: "API reference"
---

# API reference

Every class below is generated from a reflection pass over `src/` across all
four packages, filtered to classes carrying the `@api` docblock tag — see
`docs/scripts/reflect-api.php` and `docs/scripts/generate-api.mjs`. An
`@internal` class is deliberately excluded: it is an implementation detail,
not part of the public contract.

| Package | Namespace |
|---|---|
| [Core](/packages/core) | `Rasuvaeff\Yii3Mcp` |
| [Audit log bridge](/packages/audit-log-bridge) | `Rasuvaeff\Yii3McpAuditLogBridge` |
| [RBAC bridge](/packages/rbac-bridge) | `Rasuvaeff\Yii3McpRbacBridge` |
| [Telemetry bridge](/packages/telemetry-bridge) | `Rasuvaeff\Yii3McpTelemetryBridge` |

Use the sidebar's package pages for a curated tour of the most relevant
classes, or the search box for a specific class name — the generated pages
under `api/classes/` are not linked from the sidebar individually to keep
navigation usable across four packages' worth of public API.
