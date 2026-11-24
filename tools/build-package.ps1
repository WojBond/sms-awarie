# Buduje paczke sms-awarie-vX.Y.Z.zip. Wersja odczytywana z app\config.php (APP_VERSION).
# Uzycie (z katalogu repozytorium): .\tools\build-package.ps1 [-Out D:\AppDeploy\Projekty_HTML\sms-awarie]
param([string]$Out=(Join-Path $PSScriptRoot '..\dist'))
$ErrorActionPreference='Stop'
$root=Resolve-Path (Join-Path $PSScriptRoot '..')
$common=Get-Content (Join-Path $root 'app\config.php') -Raw
if($common -notmatch "define\('APP_VERSION',\s*'([0-9]+\.[0-9]+\.[0-9]+)'\)"){throw 'Nie znaleziono APP_VERSION w app\config.php'}
$v=$Matches[1]
$name="SMS-Awarie-v$v-INSTALATOR"
$tmp=Join-Path ([IO.Path]::GetTempPath()) ("sms-awarie-build-"+[guid]::NewGuid())
$stage=Join-Path $tmp $name
New-Item -ItemType Directory -Force $stage | Out-Null
foreach($item in @('app','scripts','docs','README.md','CHANGELOG.md')){Copy-Item (Join-Path $root $item) $stage -Recurse}
# Bez danych, zaleznosci i buildu
# katalogi data i logs zostaja puste (tylko .htaccess/.gitkeep)
Get-ChildItem (Join-Path $stage 'app\data'),(Join-Path $stage 'app\logs') -Force | Where-Object {$_.Name -notin @('.htaccess','.gitkeep')} | Remove-Item -Recurse -Force
Get-ChildItem $stage -Recurse -File | Where-Object {$_.Name -match '\.sqlite|^config\.json$|\.zip$'} | Remove-Item -Force
$sums=Get-ChildItem $stage -Recurse -File | Sort-Object FullName | ForEach-Object {"{0}  ./{1}" -f (Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLower(),$_.FullName.Substring($stage.Length+1).Replace('\','/')}
Set-Content -LiteralPath (Join-Path $stage 'SHA256SUMS.txt') -Value $sums -Encoding ASCII
New-Item -ItemType Directory -Force $Out | Out-Null
$zip=Join-Path (Resolve-Path $Out) "sms-awarie-v$v.zip"
if(Test-Path $zip){throw "Paczka juz istnieje: $zip. Podbij APP_VERSION albo usun plik."}
Compress-Archive -Path $stage -DestinationPath $zip
Remove-Item $tmp -Recurse -Force
Write-Host "Paczka: $zip"
