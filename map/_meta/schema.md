# Schema — map node types

Closed set for this shelf:

| `type` | Folder | Meaning |
|--------|--------|---------|
| `object` | `objects/**` | Durable noun (model, endpoint cluster, config) |
| `process` | `processes/**` | Real movement (HTTP or service flow that runs) |
| `session` | `objects/sessions/**` | What shipped in a dated change (audit trail for agents) |
| `effect-index` | `effects/CONTEXT.md` | Change → cards to open |

Frontmatter required on object/process/session cards: `universe`, `status` (`stub` \| `verified` \| `stale`), and for verified: `as_of` (date) + `commit` or branch.
