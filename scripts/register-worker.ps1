$ErrorActionPreference = 'Stop'
$taskName = 'MATTE PHP payment and notification worker'
if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    Write-Output 'The MATTE worker task already exists; it was not overwritten.'
    exit 0
}
$workerScript = Join-Path $PSScriptRoot 'worker.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $workerScript + '"')
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable -Hidden
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description 'Reconciles enabled MATTE MoMo attempts, processes enabled email jobs, and expires rate-limit buckets. Runs while the local user is signed in.' | Select-Object TaskName,State
