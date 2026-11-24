# SMS-Awarie 4.x - odczyt stanu przed instalacja/aktualizacja. Niczego nie zmienia.
param([string]$Root='C:\xampp\htdocs\sms-awarie',[string]$Xampp='C:\xampp')
$ErrorActionPreference='Continue'
foreach($p in @($Root,'C:\SMS-Awarie','C:\SMS-Awarie-Web',(Join-Path $Xampp 'php\php.exe'),(Join-Path $Xampp 'apache\bin\httpd.exe'))){Write-Host "$p : $(Test-Path $p)"}
$cfg=Join-Path $Root 'config.php'; if(Test-Path $cfg){Write-Host "Zainstalowana wersja: $((Select-String -Path $cfg -Pattern "APP_VERSION', '([^']+)'").Matches.Groups[1].Value)"}elseif(Test-Path (Join-Path $Root 'common.php')){Write-Host 'Zainstalowana wersja: 3.x'}
Get-ScheduledTask | Where-Object {$_.TaskName -like '*SMS*'} | Select-Object TaskName,State | Format-Table
Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='php-cgi.exe' OR Name='node.exe'" | Where-Object {$_.CommandLine -like '*receiver.php*' -or $_.CommandLine -like '*SMS-Awarie-Web*'} | Select-Object ProcessId,Name,CommandLine | Format-List
foreach($f in @('data\heartbeat.json','data\watchdog-state.json')){$x=Join-Path $Root $f;if(Test-Path $x){Write-Host $x;Get-Content $x -Raw}}
