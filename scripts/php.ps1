$env:PHPRC = Join-Path (Split-Path $PSScriptRoot -Parent) '.tools/php'
if (-not (Test-Path -LiteralPath (Join-Path $env:PHPRC 'php.ini'))) { throw 'Execute scripts/setup-tools.ps1 primeiro (Windows).' }
& php @args
exit $LASTEXITCODE
