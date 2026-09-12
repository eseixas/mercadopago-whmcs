# Deploy Mercado Pago WHMCS via lftp (WSL)
# Uso: .\deploy_ftp.ps1

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Split-Path -Parent $MyInvocation.MyCommand.Path)).Path

$envPath = Join-Path $repoRoot '.env'
if (-not (Test-Path $envPath)) {
    Write-Host "Arquivo .env nao encontrado!" -ForegroundColor Red
    exit 1
}

$envMap = @{}
Get-Content $envPath | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)=(.*)$') {
        $envMap[$matches[1]] = $matches[2].Trim()
    }
}

foreach ($key in @('FTP_HOST', 'FTP_USER', 'FTP_PASS', 'FTP_REMOTE_BASE')) {
    if (-not $envMap.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($envMap[$key])) {
        Write-Host "Variavel $key ausente no .env" -ForegroundColor Red
        exit 1
    }
}

$ftpHost = $envMap['FTP_HOST']
$ftpUser = $envMap['FTP_USER']
$ftpPass = $envMap['FTP_PASS']
$ftpBase = $envMap['FTP_REMOTE_BASE'].TrimEnd('/')

$winPath = ($repoRoot -replace '\\', '/')
$localPath = (wsl -- wslpath -a "$winPath").Trim()
if ([string]::IsNullOrWhiteSpace($localPath)) {
    Write-Host "Falha ao converter caminho para WSL (wslpath)." -ForegroundColor Red
    exit 1
}

Write-Host "Iniciando deploy para $ftpHost ..." -ForegroundColor Cyan

$lftpScript = @"
set ftp:passive-mode on
# Hostinger/Nitmail FTP apresenta certificado TLS com emissor desconhecido.
set ssl:verify-certificate no
set net:timeout 30
set net:max-retries 3
put $localPath/modules/gateways/seixastec_mercadopago.php -o $ftpBase/modules/gateways/seixastec_mercadopago.php
put $localPath/modules/gateways/callback/seixastec_mercadopago.php -o $ftpBase/modules/gateways/callback/seixastec_mercadopago.php
mirror --reverse --verbose --no-perms $localPath/modules/gateways/seixastec_mercadopago $ftpBase/modules/gateways/seixastec_mercadopago
put $localPath/includes/hooks/seixastec_mp_install.php -o $ftpBase/includes/hooks/seixastec_mp_install.php
put $localPath/includes/hooks/seixastec_mp_cleanup.php -o $ftpBase/includes/hooks/seixastec_mp_cleanup.php
put $localPath/includes/hooks/seixastec_mercadopago_pdf.php -o $ftpBase/includes/hooks/seixastec_mercadopago_pdf.php
put $localPath/includes/hooks/seixastec_mercadopago.php -o $ftpBase/includes/hooks/seixastec_mercadopago.php
bye
"@

$scriptLocal = Join-Path $repoRoot '.deploy.lftp'
Set-Content -Path $scriptLocal -Value $lftpScript -Encoding ascii
$scriptWsl = $localPath + '/.deploy.lftp'

try {
    wsl -- bash -lc "lftp -u '${ftpUser},${ftpPass}' '$ftpHost' < '$scriptWsl'"
    if ($LASTEXITCODE -ne 0) {
        throw "lftp exit $LASTEXITCODE"
    }
    Write-Host "Deploy concluido com sucesso!" -ForegroundColor Green
} catch {
    Write-Host "Deploy falhou: $_" -ForegroundColor Red
    exit 1
} finally {
    if (Test-Path $scriptLocal) {
        Remove-Item $scriptLocal -Force
    }
}
