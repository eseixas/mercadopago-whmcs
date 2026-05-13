# Deploy Mercado Pago WHMCS via lftp (WSL)
# Uso: .\deploy_ftp.ps1

$envFile = Get-Content ".env" -ErrorAction SilentlyContinue
if (!$envFile) {
    Write-Host "Arquivo .env nao encontrado!" -ForegroundColor Red
    exit 1
}

$ftpHost = ($envFile | Select-String "FTP_HOST=").ToString().Split("=")[1].Trim()
$ftpUser = ($envFile | Select-String "FTP_USER=").ToString().Split("=")[1].Trim()
$ftpPass = ($envFile | Select-String "FTP_PASS=").ToString().Split("=")[1].Trim()
$ftpBase = ($envFile | Select-String "FTP_REMOTE_BASE=").ToString().Split("=")[1].Trim()

$localPath = "/c/Temp/code/mercadopago-whmcs"

Write-Host "Iniciando deploy para $ftpHost..." -ForegroundColor Cyan

wsl bash -c @"
lftp -u '$ftpUser,$ftpPass' $ftpHost << 'EOF'
set ftp:passive-mode on
set ssl:verify-certificate no
set net:timeout 30
set net:max-retries 3

# 1. Main Gateway File
put $localPath/modules/gateways/seixastec_mercadopago.php -o $ftpBase/modules/gateways/seixastec_mercadopago.php

# 2. Callback File
put $localPath/modules/gateways/callback/seixastec_mercadopago.php -o $ftpBase/modules/gateways/callback/seixastec_mercadopago.php

# 3. Gateway Directory (Templates, API, etc)
mirror --reverse --verbose --no-perms $localPath/modules/gateways/seixastec_mercadopago $ftpBase/modules/gateways/seixastec_mercadopago

# 4. Hooks
put $localPath/includes/hooks/seixastec_mp_install.php -o $ftpBase/includes/hooks/seixastec_mp_install.php
put $localPath/includes/hooks/seixastec_mp_cleanup.php -o $ftpBase/includes/hooks/seixastec_mp_cleanup.php
put $localPath/includes/hooks/seixastec_mercadopago_pdf.php -o $ftpBase/includes/hooks/seixastec_mercadopago_pdf.php
put $localPath/includes/hooks/seixastec_mercadopago.php -o $ftpBase/includes/hooks/seixastec_mercadopago.php

bye
EOF
"@

if ($LASTEXITCODE -eq 0) {
    Write-Host "Deploy concluido com sucesso!" -ForegroundColor Green
} else {
    Write-Host "Deploy falhou (exit $LASTEXITCODE)" -ForegroundColor Red
}
