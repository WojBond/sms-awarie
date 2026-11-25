# SMS-Awarie 4.x - instalator do folderu stron XAMPP: <XAMPP>\htdocs\sms-awarie
# XAMPP moze lezec na dowolnym dysku - jest wykrywany automatycznie (albo podaj -Xampp D:\xampp).
# Windows PowerShell 5.1, uruchom jako administrator z rozpakowanego katalogu paczki:
#   .\scripts\install.ps1            (podglad - niczego nie zmienia)
#   .\scripts\install.ps1 -Apply     (instalacja albo aktualizacja)
# Nie nadpisuje danych (data\awarie.sqlite, data\config.json) ani logow. Nie zmienia httpd.conf.
param([string]$Xampp='',[string]$Root='',[string]$BackupRoot='',[switch]$Apply)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '_xampp.ps1')
$Xampp=Find-Xampp $Xampp
if(-not $Root){$Root=Join-Path $Xampp 'htdocs\sms-awarie'}
if(-not $BackupRoot){$BackupRoot=Join-Path (Split-Path $Xampp -Qualifier) 'SMS-Awarie-Backups'}
$release=Split-Path $PSScriptRoot -Parent
$src=Join-Path $release 'htdocs\sms-awarie'
$php=Join-Path $Xampp 'php\php.exe'
$httpdConf=Join-Path $Xampp 'apache\conf\httpd.conf'
$version=(Select-String -Path (Join-Path $src 'config.php') -Pattern "APP_VERSION', '([^']+)'").Matches.Groups[1].Value

function Step($t){Write-Host "`n== $t" -ForegroundColor Cyan}
function Warn($t){Write-Host "UWAGA: $t" -ForegroundColor Yellow}

Step "SMS-Awarie $version"
Write-Host "XAMPP:     $Xampp"
Write-Host "Aplikacja: $Root"
if(!(Test-Path $src)){throw "Brak $src - uruchom skrypt z rozpakowanej paczki."}
$mods=(& $php -m) -join ' '
foreach($ext in @('pdo_sqlite','curl','SimpleXML')){if($mods -notmatch "\b$ext\b"){throw "PHP nie ma rozszerzenia $ext - wlacz je w $Xampp\php\php.ini"}}
Write-Host "PHP $(& $php -r 'echo PHP_VERSION;') z pdo_sqlite, curl, SimpleXML - OK"

# .htaccess chroni katalog data tylko przy AllowOverride All dla htdocs (domyslne w XAMPP)
$conf=if(Test-Path $httpdConf){Get-Content $httpdConf -Raw}else{''}
$block=[regex]::Match($conf,'(?is)<Directory\s+"?[^">]*/htdocs"?\s*>(.*?)</Directory>')
if(-not ($block.Success -and $block.Groups[1].Value -match '(?im)^\s*AllowOverride\s+All')){
  Warn "W $httpdConf nie znaleziono 'AllowOverride All' dla htdocs. Bez tego .htaccess nie chroni bazy i config.json (haslo modemu). Ustaw AllowOverride All i zrestartuj Apache."
}

$existing=Test-Path (Join-Path $Root 'receiver.php')
if($existing){
  Write-Host "Aktualizacja istniejacej instalacji (dane w data\ zostaja)."
  $receivers=@(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {$_.CommandLine -like "*$Root\receiver.php*"})
  if($receivers.Count){throw "Dziala odbiornik (PID $($receivers.ProcessId -join ',')). Najpierw w panelu: Ustawienia > Uslugi > Zatrzymaj wszystko (wersje starsze niz 4.4: New-Item '$Root\data\watchdog.pause' -ItemType File; potem Stop-Process -Id <PID>). Uruchom instalator ponownie."}
}elseif(Test-Path $Root){
  Warn "Katalog $Root istnieje, ale nie zawiera aplikacji - pliki zostana do niego dograne."
}else{
  Write-Host 'Nowa instalacja.'
}

if(-not $Apply){Write-Host "`nPODGLAD - niczego nie zmieniono. Uruchom z -Apply." -ForegroundColor Green; exit 0}

if($existing){
  Step 'Kopia zapasowa'
  $bk=Join-Path $BackupRoot ("pre-$version-"+(Get-Date -Format 'yyyyMMdd-HHmmss'))
  New-Item -ItemType Directory -Force $bk | Out-Null
  robocopy $Root (Join-Path $bk 'app') /E /XD (Join-Path $Root 'data') (Join-Path $Root 'logs') /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw "robocopy (kopia) zakonczyl sie bledem $LASTEXITCODE"}
  $db=Join-Path $Root 'data\awarie.sqlite'
  if(Test-Path $db){
    & $php (Join-Path $PSScriptRoot 'snapshot-sqlite.php') $db (Join-Path $bk 'awarie.sqlite')
    if($LASTEXITCODE -ne 0){throw 'Kopia bazy nie powiodla sie - przerwano przed zmianami.'}
    Copy-Item (Join-Path $Root 'data\config.json') $bk -ErrorAction SilentlyContinue
  }
  Write-Host "Kopia: $bk"
}

Step 'Pliki aplikacji'
foreach($d in @('','data','logs','data\backups')){New-Item -ItemType Directory -Force (Join-Path $Root $d) | Out-Null}
foreach($d in @('data','logs')){Copy-Item (Join-Path $src "$d\.htaccess") (Join-Path $Root "$d\.htaccess") -Force}
robocopy $src $Root /E /XD (Join-Path $src 'data') (Join-Path $src 'logs') /XF config.json /NFL /NDL /NJH /NJS /NP | Out-Null
if($LASTEXITCODE -ge 8){throw "robocopy zakonczyl sie bledem $LASTEXITCODE"}
Write-Host "Skopiowano do $Root"

Step 'Baza danych'
& $php (Join-Path $Root 'cli\init.php')
if($LASTEXITCODE -ne 0){throw 'Inicjalizacja bazy nie powiodla sie.'}

Step 'Gotowe'
Write-Host 'Panel: http://localhost/sms-awarie/  (z sieci: http://<adres-serwera>/sms-awarie/)'
Write-Host 'Brak kont? Pierwszego administratora utworzysz w przegladarce. Haslo modemu ustaw w: Ustawienia i modem.'
if(!(Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Watchdog' -ErrorAction SilentlyContinue)){Write-Host 'Zadanie Watchdoga:  .\scripts\install-watchdog-task.ps1'}
if(!(Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Backup' -ErrorAction SilentlyContinue)){Write-Host 'Zadanie backupu:    .\scripts\install-backup-task.ps1 -Apply'}
$pause=Join-Path $Root 'data\watchdog.pause'
if(Test-Path $pause){Write-Host "Po sprawdzeniu panelu usun pauze Watchdoga: Remove-Item '$pause'"}
Write-Host 'Kontrola (m.in. czy data\ jest zablokowane z sieci): .\scripts\verify.ps1'
if(-not (Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue)){Warn 'Apache nie dziala jako usluga Windows - panel i Watchdog beda dzialac tylko po recznym starcie XAMPP. Zalecane: XAMPP Control Panel -> Svc przy Apache.'}
