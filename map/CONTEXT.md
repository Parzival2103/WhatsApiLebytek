---
type: context
scope: map-root
---

# How to walk this map

1. Start at `map/CLAUDE.md` (twins: `AGENTS.md`, `routing.md` — same bytes).
2. Open **one** object or process card for the task.
3. Follow `See` links to source files; prefer code over prose.
4. Before editing, check `effects/CONTEXT.md`.

## Status of this map

- Seeded **2026-09-01** after ship of client create-instance + plan quota (PR #51, commit `f35560b` on `main`).
- Covers live `/api/v1` surface and the quota feature; not a full Laravel app map (admin Inertia, jobs internals beyond provisioning entry).

## Out of scope here

- Portal UI (`Lebytek_Portal`)
- docsV2 sandbox allowlist (follow-up)
- Green Partner HTTP details (see `app/Services/GreenApi/`)
