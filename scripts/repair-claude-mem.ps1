# repair-claude-mem.ps1
# Repara claude-mem en Windows cuando Claude Code/Cursor se cuelgan por hooks fallidos.
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File scripts/repair-claude-mem.ps1
#
# Después: Developer: Reload Window en Cursor + reiniciar sesión de Claude Code.

$ErrorActionPreference = "Continue"

$pluginRoot = Join-Path $env:USERPROFILE ".claude\plugins\marketplaces\thedotmack\plugin"
$dataDir = Join-Path $env:USERPROFILE ".claude-mem"
$workerPort = "37777"

function Write-Step([string]$Message) {
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Test-WorkerHealth([string]$Port) {
    try {
        $health = curl.exe -s -m 5 "http://127.0.0.1:$Port/api/health" 2>$null
        $ready = curl.exe -s -m 5 "http://127.0.0.1:$Port/api/readiness" 2>$null
        if ($health -and $ready) {
            Write-Host "health:  $health"
            Write-Host "ready:   $ready"
            return $true
        }
    } catch {}
    return $false
}

Write-Step "Matando procesos zombie de claude-mem (node/bun)"

Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'claude-mem|mcp-server\.cjs' } |
    ForEach-Object {
        Write-Host "  kill node PID $($_.ProcessId)"
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

Get-CimInstance Win32_Process -Filter "Name='bun.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'worker-service|claude-mem' } |
    ForEach-Object {
        Write-Host "  kill bun PID $($_.ProcessId)"
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

Write-Step "Limpiando estado local"
Remove-Item (Join-Path $dataDir "worker.pid") -Force -ErrorAction SilentlyContinue
'{"consecutiveFailures":0,"lastFailureAt":0}' |
    Set-Content (Join-Path $dataDir "state\hook-failures.json") -Encoding UTF8

Write-Step "Ajustando puerto del worker (evita puerto fantasma 37803)"
$settingsPath = Join-Path $dataDir "settings.json"
if (Test-Path $settingsPath) {
    $raw = Get-Content $settingsPath -Raw
    $updated = $raw -replace '"CLAUDE_MEM_WORKER_PORT"\s*:\s*"37803"', "`"CLAUDE_MEM_WORKER_PORT`": `"$workerPort`""
    if ($updated -ne $raw) {
        Set-Content $settingsPath $updated -Encoding UTF8
        Write-Host "  CLAUDE_MEM_WORKER_PORT -> $workerPort"
    } else {
        Write-Host "  settings.json ya usa puerto distinto de 37803"
    }
} else {
    Write-Host "  WARN: no existe $settingsPath"
}

if (-not (Test-Path (Join-Path $pluginRoot "scripts\worker-cli.js"))) {
    Write-Host "`nERROR: claude-mem no encontrado en $pluginRoot" -ForegroundColor Red
    Write-Host "Instala/actualiza el plugin claude-mem en Claude Code primero."
    exit 1
}

Write-Step "Reiniciando worker"
bun (Join-Path $pluginRoot "scripts\worker-cli.js") stop 2>$null | Out-Null
Start-Sleep -Seconds 2
$startResult = bun (Join-Path $pluginRoot "scripts\worker-cli.js") start 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host $startResult -ForegroundColor Red
    Write-Host "`nSi falla por puerto en uso, reinicia Windows para limpiar sockets fantasma." -ForegroundColor Yellow
    exit 1
}

Start-Sleep -Seconds 3

Write-Step "Verificando salud en puerto $workerPort"
if (Test-WorkerHealth $workerPort) {
    Write-Host "`nOK: claude-mem worker activo." -ForegroundColor Green
    Write-Host "Siguiente: Reload Window en Cursor + reiniciar Claude Code."
    exit 0
}

Write-Host "`nWARN: worker arrancó pero health check no respondió." -ForegroundColor Yellow
Write-Host "Revisa logs en $dataDir\logs\"
netstat -ano | findstr $workerPort
exit 1
