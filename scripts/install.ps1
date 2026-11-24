# SMS-Awarie 4.x - instalator do standardowego folderu XAMPP: C:\xampp\htdocs\sms-awarie
# Nowa instalacja, aktualizacja oraz przeniesienie z C:\SMS-Awarie (wersje 3.x / 4.0.0).
# Windows PowerShell 5.1, uruchom jako administrator z rozpakowanego katalogu paczki:
#   .\scripts\install.ps1            (podglad - niczego nie zmienia)
#   .\scripts\install.ps1 -Apply     (instalacja)
# Nie nadpisuje danych (data\awarie.sqlite, data\config.json) ani logow.
param([string]$Xampp='C:\xampp',[string]$Root='',[string]$OldRoot='C:\SMS-Awarie',[string]$BackupRoot='C:\SMS-Awarie-Backups',[switch]$Apply)
$ErrorActionPreference='Stop'
if(-not $Root){$Root=Join-Path $Xampp 'htdocs\sms-awarie'}
$release=Split-Path $PSScriptRoot -Parent
$src=Join-Path $release 'htdocs\sms-awarie'
$php=Join-Path $Xampp 'php\php.exe'
$httpd=Join-Path $Xampp 'apache\bin\httpd.exe'
$httpdConf=Join-Path $Xampp 'apache\conf\httpd.conf'
$version=(Select-String -Path (Join-Path $src 'config.php') -Pattern "APP_VERSION', '([^']+)'").Matches.Groups[1].Value
$snapshot=Join-Path $PSScriptRoot 'snapshot-sqlite.php'

function Step($t){Write-Host "`n== $t" -ForegroundColor Cyan}
function Warn($t){Write-Host "UWAGA: $t" -ForegroundColor Yellow}

Step "SMS-Awarie $version -> $Root"
foreach($f in @($src,$php,$httpd,$httpdConf)){if(!(Test-Path $f)){throw "Brak: $f (sprawdz -Xampp i czy uruchamiasz skrypt z rozpakowanej paczki)"}}
$mods=(& $php -m) -join ' '
foreach($ext in @('pdo_sqlite','curl','SimpleXML')){if($mods -notmatch "\b$ext\b"){throw "PHP nie ma rozszerzenia $ext - wlacz je w $Xampp\php\php.ini"}}
Write-Host "PHP: $(& $php -r 'echo PHP_VERSION;') z pdo_sqlite, curl, SimpleXML - OK"

# --- Stan obecny
$existing=Test-Path (Join-Path $Root 'receiver.php')
$migrate=($OldRoot -ne $Root) -and (Test-Path (Join-Path $OldRoot 'data\awarie.sqlite')) -and !(Test-Path (Join-Path $Root 'data\awarie.sqlite'))
$receivers=@(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {$_.CommandLine -like '*receiver.php*'})
$conf=Get-Content $httpdConf -Raw
# Stare wpisy: panel 3.x (C:/SMS-Awarie-Web) i Alias z 4.0.0 (C:/SMS-Awarie/apache-sms-awarie.conf)
$oldIncludes=[regex]::Matches($conf,'(?m)^\s*Include\s+"?C:/SMS-Awarie(-Web/apache-sms|/apache-sms-awarie)\.conf"?\s*$')
$oldWebTask=Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Web' -ErrorAction SilentlyContinue
$oldTasks=@(foreach($n in @('SMS-Awarie-v3-Watchdog','SMS-Awarie-v3-Backup')){$t=Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue; if($t -and (($t.Actions | ForEach-Object {"$($_.Arguments) $($_.WorkingDirectory)"}) -join ' ') -notlike "*$Root*"){$t}})
$htdocsBlock=[regex]::Match($conf,'(?is)<Directory\s+"?[^">]*/htdocs"?\s*>(.*?)</Directory>')
$allowAll=$htdocsBlock.Success -and $htdocsBlock.Groups[1].Value -match '(?im)^\s*AllowOverride\s+All'

if($existing){Write-Host "Aktualizacja istniejacej instalacji w $Root (dane zostaja)."}
elseif($migrate){Write-Host "Przeniesienie z $OldRoot do $Root (baza, config.json i kopie zostana skopiowane; $OldRoot zostaje nienaruszony)."}
else{Write-Host 'Nowa instalacja.'}
if($receivers.Count){throw "Dziala odbiornik (PID $($receivers.ProcessId -join ',')). Najpierw wstrzymaj Watchdoga (New-Item <katalog>\data\watchdog.pause -ItemType File) i zatrzymaj odbiornik: Stop-Process -Id <PID>. Potem uruchom instalator ponownie."}
if(-not $allowAll){Warn "Nie znaleziono 'AllowOverride All' dla htdocs w httpd.conf. Bez tego pliki .htaccess nie chronia katalogu data (baza, config.json z haslem modemu). Ustaw AllowOverride All w bloku <Directory `"$Xampp\htdocs`">."}
if($oldIncludes.Count){Warn "Stare wpisy Include w httpd.conf zostana zakomentowane: $(($oldIncludes | ForEach-Object {$_.Value.Trim()}) -join '; ')"}
if($oldWebTask){Warn 'Zadanie SMS-Awarie-v3-Web (Next.js z 3.x) zostanie wylaczone.'}
foreach($t in $oldTasks){Warn "Zadanie $($t.TaskName) wskazuje stara lokalizacje - zostanie wylaczone; zarejestruj je ponownie z -Replace (instrukcja na koncu)."}

Step 'Plan'
Write-Host "1. Kopia zapasowa do $BackupRoot\pre-$version-<data>"
if($migrate){Write-Host "2. Kopia danych z $OldRoot\data do $Root\data"}
Write-Host "3. Kopiowanie aplikacji do $Root (bez data i logs)"
Write-Host '4. Inicjalizacja/migracja bazy (cli\init.php)'
Write-Host '5. httpd.conf: tylko wylaczenie starych wpisow (htdocs nie wymaga Alias)'
if(-not $Apply){Write-Host "`nPODGLAD - niczego nie zmieniono. Uruchom z -Apply." -ForegroundColor Green; exit 0}

Step '1. Kopia zapasowa'
$bk=Join-Path $BackupRoot ("pre-$version-"+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force $bk | Out-Null
Copy-Item $httpdConf (Join-Path $bk 'httpd.conf')
$dataSrc=$null
if($existing){$dataSrc=$Root}elseif($migrate){$dataSrc=$OldRoot}
if($existing){
  robocopy $Root (Join-Path $bk 'app') /E /XD (Join-Path $Root 'data') (Join-Path $Root 'logs') /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw "robocopy (kopia) zakonczyl sie bledem $LASTEXITCODE"}
}
if($dataSrc -and (Test-Path (Join-Path $dataSrc 'data\awarie.sqlite'))){
  & $php $snapshot (Join-Path $dataSrc 'data\awarie.sqlite') (Join-Path $bk 'awarie.sqlite')
  if($LASTEXITCODE -ne 0){throw 'Kopia bazy nie powiodla sie - przerwano przed zmianami.'}
  Copy-Item (Join-Path $dataSrc 'data\config.json') $bk -ErrorAction SilentlyContinue
}
Write-Host "Kopia: $bk"

Step '2-3. Pliki'
New-Item -ItemType Directory -Force $Root | Out-Null
foreach($d in @('data','logs','data\backups')){New-Item -ItemType Directory -Force (Join-Path $Root $d) | Out-Null}
foreach($d in @('data','logs')){Copy-Item (Join-Path $src "$d\.htaccess") (Join-Path $Root "$d\.htaccess") -Force}
if($migrate){
  & $php $snapshot (Join-Path $OldRoot 'data\awarie.sqlite') (Join-Path $Root 'data\awarie.sqlite')
  if($LASTEXITCODE -ne 0){throw 'Kopiowanie bazy do nowej lokalizacji nie powiodlo sie.'}
  Copy-Item (Join-Path $OldRoot 'data\config.json') (Join-Path $Root 'data\config.json') -ErrorAction SilentlyContinue
  $ob=Join-Path $OldRoot 'data\backups'
  if(Test-Path $ob){robocopy $ob (Join-Path $Root 'data\backups') /E /NFL /NDL /NJH /NJS /NP | Out-Null}
  Write-Host "Dane skopiowane z $OldRoot."
}
robocopy $src $Root /E /XD (Join-Path $src 'data') (Join-Path $src 'logs') /XF config.json /NFL /NDL /NJH /NJS /NP | Out-Null
if($LASTEXITCODE -ge 8){throw "robocopy (instalacja) zakonczyl sie bledem $LASTEXITCODE"}
Write-Host 'Pliki aplikacji skopiowane.'

Step '4. Baza danych'
& $php (Join-Path $Root 'cli\init.php')
if($LASTEXITCODE -ne 0){throw 'Inicjalizacja bazy nie powiodla sie. Pliki i baza sa w kopii zapasowej.'}

Step '5. Apache i zadania'
$restart=$false
if($oldIncludes.Count){
  $new=$conf
  foreach($m in $oldIncludes){$new=$new.Replace($m.Value,"`r`n# wylaczone przez SMS-Awarie $version`: "+$m.Value.Trim())}
  Set-Content -LiteralPath $httpdConf -Value $new -Encoding ASCII -NoNewline
  & $httpd -t
  if($LASTEXITCODE -ne 0){Copy-Item (Join-Path $bk 'httpd.conf') $httpdConf -Force; throw 'Blad skladni Apache - przywrocono httpd.conf.'}
  $restart=$true
}
if($oldWebTask){Stop-ScheduledTask -TaskName 'SMS-Awarie-v3-Web' -ErrorAction SilentlyContinue; Disable-ScheduledTask -TaskName 'SMS-Awarie-v3-Web' | Out-Null; Write-Host 'Wylaczono zadanie SMS-Awarie-v3-Web.'}
foreach($t in $oldTasks){Disable-ScheduledTask -TaskName $t.TaskName | Out-Null; Write-Host "Wylaczono zadanie $($t.TaskName) (stara lokalizacja)."}

Step 'Gotowe'
if($restart){
  $svc=Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
  if($svc){Write-Host 'Zrestartuj Apache: Restart-Service Apache2.4'}else{Write-Host 'Zrestartuj Apache w XAMPP Control Panel.'}
}
Write-Host "Panel: http://localhost/sms-awarie/  (z sieci: http://<adres-serwera>/sms-awarie/)"
Write-Host 'Brak kont? Pierwszego administratora utworzysz w przegladarce. Haslo modemu ustaw w: Ustawienia i modem.'
$wd=Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Watchdog' -ErrorAction SilentlyContinue
if(!$wd){Write-Host 'Zadanie Watchdoga: .\scripts\install-watchdog-task.ps1'}elseif($oldTasks | Where-Object TaskName -eq 'SMS-Awarie-v3-Watchdog'){Write-Host 'Zadanie Watchdoga: .\scripts\install-watchdog-task.ps1 -Replace'}
$bt=Get-ScheduledTask -TaskName 'SMS-Awarie-v3-Backup' -ErrorAction SilentlyContinue
if(!$bt){Write-Host 'Zadanie backupu: .\scripts\install-backup-task.ps1 -Apply'}elseif($oldTasks | Where-Object TaskName -eq 'SMS-Awarie-v3-Backup'){Write-Host 'Zadanie backupu: .\scripts\install-backup-task.ps1 -Replace -Apply'}
$pause=Join-Path $Root 'data\watchdog.pause'
if(Test-Path $pause){Write-Host "Po sprawdzeniu panelu usun pauze Watchdoga: Remove-Item '$pause'"}
if($migrate){Write-Host "Stary katalog $OldRoot nie jest juz uzywany - usun go po kilku dniach poprawnej pracy."}
Write-Host 'Kontrola (m.in. czy data\ jest zablokowane z sieci): .\scripts\verify.ps1'
