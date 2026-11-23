# Run elevated after 10-install-files.ps1 -Apply. Never overwrites existing data or config.
param([switch]$Apply)
$ErrorActionPreference = 'Stop'
$root = 'C:\SMS-Awarie'
$php = 'C:\xampp\php\php.exe'
if (!(Test-Path $php)) { throw 'Install XAMPP PHP first' }
if (!(Test-Path (Join-Path $root 'common.php'))) { throw 'Deploy files first' }
if (!(Test-Path (Join-Path $root 'groups.php'))) { throw 'Missing groups.php' }
if (-not $Apply) { Write-Host 'DRY RUN: creates data/logs and config template; initializes SQLite schema. Use -Apply.'; exit }
New-Item -ItemType Directory -Force (Join-Path $root 'data'),(Join-Path $root 'logs') | Out-Null
$config = Join-Path $root 'data\config.json'
if (!(Test-Path $config)) {
  $settings = @{ modem='http://192.168.8.1'; modempass='CHANGE_ME'; ack=$false; delete_after_import=$true; send_per_cycle=5; server=''; database=''; user=''; password='' }
  $settings | ConvertTo-Json | Set-Content -LiteralPath $config -Encoding ASCII
  Write-Warning 'Set modem password in data\config.json before starting receiver. Keep ack=false until tests.'
}
$db = Join-Path $root 'data\awarie.sqlite'
if (Test-Path $db) { Write-Warning 'SQLite already exists; initialization will only add missing tables/groups.' }
& $php (Join-Path $PSScriptRoot '20-init-groups.php')
if ($LASTEXITCODE -ne 0) { throw 'Database initialization failed' }
Write-Host 'Initialization complete. DO NOT start receiver until modem config and duplicate tasks are checked.'
