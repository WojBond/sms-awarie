# Windows PowerShell 5.1 elevated. Require explicit trusted subnet, e.g. 192.168.10.0/24
param([Parameter(Mandatory=$true)][string]$RemoteSubnet,[int]$Port=8090)
$ErrorActionPreference='Stop'
if($RemoteSubnet -notmatch '^\d{1,3}(\.\d{1,3}){3}/(\d|[12]\d|3[0-2])$'){throw 'Expected IPv4 CIDR e.g. 192.168.10.0/24'}
$name='SMS-Awarie-v3-LAN'
if(Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue){throw 'Rule exists; inspect before modifying'}
New-NetFirewallRule -DisplayName $name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -RemoteAddress $RemoteSubnet -Profile Domain,Private | Out-Null
Write-Host "Allowed $RemoteSubnet to TCP $Port on Domain/Private profiles only."
