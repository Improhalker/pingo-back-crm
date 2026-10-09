$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$toolsDirectory = Join-Path $projectRoot '.tools'
$phpDirectory = Join-Path $toolsDirectory 'php'
New-Item -ItemType Directory -Force -Path $phpDirectory | Out-Null
$phpCommand = (Get-Command php -ErrorAction Stop).Source
$originalIni = & $phpCommand -r 'echo php_ini_loaded_file();'
if (-not $originalIni) { throw 'PHP precisa de um php.ini válido antes da configuração local.' }
$iniText = Get-Content -LiteralPath $originalIni -Raw
$extensionDirectory = Join-Path (Split-Path $phpCommand -Parent) 'ext'
$iniText += "`nextension_dir=`"$extensionDirectory`"`n"
foreach ($extension in @('pdo_mysql', 'curl', 'zip')) {
    if (-not (Test-Path -LiteralPath (Join-Path $extensionDirectory "php_$extension.dll"))) { throw "Extensão ausente: $extension" }
    if ((& $phpCommand -r "echo extension_loaded('$extension') ? 'yes' : 'no';") -eq 'no') { $iniText += "`nextension=$extension`n" }
}
$certificate = Join-Path $toolsDirectory 'cacert.pem'
Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile $certificate
$iniText += "`ncurl.cainfo=`"$certificate`"`nopenssl.cafile=`"$certificate`"`n"
Set-Content -LiteralPath (Join-Path $phpDirectory 'php.ini') -Value $iniText -Encoding utf8NoBOM
$installer = Join-Path $toolsDirectory 'composer-setup.php'
Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $installer
$signatureContent = (Invoke-WebRequest -Uri 'https://composer.github.io/installer.sig').Content
if ($signatureContent -is [byte[]]) { $signatureContent = [Text.Encoding]::UTF8.GetString($signatureContent) }
$expectedHash = ([string]$signatureContent).Trim()
$actualHash = (Get-FileHash -LiteralPath $installer -Algorithm SHA384).Hash
if ($actualHash -ine $expectedHash) { throw 'Checksum do instalador Composer não corresponde; instalação cancelada.' }
$env:PHPRC = $phpDirectory
& $phpCommand $installer "--install-dir=$toolsDirectory" '--filename=composer.phar' '--version=2.10.3'
if ($LASTEXITCODE -ne 0) { throw 'Falha ao instalar Composer.' }
& $phpCommand (Join-Path $toolsDirectory 'composer.phar') --version
