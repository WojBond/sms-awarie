<?php
require __DIR__.'/_layout.php';

$dir = DATA_DIR.'/backups';
if ($post) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'backup') {
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $dest = $dir.'/manual-'.date('Ymd-His').'.sqlite';
        $p->exec('VACUUM INTO '.$p->quote($dest));
        $ok = (new PDO('sqlite:'.$dest))->query('PRAGMA integrity_check')->fetchColumn() === 'ok';
        audit($p, $u, 'database:backup', basename($dest));
        flash($ok ? 'Utworzono kopię '.basename($dest).'.' : 'Kopia utworzona, ale integrity_check nie zwrócił ok!', $ok ? 'ok' : 'err');
    } elseif ($act === 'check') {
        $r = (string)$p->query('PRAGMA integrity_check')->fetchColumn();
        flash('PRAGMA integrity_check: '.$r, $r === 'ok' ? 'ok' : 'err');
    }
    redirect('database');
}

$counts = [];
foreach (['tickets' => 'Zgłoszenia', 'sms' => 'SMS odebrane', 'outbox' => 'SMS wychodzące', 'vehicles' => 'Pojazdy', 'incident_groups' => 'Grupy', 'web_users' => 'Użytkownicy'] as $t => $label) $counts[$label] = (int)one($p, "SELECT COUNT(*) FROM $t");
$dbFile = DATA_DIR.'/awarie.sqlite';
$backups = [];
foreach (glob($dir.'/*.sqlite') ?: [] as $f) $backups[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
usort($backups, fn($a, $b) => $b['time'] <=> $a['time']);

layout_start($title, $u, $page);
?>
<div class="grid2">
<section class="card">
  <h2>Baza SQLite</h2>
  <p><code><?= h(str_replace('/', '\\', $dbFile)) ?></code> · <?= is_file($dbFile) ? h(fmt_bytes(filesize($dbFile))) : '' ?> · schemat v<?= (int)one($p, 'PRAGMA user_version') ?></p>
  <table><tbody><?php foreach ($counts as $k => $v): ?><tr><td><?= h($k) ?></td><td class="num"><?= number_format($v, 0, ',', ' ') ?></td></tr><?php endforeach; ?></tbody></table>
  <div class="actions">
    <form method="post" action="<?= h(url('database')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn">Utwórz kopię teraz</button></form>
    <form method="post" action="<?= h(url('database')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="check"><button class="btn btn-o">Sprawdź integralność</button></form>
  </div>
</section>
<section class="card">
  <h2>Kopie w <?= h(str_replace('/', '\\', $dir)) ?></h2>
  <p class="muted">Codzienne kopie (daily-*) tworzy zadanie SMS-Awarie-v3-Backup. Przechowuj kopie także na innym dysku.</p>
  <?php if (!$backups): ?><p class="flash flash-err">Brak kopii. Zainstaluj zadanie backupu: scripts\install-backup-task.ps1</p><?php else: ?>
  <table><thead><tr><th>Plik</th><th>Rozmiar</th><th>Data</th></tr></thead><tbody>
  <?php foreach (array_slice($backups, 0, 40) as $b): ?><tr><td><?= h($b['name']) ?></td><td class="num"><?= h(fmt_bytes($b['size'])) ?></td><td><?= h(date('d.m.Y H:i', $b['time'])) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
</div>
<?php layout_end();
