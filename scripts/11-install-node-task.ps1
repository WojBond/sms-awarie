# Windows PowerShell 5.1 elevated; creates scheduled task, does not stop running app.
param([string]$TaskName='SMS-Awarie-v3-Web')
$ErrorActionPreference='Stop'
if(Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue){throw "Task exists: $TaskName"}
if(!(Test-Path 'C:\SMS-Awarie-Web\web\.next\BUILD_ID')){throw 'Build frontend first (npm.cmd ci; npm.cmd run build)'}
$npm=(Get-Command npm.cmd -ErrorAction Stop).Source
$launcher='C:\SMS-Awarie-Web\start-web.cmd'
$script="@echo off`r`ncd /d C:\SMS-Awarie-Web\web`r`n`"$npm`" run start -- -H 127.0.0.1 -p 3000`r`n"
Set-Content -LiteralPath $launcher -Value $script -Encoding ASCII
$user=Read-Host 'Service account (COMPUTER\user or DOMAIN\user)'
$cred=Get-Credential -UserName $user -Message 'Credentials for web task'
$action=New-ScheduledTaskAction -Execute 'cmd.exe' -Argument '/c "C:\SMS-Awarie-Web\start-web.cmd"' -WorkingDirectory 'C:\SMS-Awarie-Web\web'
$trigger=New-ScheduledTaskTrigger -AtStartup
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -User $cred.UserName -Password $cred.GetNetworkCredential().Password -Description 'SMS Awarie Next.js frontend' | Out-Null
Write-Host 'Task installed but not started. Start-ScheduledTask when ready.'
