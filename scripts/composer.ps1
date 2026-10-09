$projectRoot = Split-Path $PSScriptRoot -Parent
$env:PHPRC = Join-Path $projectRoot '.tools/php'
& php (Join-Path $projectRoot '.tools/composer.phar') @args
exit $LASTEXITCODE
