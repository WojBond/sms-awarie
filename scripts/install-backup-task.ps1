# SMS-Awarie - rejestruje codzienna kopie SQLite (domyslnie 02:30, retencja 30 dni). Uruchom jako administrator.
# Kopie trafiaja do <aplikacja>\data\backups lub do -Target (zalecany inny dysk / udzial sieciowy).
# XAMPP jest wykrywany automatycznie; inny katalog: -Xampp D:\xampp. -Replace odtwarza istniejace zadanie.
param([string]$Xampp='',[string]$Root='',[string]$Time='02:30',[int]$KeepDays=30,[string]$Target='',[switch]$Replace,[switch]$Apply)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '_xampp.ps1')
$Xampp=Find-Xampp $Xampp
if(-not $Root){$Root=Join-Path $Xampp 'htdocs\sms-awarie'}
$Php=Join-Path $Xampp 'php\php.exe'
$name='SMS-Awarie-v3-Backup'
$script=Join-Path $Root 'backup.php'
if(!(Test-Path $script)){throw "Missing $script - run install.ps1 -Apply first"}
$old=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
if($old -and -not $Replace){throw "Task already exists: $name. Use -Replace to recreate it."}
$taskArgs='"'+$script+'" '+$KeepDays; if($Target){$taskArgs+=' "'+$Target+'"'}
Write-Host "XAMPP: $Xampp; task: $name, daily at $Time, keep $KeepDays days, target: $(if($Target){$Target}else{Join-Path $Root 'data\backups'})"
if(-not $Apply){Write-Host 'DRY RUN. Use -Apply to register.';exit}
if($old){Unregister-ScheduledTask -TaskName $name -Confirm:$false; Write-Host "Removed old task $name."}
$action=New-ScheduledTaskAction -Execute $Php -Argument $taskArgs -WorkingDirectory $Root
$trigger=New-ScheduledTaskTrigger -Daily -At $Time
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -StartWhenAvailable
$user=Read-Host 'Service account (DOMAIN\user or COMPUTER\user)'
if([string]::IsNullOrWhiteSpace($user)){throw 'Service account required'}
$cred=Get-Credential -UserName $user -Message 'Credentials for backup task'
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -User $cred.UserName -Password $cred.GetNetworkCredential().Password -Description "SMS-Awarie daily SQLite backup ($Root)" | Out-Null
Write-Host "Created $name. Test now: Start-ScheduledTask -TaskName $name"
