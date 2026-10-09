$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$toolsDirectory = Join-Path $projectRoot '.tools'
New-Item -ItemType Directory -Path $toolsDirectory -Force | Out-Null
$archivePath = Join-Path $toolsDirectory 'node-v24.21.0-win-x64.zip'
Invoke-WebRequest -Uri 'https://nodejs.org/dist/v24.21.0/node-v24.21.0-win-x64.zip' -OutFile $archivePath
if ((Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash -ine '158f7685b44de51f6c0df1d153526cbcd3e1bc739a8dfc607721cef75de9e541') { throw 'Checksum Node inválido.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath $toolsDirectory -Force
& (Join-Path $toolsDirectory 'node-v24.21.0-win-x64/node.exe') --version
