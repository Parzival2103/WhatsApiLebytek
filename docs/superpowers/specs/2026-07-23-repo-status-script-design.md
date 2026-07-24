# Design: Multi-repo status script (`repo-status.ps1`)

**Date:** 2026-07-23  
**Status:** Approved for implementation (pending user review of this file)  
**Deliverable location:** `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1`  
**Spec repo:** WhatsApiLebytek (docs only; script lives outside any single product repo)

## Goal

A small PowerShell script runnable from the terminal that lists the four Lebytek sibling repos and reports anything “open”: dirty tree, stashes, unpushed/ahead-behind, open PRs, open issues, and latest CI status on the current branch.

## Repos (fixed list)

Script resolves paths as siblings of its own directory (`$PSScriptRoot`):

| Folder | Role |
|--------|------|
| `WhatsApiLebytek` | api.lebytek.com |
| `Lebytek_Framework` | waapi / lebytek.com |
| `docsV2` | docs |
| `Lebytek_Portal` | portal |

Missing folder or non-git directory → block marked `MISSING` / `NOT A GIT REPO`; continue with remaining repos; contributes to non-zero exit.

## Invocation

```powershell
.\repo-status.ps1
.\repo-status.ps1 -Fetch
```

- Default: no network fetch for git refs (uses existing remotes/tracking).
- `-Fetch`: `git fetch --quiet` per repo before ahead/behind; fetch failure → WARN and measure with local refs.

## Per-repo report

| Field | Source | Counts as “open” |
|-------|--------|------------------|
| Branch | `git branch --show-current` | No (informational) |
| Dirty | `git status --porcelain` | Yes if any lines |
| Stash | `git stash list` | Yes if count > 0 |
| Sync (ahead/behind) | vs `@{u}` when upstream exists | Yes if ahead > 0 or behind > 0 |
| Unpushed | same as ahead when upstream exists | Covered by sync |
| Open PRs | `gh pr list --state open` | Yes if count > 0 |
| Open issues | `gh issue list --state open` | Yes if count > 0 (list up to 20; show total count) |
| CI (HEAD branch) | `gh run list --branch <current> --limit 1` | Yes if latest conclusion is not `success` (includes `failure`, `cancelled`, `timed_out`, or status `in_progress` / `queued`) |

PRs: all open PRs in the repo (any head branch).  
Issues: open issues for the repo only.  
CI: latest workflow run on the **current** local branch name.

### Example output shape

```
=== WhatsApiLebytek ===
  branch:  feature/foo
  dirty:   3 files
  stash:   1
  sync:    ahead 2, behind 0
  PRs:     1 open  (#42 …)
  issues:  0 open
  CI:      failure  (run #123)

=== SUMMARY ===
  open:    WhatsApiLebytek, …
  clean:   …
```

Use console colors when supported (green clean / yellow warn / red open).

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All four repos present, git repos, and nothing “open” |
| 1 | At least one “open” item, or missing/non-git repo |
| 2 | `git` or `gh` missing from PATH |

## Error handling

- `gh` auth / API failure per repo: show `unavailable (gh …)` for PRs/issues/CI; local git fields still reported; treat remote section as WARN (repo not “clean”).
- No upstream: `sync: no upstream` (not an error by itself).
- No CI runs for branch: `CI: none` (not open).
- Out of scope: merge, push, drop stash, open browser, edit remotes.

## Dependencies

- PowerShell 5.1+ (Windows)
- `git` on PATH
- `gh` on PATH and authenticated for GitHub API fields

## Non-goals

- Profile alias / global install
- Per-repo custom hooks
- Writing reports to disk
- Scanning repos outside the fixed list of four

## Implementation notes

- Single file, no modules required.
- Prefer `Push-Location` / `Pop-Location` (or `git -C`) per repo.
- Prefer `gh ... --json` where available for stable parsing; fall back to plain text if needed.
- Script is personal tooling under `sistemas\`; this design doc lives in WhatsApiLebytek for traceability only.
