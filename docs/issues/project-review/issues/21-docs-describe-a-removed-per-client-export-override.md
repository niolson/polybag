# Docs describe a per-client export override that was removed

Status: needs-triage

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
