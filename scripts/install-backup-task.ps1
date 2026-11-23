# SMS-Awarie. Windows PowerShell 5.1 elevated. Rejestruje codzienna kopie SQLite (domyslnie 02:30, retencja 30 dni).
# Kopie trafiaja do C:\SMS-Awarie\data\backups lub do -Target (zalecany inny dysk / udzial sieciowy).
param([string]$Time='02:30',[int]$KeepDays=30,[string]$Target='',[switch]$Apply)
$ErrorActionPreference='Stop'
$name='SMS-Awarie-v3-Backup'
$php='C:\xampp\php\php.exe';$script='C:\SMS-Awarie\backup.php'
if(!(Test-Path $php)){throw "Missing $php"}
if(!(Test-Path $script)){throw "Missing $script - run install.ps1 -Apply first"}
if(Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue){throw "Task already exists: $name. Inspect before changing."}
$taskArgs='"'+$script+'" '+$KeepDays; if($Target){$taskArgs+=' "'+$Target+'"'}
Write-Host "Task: $name, daily at $Time, keep $KeepDays days, target: $(if($Target){$Target}else{'C:\SMS-Awarie\data\backups'})"
if(-not $Apply){Write-Host 'DRY RUN. Use -Apply to register.';exit}
$action=New-ScheduledTaskAction -Execute $php -Argument $taskArgs -WorkingDirectory 'C:\SMS-Awarie'
$trigger=New-ScheduledTaskTrigger -Daily -At $Time
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -StartWhenAvailable
$user=Read-Host 'Service account (DOMAIN\user or COMPUTER\user)'
if([string]::IsNullOrWhiteSpace($user)){throw 'Service account required'}
$cred=Get-Credential -UserName $user -Message 'Credentials for backup task'
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -User $cred.UserName -Password $cred.GetNetworkCredential().Password -Description 'SMS-Awarie daily SQLite backup' | Out-Null
Write-Host "Created $name. Test now: Start-ScheduledTask -TaskName $name"
