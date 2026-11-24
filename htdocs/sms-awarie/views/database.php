<?php
// Ustawienia > Baza danych: stan bazy, kopie, polaczenie z MS SQL i przelaczanie SQLite <-> MS SQL.
require __DIR__.'/_layout.php';

$c = config();
$s = db_settings($c);
$mssql = db_is_mssql($p);
$dir = DATA_DIR.'/backups';

// Ustawienia MS SQL z formularza (haslo puste = bez zmian).
function mssql_form_settings(array $s): array {
    $n = $s;
    $n['server'] = trim((string)($_POST['server'] ?? ''));
    $n['database'] = trim((string)($_POST['database'] ?? ''));
    $n['user'] = trim((string)($_POST['user'] ?? ''));
    if (($_POST['pass'] ?? '') !== '') $n['pass'] = (string)$_POST['pass'];
    if ($n['user'] === '') $n['pass'] = '';
    $n['encrypt'] = !empty($_POST['encrypt']);
    $n['trust_cert'] = !empty($_POST['trust_cert']);
    $n['backup_dir'] = trim((string)($_POST['backup_dir'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\\\\:,-]{1,200}$/D', $n['server'])) throw new RuntimeException('Serwer: np. localhost\\SQLEXPRESS, 192.168.1.10,1433 (bez spacji i średników).');
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $n['database'])) throw new RuntimeException('Nazwa bazy: litery, cyfry i _ (zaczyna się literą), np. SMSAwarie.');
    if ($n['user'] !== '' && !preg_match('/^[A-Za-z0-9_.@\\\\-]{1,128}$/D', $n['user'])) throw new RuntimeException('Login SQL: litery, cyfry i . _ @ - \\');
    if (str_contains($n['backup_dir'], "'")) throw new RuntimeException('Katalog kopii nie może zawierać apostrofu.');
    return $n;
}

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    set_time_limit(600);
    try {
        if ($act === 'backup') {
            if ($mssql) {
                $file = mssql_backup($p, $s, 'manual');
                audit($p, $u, 'database:backup', $file);
                flash('Kopia MS SQL utworzona i sprawdzona (RESTORE VERIFYONLY): '.$file);
            } else {
                if (!is_dir($dir)) mkdir($dir, 0700, true);
                $dest = $dir.'/manual-'.date('Ymd-His').'.sqlite';
                $p->exec('VACUUM INTO '.$p->quote($dest));
                $ok = (new PDO('sqlite:'.$dest))->query('PRAGMA integrity_check')->fetchColumn() === 'ok';
                audit($p, $u, 'database:backup', basename($dest));
                flash($ok ? 'Utworzono kopię '.basename($dest).'.' : 'Kopia utworzona, ale integrity_check nie zwrócił ok!', $ok ? 'ok' : 'err');
            }
        } elseif ($act === 'check') {
            if ($mssql) { $p->exec('DBCC CHECKDB(['.$s['database'].']) WITH NO_INFOMSGS'); flash('DBCC CHECKDB: brak błędów.'); }
            else { $r = (string)$p->query('PRAGMA integrity_check')->fetchColumn(); flash('PRAGMA integrity_check: '.$r, $r === 'ok' ? 'ok' : 'err'); }
        } elseif ($act === 'mssql-save') {
            $n = mssql_form_settings($s);
            $c['db'] = $n; saveconfig($c);
            unset($_SESSION['mssql_test']);
            $created = !empty($_POST['create']) && mssql_create_database(['driver' => 'sqlsrv'] + $n);
            $t = db_connect(['driver' => 'sqlsrv'] + $n);
            $counts = db_counts($t);
            $_SESSION['mssql_test'] = ['sig' => db_signature(['driver' => 'sqlsrv'] + $n), 'counts' => $counts, 'schema' => schema_version($t),
                'version' => (string)$t->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS NVARCHAR(64)) + ' ' + CAST(SERVERPROPERTY('Edition') AS NVARCHAR(128))")->fetchColumn()];
            audit($p, $u, 'database:mssql-test', $n['server'].'/'.$n['database']);
            flash(($created ? 'Utworzono bazę '.$n['database'].'. ' : '').'Połączenie z MS SQL działa.');
        } elseif ($act === 'switch') {
            $mode = (string)($_POST['mode'] ?? '');
            if (!in_array($mode, ['copy', 'empty', 'existing'], true)) throw new RuntimeException('Wybierz, co zrobić z danymi.');
            $n = ['driver' => 'sqlsrv'] + $s; $n['driver'] = 'sqlsrv';
            $dst = db_connect($n);
            $counts = db_counts($dst);
            $has = db_has_data($counts);
            if ($mode !== 'existing' && $has) throw new RuntimeException('Baza '.$n['database'].' zawiera już dane SMS-Awarie. Wybierz „Użyj danych z bazy MS SQL” albo wskaż pustą bazę.');
            if ($mode === 'existing' && !$has) throw new RuntimeException('W bazie '.$n['database'].' nie ma danych SMS-Awarie – wybierz przeniesienie danych albo pustą bazę.');
            if (!receiver_pause()) throw new RuntimeException('Odbiornik nie wstrzymał się w ciągu 60 s. Spróbuj ponownie za chwilę.');
            try {
                ensureSchema($dst);
                if ($mode === 'copy') { $done = db_copy($p, $dst); $msg = 'Przeniesiono dane: zgłoszenia '.$done['tickets'].', SMS '.$done['sms'].', wychodzące '.$done['outbox'].', konta '.$done['web_users'].'.'; }
                elseif ($mode === 'empty') { $done = db_copy($p, $dst, ['web_users']); groupsSeed($dst); $msg = 'Pusta baza: przeniesiono tylko konta użytkowników ('.$done['web_users'].').'; }
                else { $msg = 'Używasz danych z bazy MS SQL (zgłoszenia: '.$counts['tickets'].').'; }
                $c = config(); $c['db'] = $n; saveconfig($c);
                audit($dst, $u, 'database:switch', 'sqlite -> mssql '.$mode);
            } finally { receiver_resume(); }
            unset($_SESSION['mssql_test']);
            flash('Przełączono na MS SQL ('.$n['server'].' / '.$n['database'].'). '.$msg.' Odbiornik połączy się z nową bazą w najbliższym cyklu. Plik SQLite pozostał bez zmian.');
        } elseif ($act === 'to-sqlite') {
            if (!$mssql) throw new RuntimeException('Aplikacja już używa SQLite.');
            if (!receiver_pause()) throw new RuntimeException('Odbiornik nie wstrzymał się w ciągu 60 s. Spróbuj ponownie za chwilę.');
            try {
                $c = config(); $c['db'] = ['driver' => 'sqlite'] + $s; $c['db']['driver'] = 'sqlite'; saveconfig($c);
                $sq = db($c['db']); ensureSchema($sq);
                audit($sq, $u, 'database:switch', 'mssql -> sqlite');
            } finally { receiver_resume(); }
            flash('Przełączono na SQLite. Dane zapisane w MS SQL po przełączeniu nie zostały skopiowane – są nadal w bazie '.$s['database'].'.');
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) error_log('SMS-Awarie baza: '.$e->getMessage());
        flash($e->getMessage(), 'err');
    }
    redirect('database');
}

$counts = db_counts($p);
$labels = ['tickets' => 'Zgłoszenia', 'sms' => 'SMS odebrane', 'outbox' => 'SMS wychodzące', 'vehicles' => 'Pojazdy', 'incident_groups' => 'Grupy', 'web_users' => 'Użytkownicy'];
$backups = [];
if ($mssql) {
    try { $bdir = mssql_backup_dir($p, $s); } catch (Throwable $e) { $bdir = ''; }
    $pattern = $bdir !== '' && is_dir($bdir) ? $bdir.'/*.bak' : null;
} else {
    $bdir = $dir;
    $pattern = $dir.'/*.sqlite';
}
foreach ($pattern ? (glob($pattern) ?: []) : [] as $f) $backups[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
usort($backups, fn($a, $b) => $b['time'] <=> $a['time']);
$test = $_SESSION['mssql_test'] ?? null;
if ($test && $test['sig'] !== db_signature(['driver' => 'sqlsrv'] + $s)) $test = null;
$testHas = $test ? db_has_data($test['counts']) : false;
$sqlsrvOk = extension_loaded('pdo_sqlsrv') || $s['pdo'] === 'odbc';

layout_start($title, $u, $page);
?>
<div class="grid2">
<section class="card">
  <h2>Aktualna baza: <?= $mssql ? 'MS SQL' : 'SQLite' ?></h2>
  <?php if ($mssql): ?><p><code><?= h($s['server']) ?> / <?= h($s['database']) ?></code> · schemat v<?= schema_version($p) ?></p>
  <?php else: $dbFile = DATA_DIR.'/awarie.sqlite'; ?><p><code><?= h(str_replace('/', '\\', $dbFile)) ?></code> · <?= is_file($dbFile) ? h(fmt_bytes(filesize($dbFile))) : '' ?> · schemat v<?= schema_version($p) ?></p><?php endif; ?>
  <table><tbody><?php foreach ($labels as $t => $label): ?><tr><td><?= h($label) ?></td><td class="num"><?= number_format((int)$counts[$t], 0, ',', ' ') ?></td></tr><?php endforeach; ?></tbody></table>
  <div class="actions">
    <form method="post" action="<?= h(url('database')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn">Utwórz kopię teraz</button></form>
    <form method="post" action="<?= h(url('database')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="check"><button class="btn btn-o">Sprawdź integralność</button></form>
  </div>
</section>
<section class="card">
  <h2>Kopie<?= $bdir !== '' ? ' w '.h(str_replace('/', '\\', $bdir)) : '' ?></h2>
  <p class="muted">Codzienne kopie (daily-*) tworzy zadanie SMS-Awarie-v3-Backup<?= $mssql ? ' poleceniem BACKUP DATABASE na serwerze SQL' : '' ?>. Przechowuj kopie także na innym dysku.</p>
  <?php if ($mssql && $pattern === null): ?><p class="muted">Katalog kopii jest na serwerze SQL i nie jest widoczny z tego komputera – listę plików sprawdzisz na serwerze.</p>
  <?php elseif (!$backups): ?><p class="flash flash-err">Brak kopii. Zainstaluj zadanie backupu: scripts\install-backup-task.ps1</p><?php else: ?>
  <table><thead><tr><th>Plik</th><th>Rozmiar</th><th>Data</th></tr></thead><tbody>
  <?php foreach (array_slice($backups, 0, 40) as $b): ?><tr><td><?= h($b['name']) ?></td><td class="num"><?= h(fmt_bytes($b['size'])) ?></td><td><?= h(date('d.m.Y H:i', $b['time'])) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
</div>

<div class="grid2">
<section class="card">
  <h2>Połączenie z MS SQL Server</h2>
  <?php if (!$sqlsrvOk): ?><p class="flash flash-err">PHP nie ma rozszerzenia pdo_sqlsrv. Zainstaluj Microsoft Drivers for PHP i ODBC Driver 18 (docs\MSSQL.md), potem zrestartuj Apache.</p><?php endif; ?>
  <form method="post" action="<?= h(url('database')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="mssql-save">
    <div class="grid2">
      <label>Serwer<input name="server" value="<?= h($s['server']) ?>" required placeholder="localhost\SQLEXPRESS"></label>
      <label>Baza danych<input name="database" value="<?= h($s['database']) ?>" required pattern="[A-Za-z][A-Za-z0-9_]{0,63}"></label>
      <label>Login SQL (puste = konto Windows)<input name="user" value="<?= h($s['user']) ?>" autocomplete="off"></label>
      <label>Hasło (puste = bez zmian)<input name="pass" type="password" autocomplete="new-password"></label>
    </div>
    <label>Katalog kopii na serwerze SQL (puste = domyślny instancji)<input name="backup_dir" value="<?= h($s['backup_dir']) ?>" placeholder="D:\SQLBackup\SMS-Awarie"></label>
    <label class="check"><input type="checkbox" name="encrypt" value="1"<?= $s['encrypt'] ? ' checked' : '' ?>> Szyfruj połączenie</label>
    <label class="check"><input type="checkbox" name="trust_cert" value="1"<?= $s['trust_cert'] ? ' checked' : '' ?>> Ufaj certyfikatowi serwera (typowe dla SQL Express w sieci firmowej)</label>
    <label class="check"><input type="checkbox" name="create" value="1"> Utwórz bazę, jeśli nie istnieje (wymaga uprawnienia dbcreator)</label>
    <p class="muted">Zalecany osobny login SQL z rolą db_owner w tej bazie. Konto Windows działa tylko, gdy Apache i odbiornik działają na koncie domenowym z dostępem do SQL Server.</p>
    <button class="btn">Zapisz i sprawdź połączenie</button>
  </form>
</section>

<section class="card">
<?php if ($mssql): ?>
  <h2>Powrót do SQLite</h2>
  <p>Aplikacja używa bazy MS SQL. Możesz wrócić do lokalnego pliku SQLite – będzie w nim stan z chwili przełączenia na MS SQL. Zgłoszenia i SMS zapisane od tamtej pory zostaną tylko w MS SQL.</p>
  <form method="post" action="<?= h(url('database')) ?>" data-confirm="Przełączyć aplikację z powrotem na SQLite? Dane z MS SQL nie zostaną skopiowane."><?= csrf_field() ?><input type="hidden" name="action" value="to-sqlite"><button class="btn btn-o">Przełącz na SQLite</button></form>
<?php elseif (!$test): ?>
  <h2>Przełączenie na MS SQL</h2>
  <p class="muted">Najpierw zapisz ustawienia i sprawdź połączenie. Potem wybierzesz, czy przenieść obecne dane.</p>
<?php else: ?>
  <h2>Przełączenie na MS SQL</h2>
  <p>Serwer: <strong><?= h($test['version']) ?></strong><br>Baza <code><?= h($s['database']) ?></code>:
  <?php if ($test['counts']['tickets'] === null): ?>pusta (bez tabel SMS-Awarie).
  <?php else: ?>schemat v<?= (int)$test['schema'] ?>, zgłoszenia <?= (int)$test['counts']['tickets'] ?>, SMS <?= (int)$test['counts']['sms'] ?>, konta <?= (int)$test['counts']['web_users'] ?>.<?php endif; ?></p>
  <form method="post" action="<?= h(url('database')) ?>" class="opts" data-confirm="Przełączyć aplikację na MS SQL? Odbiornik zostanie na chwilę wstrzymany.">
    <?= csrf_field() ?><input type="hidden" name="action" value="switch">
    <?php if (!$testHas): ?>
    <label class="check"><input type="radio" name="mode" value="copy" checked> <span><strong>Przenieś obecne dane</strong><small>Zgłoszenia, SMS, kolejka, pojazdy, grupy, konta i dziennik zostaną skopiowane z SQLite (<?= (int)$counts['tickets'] ?> zgłoszeń).</small></span></label>
    <label class="check"><input type="radio" name="mode" value="empty"> <span><strong>Zacznij od pustej bazy</strong><small>Bez zgłoszeń, SMS, pojazdów i grup. Przeniesione zostaną tylko konta użytkowników, żeby można było się zalogować.</small></span></label>
    <?php else: ?>
    <label class="check"><input type="radio" name="mode" value="existing" checked> <span><strong>Użyj danych z bazy MS SQL</strong><small>Baza ma już dane SMS-Awarie. Dane z SQLite nie zostaną skopiowane. Zaloguj się kontem zapisanym w MS SQL.</small></span></label>
    <?php endif; ?>
    <p class="muted">Odbiornik zostanie wstrzymany na czas przełączenia (zwykle kilka–kilkanaście sekund). Plik SQLite zostaje bez zmian, więc można do niego wrócić.</p>
    <button class="btn">Przełącz na MS SQL</button>
  </form>
<?php endif; ?>
</section>
</div>
<?php layout_end();
