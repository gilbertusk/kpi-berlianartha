# Windows wrapper for scripts/dev.sh. Uses Git Bash explicitly so that the
# WSL "bash.exe" in System32 is never picked up by accident.
# Usage: .\scripts\dev.ps1 <command> [args]
$ErrorActionPreference = 'Stop'

$git = Get-Command git -ErrorAction SilentlyContinue
if ($null -eq $git) {
    throw 'Git for Windows tidak ditemukan. Instal Git for Windows (menyertakan Git Bash).'
}

$gitRoot = Split-Path (Split-Path $git.Source -Parent) -Parent
$bash = Join-Path $gitRoot 'bin\bash.exe'
if (-not (Test-Path $bash)) {
    throw "Git Bash tidak ditemukan di $bash."
}

& $bash (Join-Path $PSScriptRoot 'dev.sh') @args
exit $LASTEXITCODE
