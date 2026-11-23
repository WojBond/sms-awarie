# Windows PowerShell 5.1, elevated. Run in a maintenance window only.
param([switch]$Apply)
$ErrorActionPreference='Stop'
$release=Split-Path $PSScriptRoot -Parent
$backup=Join-Path 'C:\SMS-Awarie-Backups' (Get-Date -Format 'yyyyMMdd-HHmmss')
$mapping=@(@('payload\C-SMS-Awarie','C:\SMS-Awarie'),@('payload\C-SMS-Awarie-Web','C:\SMS-Awarie-Web'))
Write-Host "Release: $release"
Write-Host "Backup: $backup"
if(-not $Apply){Write-Host 'DRY RUN. Nothing changed. Use -Apply only in maintenance window.';exit}
if((Test-Path 'C:\SMS-Awarie\receiver.php') -and -not (Test-Path 'C:\SMS-Awarie\data\watchdog.pause')){throw 'Existing installation: create watchdog.pause after disabling watchdog before installing'}
$running=@(Get-CimInstance Win32_Process | Where-Object {$_.Name -in @('php.exe','php-cgi.exe') -and $_.CommandLine -like '*SMS-Awarie\receiver.php*'})
if($running.Count){throw 'receiver.php is running. Stop it safely in the maintenance window.'}
New-Item -ItemType Directory -Force $backup | Out-Null
foreach($m in $mapping){$from=Join-Path $release $m[0];$to=$m[1];Get-ChildItem $from -Recurse -File | ForEach-Object {
 $relative=$_.FullName.Substring($from.Length).TrimStart('\');$target=Join-Path $to $relative
 if($relative -match '(^|\\)(data|logs|node_modules|\.next)(\\|$)' -or $relative -match '(^|\\)(config\.json|awarie\.sqlite|package-lock\.json)$'){return}
 if(Test-Path $target){$dest=Join-Path (Join-Path $backup ($to -replace '[:\\]','_')) $relative;New-Item -ItemType Directory -Force (Split-Path $dest) | Out-Null;Copy-Item $target $dest -Force}
 New-Item -ItemType Directory -Force (Split-Path $target) | Out-Null;Copy-Item $_.FullName $target -Force
}}
Write-Host "Files deployed. Previous versions saved to $backup. Keep database/config backups separately."
