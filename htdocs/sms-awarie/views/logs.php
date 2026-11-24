<?php
require __DIR__.'/_layout.php';

$total = (int)one($p, 'SELECT COUNT(*) FROM web_audit');
$audit = rows($p, 'SELECT created,actor,action,target FROM web_audit ORDER BY id DESC'.sql_page($p, PER_PAGE, (page_no() - 1) * PER_PAGE));
$receiver = array_merge(tailLines(LOG_DIR.'/receiver-stderr.log', 20), tailLines(LOG_DIR.'/receiver-stdout.log', 100));
$events = [];
foreach (array_reverse(tailLines(LOG_DIR.'/watchdog-events.jsonl', 30)) as $line) {
    $e = json_decode($line, true);
    if (is_array($e) && isset($e['event'], $e['time'])) $events[] = $e;
}

layout_start($title, $u, $page);
?>
<section class="card">
  <h2>Działania użytkowników</h2>
  <div class="table-wrap"><table>
  <thead><tr><th>Data</th><th>Użytkownik</th><th>Akcja</th><th>Obiekt</th></tr></thead><tbody>
  <?php foreach ($audit as $r): ?><tr><td><?= h(fmt_dt($r['created'])) ?></td><td><?= h($r['actor']) ?></td><td><?= h($r['action']) ?></td><td><?= h($r['target']) ?></td></tr><?php endforeach; ?>
  <?php if (!$audit): ?><tr><td colspan="4" class="muted">Brak wpisów.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?= pager($total, 'logs', []) ?>
</section>
<section class="card">
  <h2>Watchdog – ostatnie zdarzenia</h2>
  <?php if (!$events): ?><p class="muted">Brak zdarzeń.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Czas</th><th>Zdarzenie</th><th>PID</th><th>Szczegóły</th></tr></thead><tbody>
  <?php foreach ($events as $e): ?><tr><td><?= h(fmt_dt($e['time'])) ?></td><td><?= badge((string)$e['event']) ?></td><td><?= (int)($e['pid'] ?? 0) ?></td><td><?= h(mb_strimwidth((string)($e['detail'] ?? ''), 0, 300, '…')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Odbiornik – ostatnie linie logu</h2>
  <pre class="log"><?= h($receiver ? implode("\n", $receiver) : 'Brak logów (logs\receiver-stdout.log).') ?></pre>
</section>
<?php layout_end();
