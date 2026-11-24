# SMS-Awarie - wspolna funkcja: wykrywanie katalogu XAMPP (moze lezec na dowolnym dysku).
# Kolejnosc: parametr -Xampp, rejestr instalatora XAMPP, usluga Apache, <dysk>:\xampp na kazdym dysku.
function Test-Xampp([string]$p){ return [bool]($p -and (Test-Path (Join-Path $p 'php\php.exe')) -and (Test-Path (Join-Path $p 'apache\bin\httpd.exe'))) }
function Find-Xampp([string]$Given=''){
  if($Given){
    if(Test-Xampp $Given){ return (Resolve-Path $Given).Path }
    throw "W '$Given' nie ma php\php.exe i apache\bin\httpd.exe - to nie jest katalog XAMPP."
  }
  $candidates=@()
  foreach($k in @('HKLM:\SOFTWARE\xampp','HKLM:\SOFTWARE\WOW6432Node\xampp')){
    try{ $v=(Get-ItemProperty -Path $k -ErrorAction Stop).Install_Dir; if($v){ $candidates+=$v } }catch{}
  }
  try{
    foreach($svc in @(Get-CimInstance Win32_Service -Filter "Name LIKE 'Apache%'" -ErrorAction Stop)){
      if([string]$svc.PathName -match '^"?(.+?)\\apache\\bin\\httpd\.exe'){ $candidates+=$Matches[1] }
    }
  }catch{}
  foreach($d in @(Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue)){ if($d.Root){ $candidates+=(Join-Path $d.Root 'xampp') } }
  foreach($p in $candidates){ if(Test-Xampp $p){ return (Resolve-Path $p).Path } }
  throw 'Nie znaleziono XAMPP. Podaj sciezke parametrem, np.: -Xampp D:\xampp'
}
