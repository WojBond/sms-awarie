# PowerShell 5.1, no changes to production
$ErrorActionPreference='Stop'
$paths=@('C:\SMS-Awarie','C:\SMS-Awarie-Web','C:\xampp\php\php.exe')
foreach($p in $paths){Write-Host "$p : $(Test-Path $p)"}
Get-ScheduledTask | Where-Object {$_.TaskName -like '*SMS*'} | Select-Object TaskName,State | Format-Table
Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='php-cgi.exe'" | Where-Object {$_.CommandLine -like '*receiver.php*'} | Select-Object ProcessId,CommandLine | Format-List
foreach($f in @('C:\SMS-Awarie\data\heartbeat.json','C:\SMS-Awarie\data\watchdog-state.json')){if(Test-Path $f){Write-Host "$f";Get-Content $f -Raw}}
