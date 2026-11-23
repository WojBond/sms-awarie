$ErrorActionPreference='Continue'
$php='C:\xampp\php\php.exe'
foreach($p in @('C:\SMS-Awarie\common.php','C:\SMS-Awarie\groups.php','C:\SMS-Awarie\backup.php','C:\SMS-Awarie\receiver.php','C:\SMS-Awarie-Web\php\api.php','C:\SMS-Awarie-Web\php\create-admin.php')){if(Test-Path $p){& $php -l $p}}
foreach($p in @('C:\SMS-Awarie\data\heartbeat.json','C:\SMS-Awarie\data\watchdog-state.json')){if(Test-Path $p){Write-Host "=== $p";Get-Content $p -Raw}}
Get-ScheduledTaskInfo -TaskName 'SMS-Awarie-v3-Watchdog' -ErrorAction SilentlyContinue | Format-List
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {$_.CommandLine -like '*receiver.php*'} | Select-Object ProcessId,CommandLine
