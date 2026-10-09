$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
function New-LocalSecret { return [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)).ToLowerInvariant() }
$infraFile = Join-Path $projectRoot 'infra/.env'
if (-not (Test-Path -LiteralPath $infraFile)) {
    Set-Content -LiteralPath $infraFile -Value "MYSQL_ROOT_PASSWORD=$(New-LocalSecret)`nMYSQL_PASSWORD=$(New-LocalSecret)" -Encoding utf8NoBOM
}
$infraValues = ConvertFrom-StringData (Get-Content -LiteralPath $infraFile -Raw)
if (-not $infraValues.MYSQL_ROOT_PASSWORD -or -not $infraValues.MYSQL_PASSWORD) { throw 'Configure MYSQL_ROOT_PASSWORD e MYSQL_PASSWORD no infra/.env local.' }
$backendFile = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $backendFile)) {
    $content = Get-Content -LiteralPath (Join-Path $projectRoot '.env.example') -Raw
    $content = $content.Replace('DB_PASSWORD=', "DB_PASSWORD=$($infraValues.MYSQL_PASSWORD)").Replace('DEV_USER_PASSWORD=', "DEV_USER_PASSWORD=$(New-LocalSecret)")
    Set-Content -LiteralPath $backendFile -Value $content -Encoding utf8NoBOM
    Push-Location $projectRoot
    try { & (Join-Path $PSScriptRoot 'php.ps1') artisan key:generate; if ($LASTEXITCODE -ne 0) { throw 'Falha ao gerar APP_KEY' } } finally { Pop-Location }
}
$testFile = Join-Path $projectRoot '.env.testing'
if (-not (Test-Path -LiteralPath $testFile)) {
    $backendValues = ConvertFrom-StringData (Get-Content -LiteralPath $backendFile -Raw)
    $content = Get-Content -LiteralPath (Join-Path $projectRoot '.env.testing.example') -Raw
    $content = $content.Replace('DB_PASSWORD=', "DB_PASSWORD=$($infraValues.MYSQL_PASSWORD)").Replace('APP_KEY=', "APP_KEY=$($backendValues.APP_KEY)")
    Set-Content -LiteralPath $testFile -Value $content -Encoding utf8NoBOM
}
Write-Output 'Arquivos locais configurados sem exibir segredos. Arquivos existentes preservados.'
