# Windows PowerShell 5.1 elevated. Otwiera port Apache tylko dla zaufanej podsieci, np. 192.168.10.0/24.
# Potrzebne tylko, jesli port Apache (domyslnie 80) nie jest juz otwarty dla innych aplikacji XAMPP.
param([Parameter(Mandatory=$true)][string]$RemoteSubnet,[int]$Port=80)
$ErrorActionPreference='Stop'
if($RemoteSubnet -notmatch '^\d{1,3}(\.\d{1,3}){3}/(\d|[12]\d|3[0-2])$'){throw 'Expected IPv4 CIDR e.g. 192.168.10.0/24'}
$name="SMS-Awarie-LAN-$Port"
if(Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue){throw 'Rule exists; inspect before modifying'}
New-NetFirewallRule -DisplayName $name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -RemoteAddress $RemoteSubnet -Profile Domain,Private | Out-Null
Write-Host "Allowed $RemoteSubnet to TCP $Port on Domain/Private profiles only."
