# SMS-Awarie 4.x - kontrola instalacji (tylko odczyt).
param([string]$Xampp='',[string]$Root='')
$ErrorActionPreference='Continue'
. (Join-Path $PSScriptRoot '_xampp.ps1')
$Xampp=Find-Xampp $Xampp
if(-not $Root){$Root=Join-Path $Xampp 'htdocs\sms-awarie'}
Write-Host "XAMPP: $Xampp; aplikacja: $Root"
$php=Join-Path $Xampp 'php\php.exe'
Write-Host '== PHP lint'
Get-ChildItem $Root -Recurse -Filter *.php | Where-Object {$_.FullName -notmatch '\\data\\'} | ForEach-Object {$r=& $php -l $_.FullName 2>&1; if($LASTEXITCODE -ne 0){Write-Host $r -ForegroundColor Red}}
Write-Host 'PHP lint zakonczony.'
Write-Host '== Wersja'; & $php -r "require '$($Root -replace '\\','/')/config.php'; echo APP_VERSION,PHP_EOL;"
Write-Host '== Apache'; & (Join-Path $Xampp 'apache\bin\httpd.exe') -t
Write-Host '== Zadania'; Get-ScheduledTask | Where-Object {$_.TaskName -like '*SMS-Awarie*'} | Select-Object TaskName,State | Format-Table
Write-Host '== Odbiornik'; Get-CimInstance Win32_Process | Where-Object {$_.CommandLine -like '*SMS-Awarie\receiver.php*' -and $_.Name -eq 'php.exe'} | Select-Object ProcessId,CommandLine | Format-List
Write-Host '== Heartbeat'; $hb=Join-Path $Root 'data\heartbeat.json'; if(Test-Path $hb){Get-Content $hb -Raw}else{Write-Host 'brak'}
Write-Host '== Baza danych'; & $php (Join-Path $Root 'cli\db.php') status
Write-Host '== Panel'; try{$r=Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost/sms-awarie/' -TimeoutSec 10; Write-Host "HTTP $($r.StatusCode) - panel odpowiada"}catch{Write-Host "Panel nie odpowiada: $($_.Exception.Message)" -ForegroundColor Red}
Write-Host '== Ochrona plikow (oczekiwane 403/404)'
foreach($u in @('data/config.json','data/awarie.sqlite','common.php','logs/receiver-stdout.log')){
  try{$r=Invoke-WebRequest -UseBasicParsing -Uri "http://localhost/sms-awarie/$u" -TimeoutSec 10; Write-Host "$u : HTTP $($r.StatusCode) - DOSTEPNY Z SIECI! Sprawdz AllowOverride All dla htdocs w httpd.conf" -ForegroundColor Red}
  catch{$code=$_.Exception.Response.StatusCode.value__; Write-Host "$u : HTTP $code - zablokowany"}
}
