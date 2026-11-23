# SMS-Awarie 4.x - instalator: nowa instalacja oraz aktualizacja z 3.x / 4.x.
# Windows PowerShell 5.1, uruchom jako administrator z rozpakowanego katalogu paczki:
#   .\scripts\install.ps1            (podglad - niczego nie zmienia)
#   .\scripts\install.ps1 -Apply     (instalacja)
# Nie nadpisuje danych (data\awarie.sqlite, data\config.json) ani logow.
param([string]$Root='C:\SMS-Awarie',[string]$Xampp='C:\xampp',[string]$BackupRoot='C:\SMS-Awarie-Backups',[switch]$Apply)
$ErrorActionPreference='Stop'
$release=Split-Path $PSScriptRoot -Parent
$src=Join-Path $release 'app'
$php=Join-Path $Xampp 'php\php.exe'
$httpd=Join-Path $Xampp 'apache\bin\httpd.exe'
$httpdConf=Join-Path $Xampp 'apache\conf\httpd.conf'
$rootFwd=$Root -replace '\\','/'
$include="Include `"$rootFwd/apache-sms-awarie.conf`""
$version=(Select-String -Path (Join-Path $src 'config.php') -Pattern "APP_VERSION', '([^']+)'").Matches.Groups[1].Value

function Step($t){Write-Host "`n== $t" -ForegroundColor Cyan}
function Warn($t){Write-Host "UWAGA: $t" -ForegroundColor Yellow}

Step "SMS-Awarie $version -> $Root"
foreach($f in @($src,$php,$httpd,$httpdConf)){if(!(Test-Path $f)){throw "Brak: $f (sprawdz -Xampp i czy uruchamiasz skrypt z rozpakowanej paczki)"}}
$mods=(& $php -m) -join ' '
foreach($ext in @('pdo_sqlite','curl','SimpleXML')){if($mods -notmatch "\b$ext\b"){throw "PHP nie ma rozszerzenia $ext - wlacz je w $Xampp\php\php.ini"}}
Write-Host "PHP: $(& $php -r 'echo PHP_VERSION;') z pdo_sqlite, curl, SimpleXML - OK"

# Stan obecnej instalacji
$existing=Test-Path (Join-Path $Root 'receiver.php')
$receiver=@(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {$_.CommandLine -like "*$Root\receiver.php*"})
$pause=Join-Path $Root 'data\watchdog.pause'
$conf=Get-Content $httpdConf -Raw
$oldInclude=[regex]::Matches($conf,'(?m)^\s*Include\s+"?C:/SMS-Awarie-Web/apache-sms\.conf"?\s*$')
$oldTask=Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Web' -ErrorAction SilentlyContinue
if($existing){Write-Host "Istniejaca instalacja w $Root - aktualizacja (dane zostaja)."}else{Write-Host 'Nowa instalacja.'}
if($oldInclude.Count -or $oldTask -or (Test-Path 'C:\SMS-Awarie-Web')){Warn 'Wykryto panel 3.x (Next.js, C:\SMS-Awarie-Web). Instalator wylaczy jego Include w Apache i zadanie SMS-Awarie-v3-Web. Katalog C:\SMS-Awarie-Web zostanie (usun go recznie po testach).'}
if($existing -and $receiver.Count){
  if(!(Test-Path $pause)){throw "Odbiornik dziala (PID $($receiver.ProcessId -join ',')). Najpierw: New-Item '$pause' -ItemType File; potem zatrzymaj odbiornik: Stop-Process -Id <PID>. Uruchom instalator ponownie."}
  throw "Odbiornik nadal dziala (PID $($receiver.ProcessId -join ',')). Zatrzymaj go: Stop-Process -Id <PID> i uruchom instalator ponownie."
}

Step 'Plan'
Write-Host "1. Kopia zapasowa plikow programu i bazy do $BackupRoot\<data>"
Write-Host "2. Kopiowanie aplikacji do $Root (bez data, logs, config.json)"
Write-Host '3. Inicjalizacja/migracja bazy (cli\init.php)'
Write-Host "4. Apache: $include w httpd.conf, kontrola httpd -t"
if(-not $Apply){Write-Host "`nPODGLAD - niczego nie zmieniono. Uruchom z -Apply." -ForegroundColor Green; exit 0}

Step '1. Kopia zapasowa'
$bk=Join-Path $BackupRoot ("pre-$version-"+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force $bk | Out-Null
Copy-Item $httpdConf (Join-Path $bk 'httpd.conf')
if($existing){
  robocopy $Root (Join-Path $bk 'app') /E /XD (Join-Path $Root 'data') (Join-Path $Root 'logs') /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw "robocopy (kopia) zakonczyl sie bledem $LASTEXITCODE"}
  $db=Join-Path $Root 'data\awarie.sqlite'
  if(Test-Path $db){
    & $php (Join-Path $PSScriptRoot 'snapshot-sqlite.php') $db (Join-Path $bk 'awarie.sqlite')
    if($LASTEXITCODE -ne 0){throw 'Kopia bazy nie powiodla sie - przerwano przed zmianami.'}
    Copy-Item (Join-Path $Root 'data\config.json') $bk -ErrorAction SilentlyContinue
  }
}
Write-Host "Kopia: $bk"

Step '2. Pliki aplikacji'
robocopy $src $Root /E /XD (Join-Path $src 'data') (Join-Path $src 'logs') /XF config.json /NFL /NDL /NJH /NJS /NP | Out-Null
if($LASTEXITCODE -ge 8){throw "robocopy (instalacja) zakonczyl sie bledem $LASTEXITCODE"}
foreach($d in @('data','logs','data\backups')){New-Item -ItemType Directory -Force (Join-Path $Root $d) | Out-Null}
foreach($d in @('data','logs')){$h=Join-Path $Root "$d\.htaccess"; if(!(Test-Path $h)){Set-Content -LiteralPath $h -Value 'Require all denied' -Encoding ASCII}}
# Konfiguracja Apache z wlasciwa sciezka
(Get-Content (Join-Path $src 'apache-sms-awarie.conf') -Raw).Replace('C:/SMS-Awarie',$rootFwd) | Set-Content -LiteralPath (Join-Path $Root 'apache-sms-awarie.conf') -Encoding ASCII
Write-Host 'Pliki skopiowane.'

Step '3. Baza danych'
& $php (Join-Path $Root 'cli\init.php')
if($LASTEXITCODE -ne 0){throw 'Inicjalizacja bazy nie powiodla sie. Pliki programu sa w kopii zapasowej.'}

Step '4. Apache'
$new=$conf
foreach($m in $oldInclude){$new=$new.Replace($m.Value,"# wylaczone przez SMS-Awarie $version`: "+$m.Value.Trim())}
if($new -notmatch [regex]::Escape($include)){$new=$new.TrimEnd()+"`r`n`r`n# SMS-Awarie`r`n$include`r`n"}
if($new -ne $conf){Set-Content -LiteralPath $httpdConf -Value $new -Encoding ASCII -NoNewline}
& $httpd -t
if($LASTEXITCODE -ne 0){Copy-Item (Join-Path $bk 'httpd.conf') $httpdConf -Force; throw 'Blad skladni Apache - przywrocono httpd.conf. Sprawdz komunikat powyzej.'}
if($oldTask){Stop-ScheduledTask -TaskName 'SMS-Awarie-v3-Web' -ErrorAction SilentlyContinue; Disable-ScheduledTask -TaskName 'SMS-Awarie-v3-Web' | Out-Null; Write-Host 'Wylaczono zadanie SMS-Awarie-v3-Web (Next.js).'}

Step 'Gotowe'
$svc=Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
if($svc){Write-Host 'Zrestartuj Apache: Restart-Service Apache2.4'}else{Warn 'Apache nie jest usluga Windows. Zrestartuj go w XAMPP Control Panel i rozwaz instalacje jako usluga (przycisk Svc), zeby dzialal po wylogowaniu.'}
Write-Host "Panel: http://localhost/sms-awarie/  (z sieci: http://<adres-serwera>/sms-awarie/)"
Write-Host 'Brak kont? Pierwszego administratora utworzysz w przegladarce.'
Write-Host 'Ustaw haslo modemu w panelu: Ustawienia i modem.'
if(!(Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Watchdog' -ErrorAction SilentlyContinue)){Write-Host 'Zadanie Watchdoga: .\scripts\install-watchdog-task.ps1'}
if(!(Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Backup' -ErrorAction SilentlyContinue)){Write-Host 'Zadanie backupu: .\scripts\install-backup-task.ps1 -Apply'}
if(Test-Path $pause){Write-Host "Po sprawdzeniu panelu usun pauze Watchdoga: Remove-Item '$pause'"}
Write-Host 'Kontrola: .\scripts\verify.ps1'
