$ErrorActionPreference='Continue'
Write-Host 'PHP lint:'
foreach($p in @('C:\SMS-Awarie\common.php','C:\SMS-Awarie\groups.php','C:\SMS-Awarie\backup.php','C:\SMS-Awarie\receiver.php','C:\SMS-Awarie-Web\php\api.php')){& 'C:\xampp\php\php.exe' -l $p}
Write-Host 'Apache syntax:';& 'C:\xampp\apache\bin\httpd.exe' -t
Write-Host 'Scheduled tasks:';Get-ScheduledTask | Where-Object {$_.TaskName -like '*SMS-Awarie*'} | Select-Object TaskName,State
Write-Host 'Receiver:';Get-CimInstance Win32_Process | Where-Object {$_.CommandLine -like '*SMS-Awarie\receiver.php*' -and $_.Name -eq 'php.exe'} | Select-Object ProcessId,CommandLine
Write-Host 'Heartbeat:';if(Test-Path 'C:\SMS-Awarie\data\heartbeat.json'){Get-Content 'C:\SMS-Awarie\data\heartbeat.json' -Raw}
Write-Host 'SQLite integrity (read-only connection):'; & 'C:\xampp\php\php.exe' -r '$p=new PDO("sqlite:file:C:/SMS-Awarie/data/awarie.sqlite?mode=ro"); echo $p->query("PRAGMA integrity_check")->fetchColumn(),PHP_EOL;'
