<?php
require __DIR__.'/_layout.php';

$hb = heartbeat();
// [etykieta, liczba, link do przefiltrowanej listy, kolor paska]
$kpis = [
    ['Nowe', one($p, "SELECT COUNT(*) FROM tickets WHERE status='NOWE'"), url('tickets', ['status' => 'NOWE']), 'k-blue'],
    ['Weryfikacja', one($p, "SELECT COUNT(*) FROM tickets WHERE status='WERYFIKACJA'"), url('tickets', ['status' => 'WERYFIKACJA']), 'k-amber'],
    ['W realizacji', one($p, "SELECT COUNT(*) FROM tickets WHERE status='W_REALIZACJI'"), url('tickets', ['status' => 'W_REALIZACJI']), 'k-violet'],
    ['SMS oczekujące', one($p, "SELECT COUNT(*) FROM outbox WHERE status='OCZEKUJE'"), url('outbox', ['status' => 'OCZEKUJE']), 'k-green'],
];
$groupStats = rows($p, 'SELECT g.id,g.name,COUNT(tg.ticket_id) AS total FROM incident_groups g LEFT JOIN ticket_groups tg ON tg.group_id=g.id WHERE g.active=1 GROUP BY g.id,g.name ORDER BY g.name');
$open = rows($p, "SELECT t.id,t.plate,t.description,t.status,t.created,t.seq,COALESCE(g.name,'Pojazdy') AS group_name FROM tickets t LEFT JOIN ticket_groups tg ON tg.ticket_id=t.id LEFT JOIN incident_groups g ON g.id=tg.group_id WHERE t.status IN ('NOWE','WERYFIKACJA') ORDER BY t.created DESC".sql_page($p, 10));
$verify = rows($p, "SELECT phone,content,status,created,detail FROM outbox WHERE status IN ('W_TRAKCIE','NIEPEWNY') ORDER BY created DESC".sql_page($p, 20));
$modem = $hb['raw']['modem'] ?? null;
$wd = is_admin($u) ? @json_decode((string)@file_get_contents(DATA_DIR.'/watchdog-state.json'), true) : null;

// Odswiezany fragment (app.js pobiera ?p=dashboard&partial=1 co 10 s).
ob_start(); ?>
<div class="kpis">
<?php foreach ($kpis as [$k, $v, $href, $cls]): ?>
  <a class="card kpi <?= $cls ?>" href="<?= h($href) ?>"><span><?= h($k) ?></span><strong><?= (int)$v ?></strong></a>
<?php endforeach; ?>
</div>
<div class="grid2">
  <section class="card">
    <h2>Odbiornik SMS</h2>
    <p>Stan: <?= badge($hb['state']) ?> <small class="muted">(na podstawie heartbeat, nie aktywny test modemu)</small></p>
    <?php if (!empty($hb['raw']['lastError'])): ?><p class="flash flash-err">Ostatni błąd: <?= h($hb['raw']['lastError']) ?></p><?php endif; ?>
    <?php if (is_array($modem) && (int)($modem['max'] ?? 0) > 0): $full = (int)$modem['inbox'] >= 0.8 * (int)$modem['max']; ?>
      <p>Pamięć SMS modemu: <strong class="<?= $full ? 'txt-red' : '' ?>"><?= (int)$modem['inbox'] ?> / <?= (int)$modem['max'] ?></strong></p>
    <?php endif; ?>
    <?php if (is_admin($u)): ?>
      <?php if (is_array($wd)): ?>
        <p>Watchdog: <?= badge((string)($wd['status'] ?? 'UNKNOWN')) ?> · ostatnia kontrola <?= h(fmt_dt($wd['checked'] ?? '')) ?> · restarty w ostatniej godzinie: <?= count((array)($wd['restartHistory'] ?? [])) ?> / 3</p>
        <?php if (!empty($wd['lastProblem'])): ?><p class="muted">Ostatni problem: <?= h($wd['lastProblem']) ?></p><?php endif; ?>
      <?php else: ?><p class="muted">Brak danych Watchdoga.</p><?php endif; ?>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Zgłoszenia według grup</h2>
    <p class="chips"><?php foreach ($groupStats as $g): ?><a class="chip" href="<?= h(url('group', ['id' => $g['id']])) ?>"><?= h($g['name']) ?> <strong><?= (int)$g['total'] ?></strong></a><?php endforeach; ?></p>
  </section>
</div>
<?php if ($verify): ?>
<section class="card warn">
  <h2>Wysyłki do ręcznej weryfikacji</h2>
  <p class="muted">Statusy W_TRAKCIE i NIEPEWNY nie są ponawiane automatycznie. Sprawdź w modemie, czy SMS wyszedł.</p>
  <div class="table-wrap"><table><thead><tr><th>Utworzono</th><th>Telefon</th><th>Status</th><th>Treść</th><th>Szczegóły</th></tr></thead><tbody>
  <?php foreach ($verify as $r): ?><tr><td><?= h(fmt_dt($r['created'])) ?></td><td><?= h($r['phone']) ?></td><td><?= badge($r['status']) ?></td><td><?= h($r['content']) ?></td><td><?= h($r['detail']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<section class="card">
  <div class="card-head"><h2>Otwarte zgłoszenia</h2><a class="btn btn-o" href="<?= h(url('tickets')) ?>">Wszystkie zgłoszenia</a></div>
  <?php if (!$open): ?><p class="muted">Brak nowych zgłoszeń ani zgłoszeń do weryfikacji.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Numer</th><th>Grupa</th><th>Obiekt</th><th>Opis</th><th>Status</th><th>Utworzono</th></tr></thead><tbody>
  <?php foreach ($open as $r): ?>
    <tr><td><a href="<?= h(url('tickets', ['q' => $r['plate']])) ?>"><?= h(ticketNumberFrom($r['id'], $r['created'], $r['seq'] !== null ? (int)$r['seq'] : null)) ?></a></td><td><?= h($r['group_name']) ?></td><td><?= h($r['plate']) ?></td><td><?= h($r['description']) ?></td><td><?= badge($r['status']) ?></td><td><?= h(fmt_dt($r['created'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php
$live = ob_get_clean();
if (!empty($_GET['partial'])) { header('Cache-Control: no-store'); echo $live; exit; }
layout_start($title, $u, $page);
if (is_admin($u)) echo '<p id="exposure" class="flash flash-err" data-check="data/config.json" hidden>Katalog data jest dostępny z przeglądarki (baza i hasło modemu)! W httpd.conf ustaw AllowOverride All dla htdocs i zrestartuj Apache.</p>';
echo '<div id="live" data-refresh="'.h(url('dashboard', ['partial' => 1])).'">'.$live.'</div>';
layout_end();
