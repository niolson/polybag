# Docs describe a per-client export override that was removed

Status: done — 2026-10-02

Repo: `polybag`

Severity: low — documentation only; it sends readers, and agents, looking for code that
doesn't exist.
Verified: confirmed by reading.

## Problem

Commit `4e5d75d` ("Remove per-client export destination override") removed the override.
`PackageExportService::exportPackage()` now exports to the Shipment's originating
connection (when its export is enabled) plus every active global-export connection in
multi-client mode. Three places still describe the override:

- `PackageExportService::exportPackage()`'s docblock: "1. The client's explicit export
  override, or the shipment's originating data source."
- `AGENTS.md`, *Data Import / Export*: "Export: … — supports per-client export
  destination overrides".
- `AGENTS.md`, *Contributing*: the example commit subject is "Add per-client export
  destination override to PackageExportService", the feature that was later removed.

`AGENTS.md` is what every coding agent reads first, so an agent asked about export
routing will look for, or reintroduce, an override the product decided against.

## What to build

- Correct the docblock to the two destinations the code uses.
- Correct `AGENTS.md`'s export line, and pick an example commit subject that describes
  something that exists.

## Comments

- 2026-10-02 — Corrected all three places, plus two the ticket missed: the `README.md`
  settings table listed "export override" under Clients (now "label reference", which the
  Clients form does hold), and `AGENTS.md`'s *Security & Configuration* said import/export
  credentials "may be overridden per client" (now: each `DataSource` is optionally
  assigned to a client). The docblock names the two destinations the code uses; the
  `AGENTS.md` export line describes them and says the override was removed on purpose; the
  example commit subject is now `Skip partly shipped orders in batch ship` (`19`).
  Documentation only, so no test.
