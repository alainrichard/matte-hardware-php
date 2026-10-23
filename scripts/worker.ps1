$ErrorActionPreference = 'Stop'
$appRoot = Split-Path $PSScriptRoot -Parent
$phpBinary = 'C:\xampp\php\php.exe'
$workerLog = Join-Path $appRoot 'storage\worker.log'
if ((Test-Path -LiteralPath $workerLog) -and (Get-Item -LiteralPath $workerLog).Length -gt 1048576) {
    Move-Item -LiteralPath $workerLog -Destination ($workerLog + '.1') -Force
}
& $phpBinary (Join-Path $PSScriptRoot 'console.php') worker *>> $workerLog
exit $LASTEXITCODE
