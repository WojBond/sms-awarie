# SMS-Awarie - rejestruje zadanie Watchdoga (co minute pilnuje odbiornika receiver.php).
# Uruchom jako administrator. Nie uruchamia ani nie zatrzymuje odbiornika.
# XAMPP jest wykrywany automatycznie; inny katalog: -Xampp D:\xampp. -Replace odtwarza istniejace zadanie.
param([string]$Xampp='',[string]$Root='',[switch]$Replace)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '_xampp.ps1')
$Xampp=Find-Xampp $Xampp
if(-not $Root){$Root=Join-Path $Xampp 'htdocs\sms-awarie'}
$Php=Join-Path $Xampp 'php\php.exe'
$name='SMS-Awarie-v3-Watchdog'
$script=Join-Path $Root 'watchdog.ps1'
if(!(Test-Path $script)){throw "Missing $script - run install.ps1 -Apply first"}
Write-Host "XAMPP: $Xampp; aplikacja: $Root"
$old=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
if($old){
  if(-not $Replace){throw "Task already exists: $name ($($old.Actions[0].Arguments)). Use -Replace to recreate it."}
  Unregister-ScheduledTask -TaskName $name -Confirm:$false
  Write-Host "Removed old task $name."
}
$taskArgs='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$script+'" -Root "'+$Root+'" -Php "'+$Php+'"'
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $taskArgs -WorkingDirectory $Root
$trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3) -StartWhenAvailable
# Explicit account required; no hardcoded credentials.
$user=Read-Host 'Service account (DOMAIN\user or COMPUTER\user)'
if([string]::IsNullOrWhiteSpace($user)){throw 'Service account required'}
$cred=Get-Credential -UserName $user -Message 'Credentials for scheduled task'
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -User $cred.UserName -Password $cred.GetNetworkCredential().Password -Description "SMS-Awarie Watchdog ($Root)" | Out-Null
Write-Host "Created $name. It starts within 2 minutes unless data\watchdog.pause exists."
