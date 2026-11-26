# SMS-Awarie - Watchdog v3 (4.6.1); Windows PowerShell 5.1
param([string]$Root=$PSScriptRoot,[string]$Php='',[int]$StaleSeconds=180,[int]$CooldownSeconds=300,[int]$MaxRestartsPerHour=3,[int]$GraceSeconds=90,[int]$StopWaitSeconds=20)
$ErrorActionPreference='Stop'
# <XAMPP>\htdocs\sms-awarie -> <XAMPP>\php\php.exe (XAMPP na dowolnym dysku)
if(-not $Php){$Php=Join-Path (Split-Path (Split-Path $Root -Parent) -Parent) 'php\php.exe'}
$data=Join-Path $Root 'data'; $logs=Join-Path $Root 'logs'; $receiver=Join-Path $Root 'receiver.php'
New-Item -ItemType Directory -Force -Path $data,$logs | Out-Null
$statePath=Join-Path $data 'watchdog-state.json'; $events=Join-Path $logs 'watchdog-events.jsonl'; $log=Join-Path $logs 'watchdog.log'; $hbPath=Join-Path $data 'heartbeat.json'; $pausePath=Join-Path $data 'watchdog.pause'; $stopPath=Join-Path $data 'receiver.stop'
$mutex=New-Object System.Threading.Mutex($false,'Global\SMSAwarieV3Watchdog'); $owned=$false
function Rotate-Log([string]$path,[long]$maxBytes=5MB) { if((Test-Path -LiteralPath $path) -and (Get-Item -LiteralPath $path).Length -gt $maxBytes){ Move-Item -LiteralPath $path -Destination "$path.1" -Force } }
# 4.6.1: UTF-8 bez BOM - Set-Content/Add-Content -Encoding UTF8 w PowerShell 5.1 dopisuja BOM, przez ktory PHP nie czytal stanu (panel: "Nieznany").
$utf8=New-Object System.Text.UTF8Encoding($false)
function Save-State($s) { $tmp="$statePath.$PID.tmp"; [IO.File]::WriteAllText($tmp,($s | ConvertTo-Json -Depth 6),$utf8); Move-Item -LiteralPath $tmp -Destination $statePath -Force }
function Append-Line([string]$path,[string]$line) { [IO.File]::AppendAllText($path,$line+"`r`n",$utf8) }
function Event([string]$kind,[string]$detail,[int]$processId=0) {
 $now=[DateTimeOffset]::UtcNow.ToString('o'); $e=[ordered]@{time=$now;event=$kind;pid=$processId;detail=$detail};
 Append-Line $events ($e|ConvertTo-Json -Compress);
 Append-Line $log "$now $kind PID=$processId $detail";
 $script:s.lastEvent=$kind; $script:s.lastProblem=if($kind -in @('ERROR','STALE','RESTART_FAILED','RESTART_BLOCKED')){$detail}else{$script:s.lastProblem};
}
function Find-Workers {
 $target=[IO.Path]::GetFullPath($receiver).ToLowerInvariant()
 @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe' OR Name = 'php-cgi.exe'" | Where-Object {
   $cmd=[string]$_.CommandLine; $exe=[string]$_.ExecutablePath
   $cmd.ToLowerInvariant().Contains($target) -and $exe -and ([IO.Path]::GetFullPath($exe) -ieq [IO.Path]::GetFullPath($Php))
 })
}
try {
 $owned=$mutex.WaitOne(0); if(-not $owned){exit 0}
 Rotate-Log $events; Rotate-Log $log
 $s=@{lastStart='';lastRestart='';lastEvent='';lastProblem='';restartHistory=@();pid=0;status='UNKNOWN';checked=''}
 if(Test-Path $statePath){try{$old=Get-Content $statePath -Raw|ConvertFrom-Json; foreach($k in @($s.Keys)){if($null -ne $old.$k){$s[$k]=$old.$k}}}catch{Event 'ERROR' 'Corrupt watchdog state; automatic restart disabled';exit 2}}
 $s.checked=[DateTimeOffset]::UtcNow.ToString('o')
 if(Test-Path $pausePath){$s.status='PAUSED';Save-State $s;exit 0}
 # 4.4.0: odbiornik zatrzymany w panelu (Ustawienia > Uslugi) - nie uruchamiaj go ponownie.
 if(Test-Path $stopPath){$s.status='RECEIVER_STOPPED';Save-State $s;exit 0}
 if(!(Test-Path $Php) -or !(Test-Path $receiver)){Event 'ERROR' 'PHP or receiver.php missing';$s.status='ERROR';Save-State $s;exit 2}
 $workers=@(Find-Workers)
 $age=$null;$hbState='MISSING';$hbPid=0;$hbPhase='unknown'
 if(Test-Path $hbPath){try{$hb=Get-Content $hbPath -Raw|ConvertFrom-Json;$hbState=[string]$hb.state;$hbPid=[int]$hb.pid;$hbPhase=[string]$hb.phase;if([long]$hb.time -gt 0){$age=([DateTimeOffset]::UtcNow-[DateTimeOffset]::FromUnixTimeSeconds([long]$hb.time)).TotalSeconds}}catch{$hbState='INVALID'}}
 $now=[DateTimeOffset]::UtcNow
 $history=@($s.restartHistory | Where-Object {try{($now-[DateTimeOffset]::Parse([string]$_)).TotalSeconds -lt 3600}catch{$false}})
 $s.restartHistory=$history
 $stale=($null -eq $age -or $age -gt $StaleSeconds -or $age -lt -30 -or $hbState -eq 'ERROR')
 if($workers.Count -gt 1){$s.status='MULTIPLE';Event 'ERROR' 'Multiple receiver processes; manual intervention required';Save-State $s;exit 2}
 if($workers.Count -eq 1 -and -not $stale){
   if($hbPid -ne 0 -and $hbPid -ne [int]$workers[0].ProcessId){$s.status='PID_MISMATCH';Event 'ERROR' 'Fresh heartbeat belongs to another PID; manual inspection required';Save-State $s;exit 2}
   $s.status='RUNNING';$s.pid=[int]$workers[0].ProcessId;Save-State $s;exit 0
 }
 if($workers.Count -eq 1){
   $start=[DateTimeOffset]::MinValue
   try{$start=[DateTimeOffset]([datetime]$workers[0].CreationDate)}catch{Event 'ERROR' 'Cannot verify worker start time; manual intervention required';$s.status='ERROR';Save-State $s;exit 2}
   if(($now-$start).TotalSeconds -lt $GraceSeconds){$s.status='STARTING';Save-State $s;exit 0}
 }
 $reason=if($workers.Count -eq 0){'Process absent'}else{"Stale heartbeat state=$hbState phase=$hbPhase age=$age"}
 $s.status=if($workers.Count -eq 0){'STOPPED'}else{'STALE'}
 if($history.Count -ge $MaxRestartsPerHour){Event 'RESTART_BLOCKED' "Hourly limit reached: $reason";Save-State $s;exit 2}
 if($s.lastStart){try{if(($now-[DateTimeOffset]::Parse([string]$s.lastStart)).TotalSeconds -lt $CooldownSeconds){Save-State $s;exit 0}}catch{}}
 if($workers.Count -eq 1){
   # Avoid terminating a process when heartbeat is owned by a different live PID.
   if($hbPid -ne 0 -and $hbPid -ne [int]$workers[0].ProcessId){$s.status='PID_MISMATCH';Event 'ERROR' 'Heartbeat PID differs from worker PID; restart blocked';Save-State $s;exit 2}
   $victim=$workers[0]; $id=[int]$victim.ProcessId
   # Recheck identity and creation time before termination to avoid PID reuse.
   $again=@(Find-Workers|Where-Object {$_.ProcessId -eq $id -and $_.CreationDate -eq $victim.CreationDate})
   if($again.Count -ne 1){Event 'RESTART_FAILED' 'Worker identity changed; not terminating' $id;Save-State $s;exit 2}
   Event 'STALE' $reason $id
   # Stop-Process is forceful; no resend of uncertain outbox records is permitted.
   Stop-Process -Id $id -Force -ErrorAction Stop
   $deadline=(Get-Date).AddSeconds($StopWaitSeconds)
   do {Start-Sleep -Milliseconds 500;$remaining=@(Find-Workers|Where-Object {$_.ProcessId -eq $id})} while($remaining.Count -gt 0 -and (Get-Date) -lt $deadline)
   if($remaining.Count -gt 0){Event 'RESTART_FAILED' 'Old process still alive' $id;Save-State $s;exit 2}
 }
 # A newly started worker must acquire receiver.lock itself. Never remove that lock.
 $out=Join-Path $logs 'receiver-stdout.log';$err=Join-Path $logs 'receiver-stderr.log'
 # Zachowaj log poprzedniego uruchomienia (Start-Process nadpisuje plik).
 foreach($f in @($out,$err)){if(Test-Path -LiteralPath $f){Move-Item -LiteralPath $f -Destination "$f.1" -Force}}
 $proc=Start-Process -FilePath $Php -ArgumentList ('"'+$receiver+'"') -WorkingDirectory $Root -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err -PassThru
 $s.pid=$proc.Id;$s.lastStart=$now.ToString('o');$s.lastRestart=$now.ToString('o');$s.restartHistory=@($history)+@($now.ToString('o'));$s.status='STARTING'
 Event 'RESTART' $reason $proc.Id;Save-State $s
}catch{try{Event 'RESTART_FAILED' $_.Exception.Message; $s.status='ERROR';Save-State $s}catch{};exit 1}
finally{if($owned){$mutex.ReleaseMutex()};$mutex.Dispose()}
