<?php
// SMS-Awarie 4.4.0 - zatrzymywanie i wznawianie uslug z Harmonogramu zadan (Ustawienia > Usługi).
// Dwa poziomy:
//  1) pliki-flagi w data\ (dzialaja zawsze, bez uprawnien administratora):
//     watchdog.pause - Watchdog nic nie robi, receiver.stop - odbiornik konczy prace po biezacym kroku
//     i Watchdog go nie uruchamia, backup.pause - zadanie backupu pomija kopie;
//  2) wlaczanie/wylaczanie samych zadan w Harmonogramie (PowerShell; wymaga, zeby Apache dzialal
//     z uprawnieniami administratora - inaczej panel pokazuje polecenie do recznego uruchomienia).

const SERVICE_FLAGS = [
    'watchdog' => ['file' => 'watchdog.pause', 'name' => 'Watchdog', 'task' => 'SMS-Awarie-v3-Watchdog'],
    'receiver' => ['file' => 'receiver.stop', 'name' => 'Odbiornik SMS', 'task' => ''],
    'backup'   => ['file' => 'backup.pause', 'name' => 'Kopia zapasowa bazy', 'task' => 'SMS-Awarie-v3-Backup'],
];

function service_flag_path(string $key): string { return DATA_DIR.'/'.SERVICE_FLAGS[$key]['file']; }

// Data utworzenia flagi albo null, gdy usluga nie jest wstrzymana.
function service_flag(string $key): ?string {
    $f = service_flag_path($key);
    clearstatcache(true, $f);
    if (!is_file($f)) return null;
    $t = trim((string)@file_get_contents($f));
    return $t !== '' ? $t : date('c', (int)filemtime($f));
}
function service_set(string $key, bool $paused, string $who): void {
    $f = service_flag_path($key);
    if ($paused) {
        if (!is_file($f) && file_put_contents($f, date('c').' '.$who) === false) throw new RuntimeException('Nie można zapisać '.SERVICE_FLAGS[$key]['file'].' w katalogu data.');
    } elseif (is_file($f) && !@unlink($f)) {
        throw new RuntimeException('Nie można usunąć '.SERVICE_FLAGS[$key]['file'].' z katalogu data.');
    }
}

// Czeka, az odbiornik zapisze heartbeat STOPPED albo przestanie dzialac (brak swiezego heartbeat).
function wait_receiver_stopped(int $timeout = 25): bool {
    $start = time();
    do {
        clearstatcache();
        $hb = read_json(DATA_DIR.'/heartbeat.json');
        if (!is_array($hb) || time() - (int)($hb['time'] ?? 0) > 90) return true;
        if (($hb['state'] ?? '') === 'STOPPED') return true;
        if (time() - $start >= $timeout) return false;
        sleep(1);
    } while (true);
}

// Stan odbiornika do wyswietlenia: STOPPED (zatrzymany), STOPPING (flaga jest, proces jeszcze konczy) albo stan z heartbeat.
function receiver_display_state(array $hb): string {
    $flag = service_flag('receiver') !== null;
    if ($hb['state'] === 'STOPPED' || ($flag && $hb['state'] === 'OFFLINE')) return 'STOPPED';
    if ($flag) return 'STOPPING';
    return $hb['state'];
}

// ---- Harmonogram zadan Windows (PowerShell, tylko Windows) ----

function scheduler_available(): bool { return PHP_OS_FAMILY === 'Windows' && function_exists('proc_open'); }

// Uruchamia skrypt PowerShell (bez profilu, -EncodedCommand). Zwraca [kod wyjscia, stdout, stderr].
function powershell_run(string $script, int $timeout = 20): array {
    $enc = base64_encode(mb_convert_encoding('$ProgressPreference="SilentlyContinue";$ErrorActionPreference="Stop";'.$script, 'UTF-16LE', 'UTF-8'));
    $cmd = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $enc];
    // Wyjscie do plikow tymczasowych: na Windows potoki nie obsluguja trybu nieblokujacego.
    $tmp = [tempnam(sys_get_temp_dir(), 'smsps'), tempnam(sys_get_temp_dir(), 'smsps')];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $tmp[0], 'w'], 2 => ['file', $tmp[1], 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) { array_map('unlink', $tmp); return [-1, '', 'Nie można uruchomić powershell.exe']; }
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeout; $code = -1; $timedOut = false;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = (int)$st['exitcode']; break; }
        if (microtime(true) > $deadline) { proc_terminate($proc); $timedOut = true; break; }
        usleep(100000);
    }
    $rc = proc_close($proc);
    if ($code === -1 && !$timedOut) $code = $rc;
    $out = trim((string)@file_get_contents($tmp[0])); $err = trim((string)@file_get_contents($tmp[1]));
    array_map('unlink', $tmp);
    if ($timedOut) return [-1, $out, 'Przekroczono czas '.$timeout.' s'];
    return [$code, $out, $err];
}

// Zadania SMS-Awarie* z Harmonogramu: [nazwa => [state, last, next, result, user]] albo string z bledem.
function scheduler_tasks() {
    if (!scheduler_available()) return 'Harmonogram zadań jest dostępny tylko na serwerze Windows.';
    [$code, $out, $err] = powershell_run(<<<'PS'
$r=@(Get-ScheduledTask -TaskName 'SMS-Awarie*' -ErrorAction SilentlyContinue | ForEach-Object {
  $i=$null; try{$i=$_ | Get-ScheduledTaskInfo}catch{}
  [pscustomobject]@{name=$_.TaskName;path=$_.TaskPath;state=[string]$_.State;user=[string]$_.Principal.UserId;
    last=$(if($i -and $i.LastRunTime -and $i.LastRunTime.Year -gt 2000){$i.LastRunTime.ToString('s')}else{''});
    next=$(if($i -and $i.NextRunTime){$i.NextRunTime.ToString('s')}else{''});
    result=$(if($i){[int64]$i.LastTaskResult}else{$null})}
})
ConvertTo-Json -InputObject $r -Compress -Depth 3
PS);
    if ($code !== 0) return 'Nie udało się odczytać Harmonogramu zadań: '.($err ?: 'kod '.$code);
    $list = json_decode(strip_bom($out ?: '[]'), true);
    if (!is_array($list)) return 'Nieczytelna odpowiedź Harmonogramu zadań.';
    if (isset($list['name'])) $list = [$list];
    $tasks = [];
    foreach ($list as $t) if (is_array($t) && isset($t['name'])) $tasks[(string)$t['name']] = $t;
    return $tasks;
}

function scheduler_task_name_ok(string $name): bool { return (bool)preg_match('/^SMS-Awarie[A-Za-z0-9._-]{0,80}$/D', $name); }

// action: enable | disable | run. Zwraca null (OK) albo komunikat bledu.
function scheduler_change(string $name, string $action): ?string {
    if (!scheduler_task_name_ok($name)) return 'Niepoprawna nazwa zadania.';
    $cmd = ['enable' => 'Enable-ScheduledTask', 'disable' => 'Disable-ScheduledTask', 'run' => 'Start-ScheduledTask'][$action] ?? null;
    if (!$cmd) return 'Nieznana operacja.';
    if (!scheduler_available()) return 'Harmonogram zadań jest dostępny tylko na serwerze Windows.';
    [$code, , $err] = powershell_run($cmd." -TaskName '".$name."' | Out-Null");
    if ($code === 0) return null;
    if (stripos($err, 'Access is denied') !== false || stripos($err, 'Odmowa dost') !== false || stripos($err, '0x80070005') !== false)
        return 'Brak uprawnień: Apache nie działa jako administrator. Uruchom w PowerShell jako administrator: '.scheduler_command($name, $action);
    return 'Harmonogram zadań: '.mb_substr($err ?: 'kod '.$code, 0, 300);
}
function scheduler_command(string $name, string $action): string {
    return ['enable' => 'Enable-ScheduledTask', 'disable' => 'Disable-ScheduledTask', 'run' => 'Start-ScheduledTask'][$action]." -TaskName '".$name."'";
}

const TASK_STATES = ['Ready' => ['Gotowe', 'b-green'], 'Running' => ['Uruchomione', 'b-blue'], 'Disabled' => ['Wyłączone', 'b-red'], 'Queued' => ['W kolejce', 'b-amber'], 'Unknown' => ['Nieznany', 'b-grey']];
function task_state_badge(string $state): string {
    [$label, $cls] = TASK_STATES[$state] ?? [$state, 'b-grey'];
    return '<span class="badge '.$cls.'">'.h($label).'</span>';
}
