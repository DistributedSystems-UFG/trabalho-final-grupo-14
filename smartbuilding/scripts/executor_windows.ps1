param()

$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

Write-Host "Smart Building IoT - Executor Windows" -ForegroundColor Cyan

if (-not (Test-Path ".env") -and (Test-Path ".env.example")) {
    Write-Host "Arquivo .env nao encontrado. Copiando .env.example..." -ForegroundColor Yellow
    Copy-Item ".env.example" ".env"
    Write-Host "Edite o arquivo .env com os hosts/ports da sua EC2 antes de subir em producao." -ForegroundColor Yellow
}

if (Get-Command py -ErrorAction SilentlyContinue) {
    py start.py
}
elseif (Get-Command python -ErrorAction SilentlyContinue) {
    python start.py
}
else {
    Write-Host "Python nao encontrado. Instale Python 3 e tente novamente." -ForegroundColor Red
    exit 1
}
