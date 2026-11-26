<?php
// Ustawienia > Usługi: zatrzymanie/wznowienie odbiornika, Watchdoga i kopii zapasowej (np. przed aktualizacją)
// oraz wlaczanie/wylaczanie zadan SMS-Awarie w Harmonogramie zadan Windows.
require __DIR__.'/_layout.php';
require APP_ROOT.'/web/services.php';

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    $on = !empty($_POST['on']); // true = zatrzymaj / wstrzymaj
    try {
        if ($act === 'all') {
            foreach (array_keys(SERVICE_FLAGS) as $k) service_set($k, $on, $u['login']);
            audit($p, $u, 'services:all', $on ? 'stop' : 'start');
            if ($on) {
                flash(wait_receiver_stopped()
                    ? 'Zatrzymano odbiornik, wstrzymano Watchdoga i kopię zapasową. Możesz aktualizować pliki (scripts\install.ps1 -Apply), a potem kliknąć „Wznów wszystko”.'
                    : 'Wstrzymano usługi. Odbiornik jeszcze kończy bieżący krok – odśwież stronę za chwilę i sprawdź, czy ma stan „Zatrzymany”.', 'ok');
            } else {
                $note = scheduler_available() && scheduler_change(SERVICE_FLAGS['watchdog']['task'], 'run') === null ? ' Watchdog uruchomiony od razu.' : '';
                flash('Wznowiono usługi. Watchdog uruchomi odbiornik w ciągu ok. 1 minuty (do 5 minut po ostatnim uruchomieniu).'.$note);
            }
        } elseif ($act === 'flag' && isset(SERVICE_FLAGS[$_POST['key'] ?? ''])) {
            $key = (string)$_POST['key'];
            service_set($key, $on, $u['login']);
            audit($p, $u, 'services:'.$key, $on ? 'stop' : 'start');
            $msg = match ($key.($on ? ':stop' : ':start')) {
                'receiver:stop' => wait_receiver_stopped() ? 'Odbiornik zatrzymany. Watchdog nie uruchomi go ponownie, dopóki nie klikniesz „Uruchom”.' : 'Zatrzymywanie odbiornika – kończy bieżący krok. Odśwież stronę za chwilę.',
                'receiver:start' => 'Odbiornik zostanie uruchomiony przez Watchdoga w ciągu ok. 1 minuty (do 5 minut po ostatnim uruchomieniu).'
                    .(service_flag('watchdog') !== null ? ' Uwaga: Watchdog jest wstrzymany – wznów go, inaczej odbiornik nie wystartuje.' : ''),
                'watchdog:stop' => 'Watchdog wstrzymany: nie uruchamia ani nie restartuje odbiornika (działający odbiornik pracuje dalej).',
                'watchdog:start' => 'Watchdog wznowiony – od następnej minuty znów pilnuje odbiornika.',
                'backup:stop' => 'Kopia zapasowa wstrzymana: zadanie z Harmonogramu będzie ją pomijać. Kopię ręczną zrobisz w Ustawienia › Baza danych.',
                'backup:start' => 'Kopia zapasowa wznowiona.',
            };
            if (!$on && $key === 'receiver' && service_flag('watchdog') === null && scheduler_available() && scheduler_change(SERVICE_FLAGS['watchdog']['task'], 'run') === null) $msg = 'Odbiornik uruchamia się (Watchdog uruchomiony od razu).';
            flash($msg);
        } elseif ($act === 'task') {
            $name = (string)($_POST['name'] ?? '');
            $op = (string)($_POST['op'] ?? '');
            $err = scheduler_change($name, $op);
            audit($p, $u, 'services:task-'.$op, $name);
            if ($err) flash($err, 'err');
            else flash(['enable' => 'Włączono zadanie ', 'disable' => 'Wyłączono zadanie ', 'run' => 'Uruchomiono zadanie '][$op].$name.' w Harmonogramie zadań.');
        }
    } catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
    redirect('services');
}

$hb = heartbeat();
$recv = receiver_display_state($hb);
$wd = watchdog_state();
$flags = [];
foreach (array_keys(SERVICE_FLAGS) as $k) $flags[$k] = service_flag($k);
$tasks = scheduler_tasks();
$allStopped = $flags['receiver'] && $flags['watchdog'] && $flags['backup'];

// Karta zadania z Harmonogramu (stan, ostatnie/nastepne uruchomienie, przyciski).
function task_block($tasks, string $name, bool $runButton): void {
    if (is_string($tasks)) { echo '<p class="muted">'.h($tasks).'</p>'; return; }
    $t = $tasks[$name] ?? null;
    if (!$t) { echo '<p class="muted">Zadanie <code>'.h($name).'</code> nie jest zarejestrowane w Harmonogramie zadań.</p>'; return; }
    echo '<p>Harmonogram: '.task_state_badge((string)$t['state']).' <code>'.h($name).'</code></p>';
    echo '<p class="muted">Ostatnie uruchomienie: '.h($t['last'] ? fmt_dt($t['last']) : '–').($t['result'] !== null && $t['last'] ? ' (wynik '.h((string)$t['result']).')' : '')
        .' · następne: '.h($t['next'] ? fmt_dt($t['next']) : '–').($t['user'] ? ' · konto: '.h($t['user']) : '').'</p>';
    $disabled = $t['state'] === 'Disabled';
    echo '<div class="actions">';
    task_button($name, $disabled ? 'enable' : 'disable', $disabled ? 'Włącz w Harmonogramie' : 'Wyłącz w Harmonogramie', true);
    if ($runButton && !$disabled) task_button($name, 'run', 'Uruchom teraz', true);
    echo '</div>';
}
// Podpowiedz, gdy Watchdog nie zapisuje stanu (HTML, tresc stala).
function watchdog_hint(string $status, $tasks): string {
    if (!in_array($status, ['NO_DATA', 'SILENT', 'UNREADABLE'], true)) return '';
    if ($status === 'UNREADABLE') return 'Plik <code>data\\watchdog-state.json</code> jest nieczytelny. Usuń go – Watchdog zapisze nowy przy następnym uruchomieniu (co minutę).';
    $name = SERVICE_FLAGS['watchdog']['task'];
    $t = is_array($tasks) ? ($tasks[$name] ?? null) : null;
    $what = $status === 'NO_DATA' ? 'Watchdog jeszcze nie zapisał swojego stanu' : 'Watchdog nie sprawdzał odbiornika od ponad 5 minut';
    if (is_array($tasks) && !$t) return h($what).'. Zadanie <code>'.h($name).'</code> nie jest zarejestrowane – uruchom w PowerShell jako administrator <code>scripts\\install-watchdog-task.ps1</code>.';
    if ($t && $t['state'] === 'Disabled') return h($what).'. Zadanie jest wyłączone w Harmonogramie – kliknij „Włącz w Harmonogramie”.';
    if ($t && $t['last'] && (int)$t['result'] !== 0) return h($what).'. Ostatnie uruchomienie zadania zakończyło się kodem '.h((string)$t['result']).' – sprawdź konto zadania, ścieżkę do <code>watchdog.ps1</code> i plik <code>logs\\watchdog.log</code>.';
    return h($what).'. Sprawdź w Harmonogramie zadań, czy zadanie <code>'.h($name).'</code> uruchamia się co minutę, i plik <code>logs\\watchdog.log</code>.';
}
function task_button(string $name, string $op, string $label, bool $outline): void {
    echo '<form method="post" action="'.h(url('services')).'" class="inline">'.csrf_field()
        .'<input type="hidden" name="action" value="task"><input type="hidden" name="name" value="'.h($name).'"><input type="hidden" name="op" value="'.h($op).'">'
        .'<button class="btn'.($outline ? ' btn-o' : '').'">'.h($label).'</button></form>';
}
function flag_button(string $key, bool $on, string $label, bool $outline = false): void {
    echo '<form method="post" action="'.h(url('services')).'" class="inline">'.csrf_field()
        .'<input type="hidden" name="action" value="flag"><input type="hidden" name="key" value="'.h($key).'"><input type="hidden" name="on" value="'.($on ? '1' : '').'">'
        .'<button class="btn'.($outline ? ' btn-o' : '').'">'.h($label).'</button></form>';
}
function since(?string $flag): string {
    if ($flag === null) return '';
    $parts = explode(' ', $flag, 2);
    return '<p class="muted">Od '.h(fmt_dt($parts[0])).(isset($parts[1]) ? ' ('.h($parts[1]).')' : '').'</p>';
}

layout_start($title, $u, $page);
?>
<section class="card">
  <h2>Aktualizacja i prace serwisowe</h2>
  <p>Jednym kliknięciem zatrzymasz odbiornik i wstrzymasz Watchdoga oraz kopię zapasową – tak jak wymaga tego aktualizacja (<code>scripts\install.ps1 -Apply</code>). SMS-y przychodzące w tym czasie czekają w modemie, a wysyłki w kolejce.</p>
  <div class="actions">
    <form method="post" action="<?= h(url('services')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="all"><input type="hidden" name="on" value="1"><button class="btn"<?= $allStopped ? ' disabled' : '' ?>>Zatrzymaj wszystko</button></form>
    <form method="post" action="<?= h(url('services')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="all"><input type="hidden" name="on" value=""><button class="btn btn-o"<?= !array_filter($flags) ? ' disabled' : '' ?>>Wznów wszystko</button></form>
  </div>
  <?php if ($allStopped && $recv === 'STOPPED'): ?><p class="flash">Wszystkie usługi zatrzymane – można aktualizować.</p><?php endif; ?>
</section>

<div class="grid2 mt">
<section class="card">
  <h2>Odbiornik SMS</h2>
  <p>Stan: <?= badge($recv) ?><?php if (!empty($hb['raw']['pid']) && !in_array($recv, ['STOPPED', 'OFFLINE'], true)): ?> <small class="muted">PID <?= (int)$hb['raw']['pid'] ?></small><?php endif; ?></p>
  <?= since($flags['receiver']) ?>
  <p class="muted">Odbiera i wysyła SMS-y. Zatrzymanie następuje po zakończeniu bieżącego kroku (nigdy w trakcie zapisu ani wysyłki). Zatrzymanego odbiornika Watchdog nie uruchamia.</p>
  <div class="actions"><?php $flags['receiver'] ? flag_button('receiver', false, 'Uruchom') : flag_button('receiver', true, 'Zatrzymaj'); ?></div>
</section>

<section class="card">
  <h2>Watchdog</h2>
  <p>Stan: <?= $flags['watchdog'] ? badge('PAUSED') : badge($wd['status']) ?><?php if ($wd['checked'] !== ''): ?> <small class="muted">ostatnia kontrola <?= h(fmt_dt($wd['checked'])) ?></small><?php endif; ?></p>
  <?= since($flags['watchdog']) ?>
  <?php if (!$flags['watchdog'] && ($hint = watchdog_hint($wd['status'], $tasks))): ?><p class="flash flash-err"><?= $hint ?></p><?php endif; ?>
  <?php if (!empty($wd['raw']['lastProblem'])): ?><p class="muted">Ostatni problem: <?= h((string)$wd['raw']['lastProblem']) ?></p><?php endif; ?>
  <p class="muted">Co minutę sprawdza odbiornik i uruchamia go ponownie po awarii (maks. 3 razy na godzinę).</p>
  <div class="actions"><?php $flags['watchdog'] ? flag_button('watchdog', false, 'Wznów') : flag_button('watchdog', true, 'Wstrzymaj'); ?></div>
  <?php task_block($tasks, SERVICE_FLAGS['watchdog']['task'], true); ?>
</section>

<section class="card">
  <h2>Kopia zapasowa bazy</h2>
  <p>Stan: <?= $flags['backup'] ? badge('PAUSED') : badge('RUNNING') ?></p>
  <?= since($flags['backup']) ?>
  <p class="muted">Codzienna kopia bazy z Harmonogramu zadań. Wstrzymana – zadanie się uruchamia, ale pomija kopię.</p>
  <div class="actions"><?php $flags['backup'] ? flag_button('backup', false, 'Wznów') : flag_button('backup', true, 'Wstrzymaj'); ?></div>
  <?php task_block($tasks, SERVICE_FLAGS['backup']['task'], true); ?>
</section>

<?php $other = is_array($tasks) ? array_diff_key($tasks, array_flip([SERVICE_FLAGS['watchdog']['task'], SERVICE_FLAGS['backup']['task']])) : []; ?>
<?php if ($other): ?>
<section class="card">
  <h2>Inne zadania SMS-Awarie</h2>
  <?php foreach (array_keys($other) as $name) task_block($tasks, (string)$name, true); ?>
</section>
<?php endif; ?>
</div>

<section class="card mt">
  <h2>Wstrzymanie a wyłączenie w Harmonogramie</h2>
  <p class="muted">„Zatrzymaj” i „Wstrzymaj” działają zawsze (pliki <code>receiver.stop</code>, <code>watchdog.pause</code>, <code>backup.pause</code> w katalogu <code>data</code>) i przetrwają aktualizację oraz restart serwera. „Wyłącz w Harmonogramie” wyłącza samo zadanie Windows – wymaga, żeby Apache działał z uprawnieniami administratora; jeśli ich nie ma, panel pokaże polecenie do uruchomienia w PowerShell jako administrator.</p>
</section>
<?php layout_end();
