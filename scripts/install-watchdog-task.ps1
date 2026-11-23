# Run elevated ONLY during maintenance window, after disabling old watchdog tasks.
# Does not start or stop receiver.php.
$ErrorActionPreference='Stop'
$name='SMS-Awarie-v3-Watchdog'
if(Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue){throw "Task already exists: $name. Inspect before changing."}
$script='C:\SMS-Awarie\watchdog.ps1'
if(!(Test-Path $script)){throw "Missing $script"}
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$script+'"') -WorkingDirectory 'C:\SMS-Awarie'
$trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3) -StartWhenAvailable
# Explicit account required; no hardcoded credentials.
$user=Read-Host 'Service account (DOMAIN\user or COMPUTER\user)'
if([string]::IsNullOrWhiteSpace($user)){throw 'Service account required'}
$cred=Get-Credential -UserName $user -Message 'Credentials for scheduled task'
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -User $cred.UserName -Password $cred.GetNetworkCredential().Password -Description 'SMS-Awarie v3 Watchdog' | Out-Null
Write-Host "Created $name. Review task security and run manually only when ready."
