# Multi-repo Status Script (`repo-status.ps1`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a single PowerShell script at `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1` that reports dirty trees, stashes, ahead/behind, open PRs/issues, and latest CI for the four fixed Lebytek sibling repos, with exit codes 0/1/2.

**Architecture:** One self-contained `.ps1` (no modules). Resolve repo folders as siblings of `$PSScriptRoot`. Collect local git facts first (`git -C`), then GitHub facts via `gh ... --json`. Aggregate per-repo “open” flags into a colored console report and a summary. Optional `-Fetch` runs `git fetch --quiet` before sync measurement.

**Tech Stack:** PowerShell 5.1+, `git`, `gh` (authenticated), optional Pester for local verification.

**Spec:** [`docs/superpowers/specs/2026-07-23-repo-status-script-design.md`](../specs/2026-07-23-repo-status-script-design.md)

## Global Constraints

- Deliverable path is exactly `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1` (outside any product repo; `sistemas\` is **not** a git root).
- Fixed repo folders (siblings of the script): `WhatsApiLebytek`, `Lebytek_Framework`, `docsV2`, `Lebytek_Portal`.
- Default: **no** `git fetch`. `-Fetch` only when passed.
- Exit `0` only when all four exist as git repos and nothing is “open”; `1` if any open/missing/non-git; `2` if `git` or `gh` missing from PATH.
- “Open” means: dirty (any porcelain lines), stash count > 0, ahead > 0 or behind > 0, open PR count > 0, open issue count > 0, or latest CI on current branch is not `success` (includes `failure` / `cancelled` / `timed_out` / status `in_progress` / `queued`).
- No upstream → `sync: no upstream` (not open by itself). No CI runs → `CI: none` (not open).
- Detached HEAD (`git branch --show-current` empty) → skip `gh run list`; show `CI: none` (not open by itself).
- `gh` failure per repo → show `unavailable (gh …)` for PRs/issues/CI; still report local git; treat remote section as WARN (repo **not** clean → exit `1` via `IsOpen`).
- `FetchWarned` alone → yellow WARN in report; does **not** set `IsOpen` (exit can still be `0`).
- **Accepted spec deviation:** issues/PRs use `--limit 20` and display `20+ open` when capped; do **not** call GraphQL for true `totalCount` (YAGNI).
- **Never** capture `gh` success JSON via `2>&1 | Out-String` (Windows PowerShell mixes CLIXML/progress into the stream and can report false zero counts). Use `Get-GhJsonText`: stdout for JSON, stderr redirected to a temp file (read only on failure).
- Missing folder → `MISSING`; non-git → `NOT A GIT REPO`; continue; both contribute to exit `1`.
- Out of scope: merge, push, drop stash, open browser, edit remotes, profile alias, writing reports to disk, scanning other folders.
- Spec/plan docs live in WhatsApiLebytek for traceability only; do **not** put the script under `WhatsApiLebytek/scripts/`.
- Because `sistemas\` has no git repo, **skip `git commit` for the script**. Checkpoint = overwrite the `.ps1` and run the task’s verification. Optionally commit this plan file in WhatsApiLebytek only if the user asks.

## File structure

| File | Responsibility |
|------|----------------|
| `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1` | Entire tool: params, dependency check, collectors, formatting, summary, exit |
| `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.Tests.ps1` | Optional Pester tests (local verification; not required for day-to-day use) |
| `docs/superpowers/specs/2026-07-23-repo-status-script-design.md` | Design reference (implement against this plan; do not relocate script) |

## Data shapes (locked for all tasks)

```powershell
# Per-repo result object (hashtable or PSCustomObject with these keys)
@{
    Name           = 'WhatsApiLebytek'   # string
    Path           = 'C:\...\WhatsApiLebytek'
    Exists         = $true               # bool
    IsGitRepo      = $true               # bool
    MissingReason  = $null               # $null | 'MISSING' | 'NOT A GIT REPO'
    Branch         = 'feature/foo'       # string or $null (empty/null if detached HEAD)
    DirtyCount     = 3                   # int
    StashCount     = 1                   # int
    HasUpstream    = $true               # bool
    Ahead          = 2                   # int (0 if no upstream)
    Behind         = 0                   # int
    FetchWarned    = $false              # bool (-Fetch failed)
    PrCount        = 1                   # int; -1 = unavailable
    PrPreview      = '#42 title…'        # string (first PR) or ''
    IssueCount     = 0                   # int; -1 = unavailable
    CiStatus       = 'failure'           # 'success'|'failure'|...|'none'|'unavailable'
    CiRunNumber    = 123                 # int or $null
    GhError        = $null               # string snippet or $null
    IsOpen         = $true               # bool — drives exit code (includes gh unavailable)
    IsWarn         = $false              # bool — FetchWarned and/or gh unavailable
}
```

**Header colors (exact):** hard-open → Red; else if `IsWarn` → Yellow; else Green.  
(`FetchWarned` alone → Yellow header, exit `0`. `gh` unavailable alone → Yellow header, exit `1`.)

**IsOpen / IsWarn computation (exact — use only these):**

```powershell
function Test-RepoIsHardOpen {
    param([Parameter(Mandatory)] $R)
    if (-not $R.Exists -or -not $R.IsGitRepo) { return $true }
    if ($R.DirtyCount -gt 0) { return $true }
    if ($R.StashCount -gt 0) { return $true }
    if ($R.HasUpstream -and ($R.Ahead -gt 0 -or $R.Behind -gt 0)) { return $true }
    if ($R.PrCount -gt 0) { return $true }
    if ($R.IssueCount -gt 0) { return $true }
    # in_progress / queued / failure / cancelled / timed_out / other non-success → open
    # success / none / unavailable handled elsewhere
    if ($R.CiStatus -notin @('success', 'none', 'unavailable')) { return $true }
    return $false
}

function Test-RepoIsWarn {
    param([Parameter(Mandatory)] $R)
    if ($R.FetchWarned) { return $true }
    if ($R.PrCount -eq -1 -or $R.IssueCount -eq -1 -or $R.CiStatus -eq 'unavailable') { return $true }
    return $false
}

function Test-RepoIsOpen {
    param([Parameter(Mandatory)] $R)
    if (Test-RepoIsHardOpen -R $R) { return $true }
    # Spec: gh unavailable → remote WARN and repo not “clean” → exit 1
    if ($R.PrCount -eq -1 -or $R.IssueCount -eq -1 -or $R.CiStatus -eq 'unavailable') { return $true }
    return $false
}
```

---

### Task 1: Scaffold + dependency gate (exit 2)

**Files:**
- Create: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1`
- Test: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.Tests.ps1` (create)

**Interfaces:**
- Produces: `param([switch]$Fetch)`, `Test-CommandExists([string]$Name)`, `Assert-Dependencies` (throws/`exit 2` when git or gh missing), fixed `$RepoNames` array

- [ ] **Step 1: Write the failing Pester test for dependency exit**

Create `repo-status.Tests.ps1`:

```powershell
#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$scriptPath = Join-Path $here 'repo-status.ps1'

Describe 'repo-status dependencies' {
    It 'exposes Test-CommandExists after dot-source with -SkipMain' {
        # Will fail until script supports being dot-sourced safely
        . $scriptPath -SkipMain
        Test-CommandExists 'git' | Should -BeOfType ([bool])
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: FAIL (script missing, or `-SkipMain` / `Test-CommandExists` not defined).

- [ ] **Step 3: Write minimal scaffold**

Create `repo-status.ps1`:

```powershell
#Requires -Version 5.1
<#
.SYNOPSIS
  Report open work across the four Lebytek sibling repos.
.PARAMETER Fetch
  Run `git fetch --quiet` in each repo before ahead/behind.
.PARAMETER SkipMain
  Dot-source mode for Pester; skip orchestration and exit.
#>
[CmdletBinding()]
param(
    [switch] $Fetch,
    [switch] $SkipMain
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:RepoNames = @(
    'WhatsApiLebytek',
    'Lebytek_Framework',
    'docsV2',
    'Lebytek_Portal'
)

function Test-CommandExists {
    param([Parameter(Mandatory)][string] $Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Assert-Dependencies {
    $missing = @()
    if (-not (Test-CommandExists 'git')) { $missing += 'git' }
    if (-not (Test-CommandExists 'gh')) { $missing += 'gh' }
    if ($missing.Count -gt 0) {
        Write-Host ("Missing required tools on PATH: {0}" -f ($missing -join ', ')) -ForegroundColor Red
        exit 2
    }
}

if (-not $SkipMain) {
    Assert-Dependencies
    Write-Host 'repo-status scaffold OK (tasks 2+ not implemented yet)' -ForegroundColor Yellow
    exit 0
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: PASS for `Test-CommandExists`.

Also smoke:

```powershell
.\repo-status.ps1
```

Expected: yellow scaffold message, exit `0`.

- [ ] **Step 5: Checkpoint (no git commit)**

Confirm both files exist under `C:\Users\User\OneDrive\Desktop\sistemas\`. Do not commit (no git root here).

---

### Task 2: Local git collectors (branch, dirty, stash, sync, optional fetch)

**Files:**
- Modify: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1`
- Modify: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.Tests.ps1`

**Interfaces:**
- Consumes: `$script:RepoNames`, `Assert-Dependencies`
- Produces:
  - `Get-RepoGitStatus -RepoPath <string> -DoFetch:<bool>` → hashtable with `Exists`, `IsGitRepo`, `MissingReason`, `Branch`, `DirtyCount`, `StashCount`, `HasUpstream`, `Ahead`, `Behind`, `FetchWarned`
  - `Get-AllRepoPaths` → array of `@{ Name; Path }` from `$PSScriptRoot`

- [ ] **Step 1: Write failing Pester tests for local git status**

Append to `repo-status.Tests.ps1`:

```powershell
Describe 'Get-RepoGitStatus' {
    BeforeAll {
        . (Join-Path $here 'repo-status.ps1') -SkipMain
        $script:TempRoot = Join-Path ([IO.Path]::GetTempPath()) ("repo-status-test-" + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $script:TempRoot | Out-Null
    }
    AfterAll {
        if (Test-Path $script:TempRoot) {
            Remove-Item -Recurse -Force $script:TempRoot
        }
    }

    It 'marks missing folder as MISSING' {
        $r = Get-RepoGitStatus -RepoPath (Join-Path $script:TempRoot 'nope') -DoFetch:$false
        $r.Exists | Should -BeFalse
        $r.MissingReason | Should -Be 'MISSING'
        $r.IsGitRepo | Should -BeFalse
    }

    It 'marks non-git directory as NOT A GIT REPO' {
        $dir = Join-Path $script:TempRoot 'plain'
        New-Item -ItemType Directory -Path $dir | Out-Null
        $r = Get-RepoGitStatus -RepoPath $dir -DoFetch:$false
        $r.Exists | Should -BeTrue
        $r.IsGitRepo | Should -BeFalse
        $r.MissingReason | Should -Be 'NOT A GIT REPO'
    }

    It 'reports dirty, stash, branch, and no upstream on a fresh repo' {
        $dir = Join-Path $script:TempRoot 'gitrepo'
        New-Item -ItemType Directory -Path $dir | Out-Null
        Push-Location $dir
        try {
            git init -q
            git checkout -b main -q 2>$null
            if (-not (git branch --show-current)) { git checkout -b main -q }
            'x' | Set-Content -Path .\file.txt -Encoding utf8
            git add file.txt
            git -c user.email='t@t' -c user.name='t' commit -q -m 'init'
            'y' | Set-Content -Path .\file.txt -Encoding utf8
            git stash push -q -m 'temp'
            'z' | Set-Content -Path .\dirty.txt -Encoding utf8
        }
        finally {
            Pop-Location
        }

        $r = Get-RepoGitStatus -RepoPath $dir -DoFetch:$false
        $r.IsGitRepo | Should -BeTrue
        $r.Branch | Should -Not -BeNullOrEmpty
        $r.DirtyCount | Should -BeGreaterThan 0
        $r.StashCount | Should -BeGreaterThan 0
        $r.HasUpstream | Should -BeFalse
        $r.Ahead | Should -Be 0
        $r.Behind | Should -Be 0
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: FAIL with `Get-RepoGitStatus` not found (or wrong behavior).

- [ ] **Step 3: Implement local collectors**

Add to `repo-status.ps1` (before the `if (-not $SkipMain)` block):

```powershell
function Get-AllRepoPaths {
    # Prefer $PSScriptRoot; when empty (rare), use script-level $PSCommandPath — NOT
    # $MyInvocation inside this function (that would resolve to the function, not the .ps1).
    $root = $PSScriptRoot
    if ([string]::IsNullOrWhiteSpace($root)) {
        $scriptPath = $PSCommandPath
        if ([string]::IsNullOrWhiteSpace($scriptPath)) {
            $scriptPath = $MyInvocation.ScriptName
        }
        $root = Split-Path -Parent $scriptPath
    }
    foreach ($name in $script:RepoNames) {
        [pscustomobject]@{
            Name = $name
            Path = Join-Path $root $name
        }
    }
}

function Get-RepoGitStatus {
    param(
        [Parameter(Mandatory)][string] $RepoPath,
        [bool] $DoFetch = $false
    )

    $result = [ordered]@{
        Exists        = $false
        IsGitRepo     = $false
        MissingReason = $null
        Branch        = $null
        DirtyCount    = 0
        StashCount    = 0
        HasUpstream   = $false
        Ahead         = 0
        Behind        = 0
        FetchWarned   = $false
    }

    if (-not (Test-Path -LiteralPath $RepoPath -PathType Container)) {
        $result.MissingReason = 'MISSING'
        return [pscustomobject]$result
    }
    $result.Exists = $true

    $gitDir = & git -C $RepoPath rev-parse --is-inside-work-tree 2>$null
    if ($LASTEXITCODE -ne 0 -or $gitDir -ne 'true') {
        $result.MissingReason = 'NOT A GIT REPO'
        return [pscustomobject]$result
    }
    $result.IsGitRepo = $true

    if ($DoFetch) {
        & git -C $RepoPath fetch --quiet 2>$null
        if ($LASTEXITCODE -ne 0) {
            $result.FetchWarned = $true
        }
    }

    $branch = (& git -C $RepoPath branch --show-current 2>$null | Out-String).Trim()
    $result.Branch = $branch

    $porcelain = @(& git -C $RepoPath status --porcelain 2>$null)
    $result.DirtyCount = @($porcelain | Where-Object { $_ -and $_ -ne '' }).Count

    $stash = @(& git -C $RepoPath stash list 2>$null)
    $result.StashCount = @($stash | Where-Object { $_ -and $_ -ne '' }).Count

    & git -C $RepoPath rev-parse --abbrev-ref '@{u}' 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $result.HasUpstream = $true
        $counts = (& git -C $RepoPath rev-list --left-right --count 'HEAD...@{u}' 2>$null | Out-String).Trim()
        # format: "<ahead>\t<behind>"
        if ($counts -match '^(\d+)\s+(\d+)$') {
            $result.Ahead = [int]$Matches[1]
            $result.Behind = [int]$Matches[2]
        }
    }

    return [pscustomobject]$result
}
```

Update the main block temporarily:

```powershell
if (-not $SkipMain) {
    Assert-Dependencies
    foreach ($repo in Get-AllRepoPaths) {
        $g = Get-RepoGitStatus -RepoPath $repo.Path -DoFetch:$Fetch.IsPresent
        Write-Host ("=== {0} ===" -f $repo.Name)
        if (-not $g.Exists) {
            Write-Host "  MISSING" -ForegroundColor Red
            continue
        }
        if (-not $g.IsGitRepo) {
            Write-Host "  NOT A GIT REPO" -ForegroundColor Red
            continue
        }
        Write-Host ("  branch:  {0}" -f $g.Branch)
        Write-Host ("  dirty:   {0} files" -f $g.DirtyCount)
        Write-Host ("  stash:   {0}" -f $g.StashCount)
        if ($g.FetchWarned) {
            Write-Host '  sync:    (fetch WARN; using local refs)' -ForegroundColor Yellow
        }
        if ($g.HasUpstream) {
            Write-Host ("  sync:    ahead {0}, behind {1}" -f $g.Ahead, $g.Behind)
        }
        else {
            Write-Host '  sync:    no upstream'
        }
    }
    exit 0
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: all Task 1–2 tests PASS.

Manual smoke:

```powershell
.\repo-status.ps1
.\repo-status.ps1 -Fetch
```

Expected: four blocks with branch/dirty/stash/sync (or MISSING/NOT A GIT REPO).

- [ ] **Step 5: Checkpoint**

Save `repo-status.ps1` / tests. No git commit.

---

### Task 3: GitHub collectors (PRs, issues, CI) + IsOpen

**Files:**
- Modify: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1`
- Modify: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.Tests.ps1`

**Interfaces:**
- Consumes: `Get-RepoGitStatus` output + repo path
- Produces:
  - `Get-RepoGitHubStatus -RepoPath <string> -Branch <string>` → `PrCount`, `PrPreview`, `IssueCount`, `CiStatus`, `CiRunNumber`, `GhError`
  - `Get-GhJsonText -Arguments <string[]>` → `{ Ok; JsonText; ErrorText }` (stdout-only on success)
  - `Test-RepoIsHardOpen` / `Test-RepoIsWarn` / `Test-RepoIsOpen` → `[bool]`
  - `Get-MergedRepoStatus` combining git + gh into one object with `Name`, `IsOpen`, `IsWarn`, etc.

- [ ] **Step 1: Write failing tests for IsOpen and JSON parsing helpers**

Append to `repo-status.Tests.ps1`:

```powershell
Describe 'Test-RepoIsOpen / IsWarn' {
    BeforeAll { . (Join-Path $here 'repo-status.ps1') -SkipMain }

    It 'is open when dirty' {
        $r = [pscustomobject]@{
            Exists = $true; IsGitRepo = $true; DirtyCount = 1; StashCount = 0
            HasUpstream = $false; Ahead = 0; Behind = 0; FetchWarned = $false
            PrCount = 0; IssueCount = 0; CiStatus = 'success'
        }
        Test-RepoIsHardOpen -R $r | Should -BeTrue
        Test-RepoIsOpen -R $r | Should -BeTrue
        Test-RepoIsWarn -R $r | Should -BeFalse
    }

    It 'is open when CI in_progress' {
        $r = [pscustomobject]@{
            Exists = $true; IsGitRepo = $true; DirtyCount = 0; StashCount = 0
            HasUpstream = $true; Ahead = 0; Behind = 0; FetchWarned = $false
            PrCount = 0; IssueCount = 0; CiStatus = 'in_progress'
        }
        Test-RepoIsOpen -R $r | Should -BeTrue
        Test-RepoIsHardOpen -R $r | Should -BeTrue
    }

    It 'is not open when CI none and everything clean' {
        $r = [pscustomobject]@{
            Exists = $true; IsGitRepo = $true; DirtyCount = 0; StashCount = 0
            HasUpstream = $false; Ahead = 0; Behind = 0; FetchWarned = $false
            PrCount = 0; IssueCount = 0; CiStatus = 'none'
        }
        Test-RepoIsOpen -R $r | Should -BeFalse
        Test-RepoIsWarn -R $r | Should -BeFalse
    }

    It 'is open+warn when gh unavailable (PrCount -1); not hard-open' {
        $r = [pscustomobject]@{
            Exists = $true; IsGitRepo = $true; DirtyCount = 0; StashCount = 0
            HasUpstream = $true; Ahead = 0; Behind = 0; FetchWarned = $false
            PrCount = -1; IssueCount = -1; CiStatus = 'unavailable'
        }
        Test-RepoIsHardOpen -R $r | Should -BeFalse
        Test-RepoIsWarn -R $r | Should -BeTrue
        Test-RepoIsOpen -R $r | Should -BeTrue
    }

    It 'FetchWarned alone is warn but not open' {
        $r = [pscustomobject]@{
            Exists = $true; IsGitRepo = $true; DirtyCount = 0; StashCount = 0
            HasUpstream = $true; Ahead = 0; Behind = 0; FetchWarned = $true
            PrCount = 0; IssueCount = 0; CiStatus = 'success'
        }
        Test-RepoIsWarn -R $r | Should -BeTrue
        Test-RepoIsOpen -R $r | Should -BeFalse
    }

    It 'is open when MISSING' {
        $r = [pscustomobject]@{
            Exists = $false; IsGitRepo = $false; DirtyCount = 0; StashCount = 0
            HasUpstream = $false; Ahead = 0; Behind = 0; FetchWarned = $false
            PrCount = 0; IssueCount = 0; CiStatus = 'none'
        }
        Test-RepoIsOpen -R $r | Should -BeTrue
    }
}

Describe 'ConvertFrom-GhPrJson' {
    BeforeAll { . (Join-Path $here 'repo-status.ps1') -SkipMain }

    It 'counts PRs and builds preview from first item' {
        $json = '[{"number":42,"title":"Add widget"},{"number":43,"title":"Other"}]'
        $parsed = ConvertFrom-GhPrJson -JsonText $json
        $parsed.PrCount | Should -Be 2
        $parsed.PrPreview | Should -Match '#42'
    }

    It 'returns unavailable on empty/invalid when marked failed' {
        $parsed = ConvertFrom-GhPrJson -JsonText $null -Failed
        $parsed.PrCount | Should -Be -1
    }
}

Describe 'ConvertFrom-GhRunJson' {
    BeforeAll { . (Join-Path $here 'repo-status.ps1') -SkipMain }

    It 'maps success conclusion' {
        $json = '[{"status":"completed","conclusion":"success","number":9}]'
        $parsed = ConvertFrom-GhRunJson -JsonText $json
        $parsed.CiStatus | Should -Be 'success'
        $parsed.CiRunNumber | Should -Be 9
    }

    It 'maps in_progress status' {
        $json = '[{"status":"in_progress","conclusion":"","number":10}]'
        $parsed = ConvertFrom-GhRunJson -JsonText $json
        $parsed.CiStatus | Should -Be 'in_progress'
    }

    It 'returns none on empty array' {
        $parsed = ConvertFrom-GhRunJson -JsonText '[]'
        $parsed.CiStatus | Should -Be 'none'
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: FAIL (`Test-RepoIsOpen` / `Test-RepoIsWarn` / `ConvertFrom-GhPrJson` / `ConvertFrom-GhRunJson` missing).

- [ ] **Step 3: Implement GitHub helpers + merge**

Add to `repo-status.ps1`:

```powershell
function Test-RepoIsHardOpen {
    param([Parameter(Mandatory)] $R)
    if (-not $R.Exists -or -not $R.IsGitRepo) { return $true }
    if ($R.DirtyCount -gt 0) { return $true }
    if ($R.StashCount -gt 0) { return $true }
    if ($R.HasUpstream -and ($R.Ahead -gt 0 -or $R.Behind -gt 0)) { return $true }
    if ($R.PrCount -gt 0) { return $true }
    if ($R.IssueCount -gt 0) { return $true }
    if ($R.CiStatus -notin @('success', 'none', 'unavailable')) { return $true }
    return $false
}

function Test-RepoIsWarn {
    param([Parameter(Mandatory)] $R)
    if ($R.FetchWarned) { return $true }
    if ($R.PrCount -eq -1 -or $R.IssueCount -eq -1 -or $R.CiStatus -eq 'unavailable') { return $true }
    return $false
}

function Test-RepoIsOpen {
    param([Parameter(Mandatory)] $R)
    if (Test-RepoIsHardOpen -R $R) { return $true }
    if ($R.PrCount -eq -1 -or $R.IssueCount -eq -1 -or $R.CiStatus -eq 'unavailable') { return $true }
    return $false
}

function ConvertFrom-GhPrJson {
    param(
        [string] $JsonText,
        [switch] $Failed
    )
    if ($Failed) {
        return [pscustomobject]@{ PrCount = -1; PrPreview = '' }
    }
    if ([string]::IsNullOrWhiteSpace($JsonText)) {
        return [pscustomobject]@{ PrCount = 0; PrPreview = '' }
    }
    $items = $JsonText | ConvertFrom-Json
    if ($null -eq $items) {
        return [pscustomobject]@{ PrCount = 0; PrPreview = '' }
    }
    $arr = @($items)
    $preview = ''
    if ($arr.Count -gt 0) {
        $first = $arr[0]
        $preview = ('#{0} {1}' -f $first.number, $first.title)
        if ($preview.Length -gt 60) {
            $preview = $preview.Substring(0, 57) + '...'
        }
    }
    return [pscustomobject]@{
        PrCount   = $arr.Count
        PrPreview = $preview
    }
}

function ConvertFrom-GhIssueJson {
    param(
        [string] $JsonText,
        [switch] $Failed
    )
    if ($Failed) {
        return [pscustomobject]@{ IssueCount = -1 }
    }
    if ([string]::IsNullOrWhiteSpace($JsonText)) {
        return [pscustomobject]@{ IssueCount = 0 }
    }
    $items = $JsonText | ConvertFrom-Json
    return [pscustomobject]@{ IssueCount = @($items).Count }
}

function ConvertFrom-GhRunJson {
    param(
        [string] $JsonText,
        [switch] $Failed
    )
    if ($Failed) {
        return [pscustomobject]@{ CiStatus = 'unavailable'; CiRunNumber = $null }
    }
    if ([string]::IsNullOrWhiteSpace($JsonText)) {
        return [pscustomobject]@{ CiStatus = 'none'; CiRunNumber = $null }
    }
    $items = @($JsonText | ConvertFrom-Json)
    if ($items.Count -eq 0) {
        return [pscustomobject]@{ CiStatus = 'none'; CiRunNumber = $null }
    }
    $run = $items[0]
    $status = [string]$run.status
    $conclusion = [string]$run.conclusion
    $ci = 'none'
    if ($status -in @('in_progress', 'queued', 'pending', 'waiting', 'requested')) {
        $ci = $status
    }
    elseif (-not [string]::IsNullOrWhiteSpace($conclusion)) {
        $ci = $conclusion
    }
    elseif (-not [string]::IsNullOrWhiteSpace($status)) {
        $ci = $status
    }
    return [pscustomobject]@{
        CiStatus    = $ci
        CiRunNumber = $(if ($run.PSObject.Properties.Name -contains 'number') { $run.number } else { $null })
    }
}

# CRITICAL: do not use `2>&1 | Out-String` on the success path — PS 5.1 can mix
# CLIXML/progress into the captured text and ConvertFrom-Json silently yields 0 items.
function Get-GhJsonText {
    param([Parameter(Mandatory)][string[]] $Arguments)

    $stderrFile = [IO.Path]::GetTempFileName()
    try {
        $stdout = & gh @Arguments 2>$stderrFile
        $code = $LASTEXITCODE
        $errText = ''
        if (Test-Path -LiteralPath $stderrFile) {
            $errText = (Get-Content -LiteralPath $stderrFile -Raw -ErrorAction SilentlyContinue)
            if ($null -eq $errText) { $errText = '' }
        }
        $errText = ($errText -replace '\s+', ' ').Trim()
        if ($errText.Length -gt 80) {
            $errText = $errText.Substring(0, 77) + '...'
        }
        $jsonText = if ($null -eq $stdout) { '' } else { ((@($stdout) | Out-String).Trim()) }
        return [pscustomobject]@{
            Ok        = ($code -eq 0)
            JsonText  = $(if ($code -eq 0) { $jsonText } else { '' })
            ErrorText = $(if ($code -ne 0) { $errText } else { '' })
        }
    }
    finally {
        Remove-Item -LiteralPath $stderrFile -Force -ErrorAction SilentlyContinue
    }
}

function Get-RepoGitHubStatus {
    param(
        [Parameter(Mandatory)][string] $RepoPath,
        [string] $Branch
    )

    $ghError = $null
    $prFailed = $false
    $issueFailed = $false
    $runFailed = $false

    $prJson = ''
    $issueJson = ''
    $runJson = ''

    Push-Location $RepoPath
    try {
        $prResult = Get-GhJsonText -Arguments @('pr', 'list', '--state', 'open', '--limit', '20', '--json', 'number,title')
        if (-not $prResult.Ok) {
            $prFailed = $true
            $ghError = $prResult.ErrorText
        }
        else {
            $prJson = $prResult.JsonText
        }

        $issueResult = Get-GhJsonText -Arguments @('issue', 'list', '--state', 'open', '--limit', '20', '--json', 'number,title')
        if (-not $issueResult.Ok) {
            $issueFailed = $true
            if (-not $ghError) { $ghError = $issueResult.ErrorText }
        }
        else {
            $issueJson = $issueResult.JsonText
        }

        if ([string]::IsNullOrWhiteSpace($Branch)) {
            # Detached HEAD / empty branch → no runs query; CI none (not open)
            $runJson = '[]'
        }
        else {
            $runResult = Get-GhJsonText -Arguments @('run', 'list', '--branch', $Branch, '--limit', '1', '--json', 'status,conclusion,number,databaseId')
            if (-not $runResult.Ok) {
                $runFailed = $true
                if (-not $ghError) { $ghError = $runResult.ErrorText }
            }
            else {
                $runJson = $runResult.JsonText
            }
        }
    }
    finally {
        Pop-Location
    }

    $pr = ConvertFrom-GhPrJson -JsonText $prJson -Failed:$prFailed
    $issues = ConvertFrom-GhIssueJson -JsonText $issueJson -Failed:$issueFailed
    $ci = ConvertFrom-GhRunJson -JsonText $runJson -Failed:$runFailed

    return [pscustomobject]@{
        PrCount     = $pr.PrCount
        PrPreview   = $pr.PrPreview
        IssueCount  = $issues.IssueCount
        CiStatus    = $ci.CiStatus
        CiRunNumber = $ci.CiRunNumber
        GhError     = $ghError
    }
}

function Get-MergedRepoStatus {
    param(
        [Parameter(Mandatory)][string] $Name,
        [Parameter(Mandatory)][string] $Path,
        [bool] $DoFetch = $false
    )

    $git = Get-RepoGitStatus -RepoPath $Path -DoFetch:$DoFetch
    $obj = [ordered]@{
        Name          = $Name
        Path          = $Path
        Exists        = $git.Exists
        IsGitRepo     = $git.IsGitRepo
        MissingReason = $git.MissingReason
        Branch        = $git.Branch
        DirtyCount    = $git.DirtyCount
        StashCount    = $git.StashCount
        HasUpstream   = $git.HasUpstream
        Ahead         = $git.Ahead
        Behind        = $git.Behind
        FetchWarned   = $git.FetchWarned
        PrCount       = 0
        PrPreview     = ''
        IssueCount    = 0
        CiStatus      = 'none'
        CiRunNumber   = $null
        GhError       = $null
        IsOpen        = $false
        IsWarn        = $false
    }

    if ($git.Exists -and $git.IsGitRepo) {
        $gh = Get-RepoGitHubStatus -RepoPath $Path -Branch $git.Branch
        $obj.PrCount = $gh.PrCount
        $obj.PrPreview = $gh.PrPreview
        $obj.IssueCount = $gh.IssueCount
        $obj.CiStatus = $gh.CiStatus
        $obj.CiRunNumber = $gh.CiRunNumber
        $obj.GhError = $gh.GhError
    }

    $merged = [pscustomobject]$obj
    $merged | Add-Member -NotePropertyName IsOpen -NotePropertyValue (Test-RepoIsOpen -R $merged) -Force
    $merged | Add-Member -NotePropertyName IsWarn -NotePropertyValue (Test-RepoIsWarn -R $merged) -Force
    return $merged
}
```

**Issue total note (accepted deviation):** list up to 20; display count with `20+ open` when capped. Same for PRs. No GraphQL `totalCount`.

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: PASS.

Wire main to call `Get-MergedRepoStatus` and print raw fields (formatting polished in Task 4):

```powershell
if (-not $SkipMain) {
    Assert-Dependencies
    foreach ($repo in Get-AllRepoPaths) {
        $r = Get-MergedRepoStatus -Name $repo.Name -Path $repo.Path -DoFetch:$Fetch.IsPresent
        Write-Host ("=== {0} === open={1}" -f $r.Name, $r.IsOpen)
        if ($r.MissingReason) { Write-Host ("  {0}" -f $r.MissingReason); continue }
        Write-Host ("  branch: {0}; dirty: {1}; stash: {2}" -f $r.Branch, $r.DirtyCount, $r.StashCount)
        Write-Host ("  PRs: {0}; issues: {1}; CI: {2}" -f $r.PrCount, $r.IssueCount, $r.CiStatus)
    }
    exit 0
}
```

Smoke:

```powershell
.\repo-status.ps1
```

Expected: PR/issue/CI numbers appear for real repos (or `unavailable` if `gh` auth fails).

- [ ] **Step 5: Checkpoint**

Save files. No git commit.

---

### Task 4: Colored report, SUMMARY, exit codes

**Files:**
- Modify: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.ps1`
- Modify: `C:\Users\User\OneDrive\Desktop\sistemas\repo-status.Tests.ps1`

**Interfaces:**
- Consumes: `Get-MergedRepoStatus`, `Test-RepoIsOpen`
- Produces: `Write-RepoReport -R <object>`, `Write-Summary -Results <object[]>`, `Get-RepoStatusExitCode -Results <object[]>` → `0` or `1` (caller still uses `2` from `Assert-Dependencies`)

- [ ] **Step 1: Write failing tests for exit-code helper and format helpers**

Append:

```powershell
Describe 'Get-RepoStatusExitCode' {
    BeforeAll { . (Join-Path $here 'repo-status.ps1') -SkipMain }

    It 'returns 0 when all clean' {
        $clean = [pscustomobject]@{
            Name='A'; Exists=$true; IsGitRepo=$true; IsOpen=$false
        }
        Get-RepoStatusExitCode -Results @($clean, $clean, $clean, $clean) | Should -Be 0
    }

    It 'returns 1 when any IsOpen' {
        $clean = [pscustomobject]@{ Name='A'; Exists=$true; IsGitRepo=$true; IsOpen=$false }
        $open  = [pscustomobject]@{ Name='B'; Exists=$true; IsGitRepo=$true; IsOpen=$true }
        Get-RepoStatusExitCode -Results @($clean, $open) | Should -Be 1
    }
}

Describe 'Format-CountLabel' {
    BeforeAll { . (Join-Path $here 'repo-status.ps1') -SkipMain }

    It 'caps at 20+' {
        Format-CountLabel -Count 20 -CappedAt 20 | Should -Be '20+ open'
        Format-CountLabel -Count 3 -CappedAt 20 | Should -Be '3 open'
        Format-CountLabel -Count -1 -CappedAt 20 | Should -Be 'unavailable'
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```powershell
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
```

Expected: FAIL on missing `Get-RepoStatusExitCode` / `Format-CountLabel`.

- [ ] **Step 3: Implement formatting + main orchestration**

Add:

```powershell
function Format-CountLabel {
    param(
        [int] $Count,
        [int] $CappedAt = 20
    )
    if ($Count -lt 0) { return 'unavailable' }
    if ($Count -ge $CappedAt) { return ('{0}+ open' -f $CappedAt) }
    return ('{0} open' -f $Count)
}

function Get-RepoStatusExitCode {
    param([Parameter(Mandatory)][object[]] $Results)
    foreach ($r in $Results) {
        if ($r.IsOpen) { return 1 }
    }
    return 0
}

function Write-ColorLine {
    param(
        [string] $Text,
        [ValidateSet('Green', 'Yellow', 'Red', 'Gray', 'White')]
        [string] $Color = 'White'
    )
    try {
        Write-Host $Text -ForegroundColor $Color
    }
    catch {
        Write-Host $Text
    }
}

function Write-RepoReport {
    param([Parameter(Mandatory)] $R)

    # Hard-open → Red; warn-only (fetch/gh) → Yellow; else Green
    $headerColor = if (Test-RepoIsHardOpen -R $R) { 'Red' }
        elseif ($R.IsWarn) { 'Yellow' }
        else { 'Green' }
    Write-ColorLine -Text ("=== {0} ===" -f $R.Name) -Color $headerColor

    if (-not $R.Exists) {
        Write-ColorLine -Text '  MISSING' -Color Red
        return
    }
    if (-not $R.IsGitRepo) {
        Write-ColorLine -Text '  NOT A GIT REPO' -Color Red
        return
    }

    Write-Host ("  branch:  {0}" -f $R.Branch)

    $dirtyColor = if ($R.DirtyCount -gt 0) { 'Yellow' } else { 'Green' }
    Write-ColorLine -Text ("  dirty:   {0} files" -f $R.DirtyCount) -Color $dirtyColor

    $stashColor = if ($R.StashCount -gt 0) { 'Yellow' } else { 'Green' }
    Write-ColorLine -Text ("  stash:   {0}" -f $R.StashCount) -Color $stashColor

    if ($R.FetchWarned) {
        Write-ColorLine -Text '  fetch:   WARN (using local refs)' -Color Yellow
    }

    if ($R.HasUpstream) {
        $syncOpen = ($R.Ahead -gt 0 -or $R.Behind -gt 0)
        $syncColor = if ($syncOpen) { 'Yellow' } else { 'Green' }
        Write-ColorLine -Text ("  sync:    ahead {0}, behind {1}" -f $R.Ahead, $R.Behind) -Color $syncColor
    }
    else {
        Write-Host '  sync:    no upstream'
    }

    if ($R.PrCount -lt 0) {
        $msg = '  PRs:     unavailable'
        if ($R.GhError) { $msg += (" (gh {0})" -f $R.GhError) }
        Write-ColorLine -Text $msg -Color Yellow
    }
    else {
        $prLabel = Format-CountLabel -Count $R.PrCount
        $prLine = "  PRs:     $prLabel"
        if ($R.PrCount -gt 0 -and $R.PrPreview) {
            $prLine += ("  ({0})" -f $R.PrPreview)
        }
        $prColor = if ($R.PrCount -gt 0) { 'Yellow' } else { 'Green' }
        Write-ColorLine -Text $prLine -Color $prColor
    }

    if ($R.IssueCount -lt 0) {
        Write-ColorLine -Text '  issues:  unavailable' -Color Yellow
    }
    else {
        $issueLabel = Format-CountLabel -Count $R.IssueCount
        $issueColor = if ($R.IssueCount -gt 0) { 'Yellow' } else { 'Green' }
        Write-ColorLine -Text ("  issues:  {0}" -f $issueLabel) -Color $issueColor
    }

    if ($R.CiStatus -eq 'unavailable') {
        Write-ColorLine -Text '  CI:      unavailable' -Color Yellow
    }
    elseif ($R.CiStatus -eq 'none') {
        Write-Host '  CI:      none'
    }
    elseif ($R.CiStatus -eq 'success') {
        $ciLine = '  CI:      success'
        if ($null -ne $R.CiRunNumber) { $ciLine += ("  (run #{0})" -f $R.CiRunNumber) }
        Write-ColorLine -Text $ciLine -Color Green
    }
    else {
        $ciLine = ("  CI:      {0}" -f $R.CiStatus)
        if ($null -ne $R.CiRunNumber) { $ciLine += ("  (run #{0})" -f $R.CiRunNumber) }
        Write-ColorLine -Text $ciLine -Color Red
    }
}

function Write-Summary {
    param([Parameter(Mandatory)][object[]] $Results)
    Write-Host ''
    Write-ColorLine -Text '=== SUMMARY ===' -Color White
    $openNames = @($Results | Where-Object { $_.IsOpen } | ForEach-Object { $_.Name })
    $cleanNames = @($Results | Where-Object { -not $_.IsOpen } | ForEach-Object { $_.Name })
    $openText = if ($openNames.Count -eq 0) { '(none)' } else { ($openNames -join ', ') }
    $cleanText = if ($cleanNames.Count -eq 0) { '(none)' } else { ($cleanNames -join ', ') }
    Write-ColorLine -Text ("  open:    {0}" -f $openText) -Color $(if ($openNames.Count) { 'Red' } else { 'Green' })
    Write-ColorLine -Text ("  clean:   {0}" -f $cleanText) -Color Green
}

# Replace main block:
if (-not $SkipMain) {
    Assert-Dependencies
    $results = @()
    foreach ($repo in Get-AllRepoPaths) {
        $results += Get-MergedRepoStatus -Name $repo.Name -Path $repo.Path -DoFetch:$Fetch.IsPresent
    }
    foreach ($r in $results) {
        Write-RepoReport -R $r
        Write-Host ''
    }
    Write-Summary -Results $results
    exit (Get-RepoStatusExitCode -Results $results)
}
```

- [ ] **Step 4: Run tests + smoke**

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
Invoke-Pester -Path .\repo-status.Tests.ps1 -Output Detailed
.\repo-status.ps1; Write-Host "EXIT=$LASTEXITCODE"
.\repo-status.ps1 -Fetch; Write-Host "EXIT=$LASTEXITCODE"
```

Expected:
- Pester PASS
- Output matches spec shape (`=== Name ===`, fields, `=== SUMMARY ===`)
- Exit `0` only if all four clean; otherwise `1`
- Colors when host supports them

- [ ] **Step 5: Checkpoint**

Save final script. No git commit for the script.

---

### Task 5: Exit-2 smoke + docs alignment check

**Files:**
- Modify: none required if Task 4 complete
- Verify: `docs/superpowers/specs/2026-07-23-repo-status-script-design.md` still matches behavior
- Optional keep: `repo-status.Tests.ps1` beside the script for future regression

**Interfaces:**
- Consumes: finished `repo-status.ps1`
- Produces: verified exit `2` path; confirmation that non-goals are respected

- [ ] **Step 1: Verify exit 2 when gh is shadowed**

In a new PowerShell process:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
$env:PATH = ($env:PATH -split ';' | Where-Object { $_ -notmatch 'GitHub CLI' }) -join ';'
# If gh still resolves, use a temp PATH with only System32 + Git:
$gitCmd = Split-Path (Get-Command git).Source
$env:PATH = "$gitCmd;C:\Windows\System32"
.\repo-status.ps1
Write-Host "EXIT=$LASTEXITCODE"
```

Expected: message about missing `gh`, exit code `2`.

Restore PATH by closing the shell (do not leave the session broken).

- [ ] **Step 2: Spec coverage checklist (manual)**

Confirm each row against the live script:

| Spec requirement | Covered by |
|------------------|------------|
| Four fixed sibling folders | `$script:RepoNames` + `Get-AllRepoPaths` |
| MISSING / NOT A GIT REPO continue | `Get-RepoGitStatus` + report |
| `-Fetch` optional | `param([switch]$Fetch)` |
| Fetch failure WARN | `FetchWarned` |
| Dirty / stash / sync / PRs / issues / CI | collectors + report |
| PRs any head branch | `gh pr list --state open` |
| Issues limit 20 | `--limit 20` + `20+` label |
| CI current local branch | `gh run list --branch $Branch` |
| Detached HEAD → CI none | empty Branch → `[]` / `none` |
| Issues/PRs cap `20+` (no totalCount) | `Format-CountLabel` — accepted deviation |
| gh fail → unavailable WARN / not clean | counts `-1` / `unavailable` → IsOpen + IsWarn |
| FetchWarned alone → WARN, exit 0 | `IsWarn` without `IsOpen` |
| Header colors hard/warn/clean | Red / Yellow / Green |
| Exit 0/1/2 | `Assert-Dependencies`, `Get-RepoStatusExitCode` |
| Single file under `sistemas\` | deliverable path |
| No merge/push/stash drop | no such commands in script |
| No `2>&1\|Out-String` on gh success | `Get-GhJsonText` stdout + stderr file |

- [ ] **Step 3: Optional — commit plan only in WhatsApiLebytek (ask user first)**

If the user wants the plan tracked:

```bash
cd /c/Users/User/OneDrive/Desktop/sistemas/WhatsApiLebytek
git add docs/superpowers/plans/2026-07-23-repo-status-script.md
git commit -m "$(cat <<'EOF'
docs: add implementation plan for repo-status.ps1

Personal multi-repo status script plan; deliverable lives under sistemas\, not in this repo.
EOF
)"
```

Do **not** commit `repo-status.ps1` into WhatsApiLebytek.

- [ ] **Step 4: Checkpoint complete**

Script ready for daily use:

```powershell
cd C:\Users\User\OneDrive\Desktop\sistemas
.\repo-status.ps1
```

---

## Self-review

**1. Spec coverage:** All design sections map to tasks — Goal/repos (T2 paths), Invocation/`-Fetch` (T2/T4), per-repo fields (T2–T3), example output (T4), exit codes (T1/T4/T5), error handling (T2–T3), dependencies (T1), non-goals (T5 checklist), implementation notes (single file, `git -C`/`Push-Location`, `gh --json`).

**2. Placeholder scan:** No TBD/TODO; full function bodies and Pester cases included.

**3. Type consistency:** `Get-RepoGitStatus`, `Get-GhJsonText`, `Get-RepoGitHubStatus`, `Get-MergedRepoStatus`, `Test-RepoIsHardOpen`, `Test-RepoIsWarn`, `Test-RepoIsOpen`, `Format-CountLabel`, `Get-RepoStatusExitCode`, `Write-RepoReport`, `Write-Summary` names align across tasks. Unavailable sentinel is `PrCount/IssueCount = -1` and `CiStatus = 'unavailable'`. Cap label uses `20+ open` when count ≥ 20.

**4. Audit fixes applied (2026-07-23):**
- Single `Test-RepoIsOpen` path via HardOpen + gh-unavailable (duplicate draft removed).
- `IsWarn` computed and used for Yellow headers; `FetchWarned` alone does not flip exit to `1`.
- `Get-GhJsonText` captures stdout with `2>$stderrFile` (no success-path `2>&1 | Out-String`).
- `Get-AllRepoPaths` fallback uses `$PSCommandPath`, not function `$MyInvocation`.
- Detached HEAD documented → `CI: none`.
- Spec “total count” → accepted `20+` deviation (no GraphQL).

**Accepted deviation:** Spec “show total count” for issues while listing up to 20 — implemented as displayed count with `20+` when capped (no GraphQL `totalCount`, YAGNI).
