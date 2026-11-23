# Windows PowerShell 5.1 elevated; LAN HTTP requires trusted LAN and TLS at reverse proxy.
param([string]$BindIP='127.0.0.1',[int]$Port=8090,[switch]$Apply)
$ErrorActionPreference='Stop'
if($BindIP -notmatch '^(127\.0\.0\.1|(?:\d{1,3}\.){3}\d{1,3})$'){throw 'Use a literal IPv4 address'}
if($Port -lt 1024 -or $Port -gt 65535){throw 'Port out of range'}
$conf='C:\xampp\apache\conf\httpd.conf';$httpd='C:\xampp\apache\bin\httpd.exe'
if(!(Test-Path $conf) -or !(Test-Path $httpd)){throw 'XAMPP Apache not found'}
$include='C:/SMS-Awarie-Web/apache-sms.conf'
$vhost=@"
Listen ${BindIP}:${Port}
<VirtualHost ${BindIP}:${Port}>
 ServerName $BindIP
 ProxyRequests Off
 ProxyPreserveHost On
 ProxyPass /sms-api !
 Alias /sms-api "C:/SMS-Awarie-Web/php/api.php"
 <Directory "C:/SMS-Awarie-Web/php">
  Require all denied
  <Files "api.php">
   Require all granted
  </Files>
 </Directory>
 ProxyPass / http://127.0.0.1:3000/
 ProxyPassReverse / http://127.0.0.1:3000/
</VirtualHost>
"@
Write-Host "Target: $BindIP`:$Port";if(-not $Apply){Write-Host 'DRY RUN. Use -Apply in maintenance window.';exit}
$original=Get-Content $conf -Raw
if($original -notmatch '(?m)^\s*LoadModule\s+proxy_module\s+' -or $original -notmatch '(?m)^\s*LoadModule\s+proxy_http_module\s+') {throw 'Enable proxy_module and proxy_http_module in httpd.conf manually before applying'}
$backup="$conf.$(Get-Date -Format yyyyMMddHHmmss).bak";Copy-Item $conf $backup
$target='C:\SMS-Awarie-Web\apache-sms.conf';if(Test-Path $target){Copy-Item $target "$target.$(Get-Date -Format yyyyMMddHHmmss).bak"}
Set-Content -LiteralPath $target -Value $vhost -Encoding ASCII
if($original -notmatch '(?m)^\s*Include\s+C:/SMS-Awarie-Web/apache-sms\.conf\s*$') {Add-Content -LiteralPath $conf -Value "`r`nInclude $include" -Encoding ASCII}
& $httpd -t;if($LASTEXITCODE -ne 0){Copy-Item $backup $conf -Force;throw 'Apache syntax failed. Restored httpd.conf; inspect vhost manually.'}
Write-Host 'Apache syntax OK. Restart Apache manually after reviewing. Configure firewall allowlist and TLS before LAN access.'
