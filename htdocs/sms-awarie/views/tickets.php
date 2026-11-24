<?php
require __DIR__.'/_layout.php';

if ($post) {
    $id = (string)($_POST['id'] ?? '');
    $status = (string)($_POST['status'] ?? '');
    $desc = trim((string)($_POST['description'] ?? ''));
    if (!isset(TICKET_STATUSES[$status]) || $desc === '' || mb_strlen($desc) > 4000) {
        flash('Niepoprawne dane zgłoszenia.', 'err');
    } else {
        $q = $p->prepare('UPDATE tickets SET status=?,description=? WHERE id=?');
        $q->execute([$status, $desc, $id]);
        if ($q->rowCount()) { audit($p, $u, 'tickets:edit', $id.' '.$status); flash('Zapisano zgłoszenie.'); }
        else flash('Nie znaleziono zgłoszenia.', 'err');
    }
    redirect('tickets', array_intersect_key($_GET, array_flip(['status', 'group', 'q', 'n'])));
}

$f = ['status' => (string)($_GET['status'] ?? ''), 'group' => (string)($_GET['group'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$where = [];
$args = [];
if ($f['status'] !== '') { $where[] = 't.status=?'; $args[] = $f['status']; }
if ($f['group'] !== '') { $where[] = 'tg.group_id=?'; $args[] = $f['group']; }
if ($f['q'] !== '') { $where[] = '(t.plate LIKE ? OR t.description LIKE ? OR s.sender LIKE ?)'; array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
$from = "FROM tickets t LEFT JOIN ticket_groups tg ON tg.ticket_id=t.id LEFT JOIN incident_groups g ON g.id=tg.group_id LEFT JOIN sms s ON s.id=t.sms_id".($where ? ' WHERE '.implode(' AND ', $where) : '');
$select = "SELECT t.id,t.plate,t.description,t.status,t.created,t.seq,COALESCE(g.name,'Pojazdy') AS group_name,s.sender,s.content AS sms_content $from ORDER BY t.created DESC";

if (($_GET['export'] ?? '') === 'csv') {
    $out = [];
    foreach (rows($p, $select, $args) as $r) {
        $out[] = [ticketNumberFrom($r['id'], $r['created'], $r['seq'] !== null ? (int)$r['seq'] : null), $r['group_name'], $r['plate'], $r['description'], TICKET_STATUSES[$r['status']] ?? $r['status'], fmt_dt($r['created']), $r['sender']];
    }
    csv_out('zgloszenia-'.date('Y-m-d').'.csv', ['Numer', 'Grupa', 'Obiekt', 'Opis', 'Status', 'Utworzono', 'Zgłaszający'], $out);
}

$total = (int)one($p, "SELECT COUNT(*) $from", $args);
$list = rows($p, $select.' LIMIT '.PER_PAGE.' OFFSET '.((page_no() - 1) * PER_PAGE), $args);
$groups = rows($p, 'SELECT id,name FROM incident_groups ORDER BY name');

layout_start($title, $u, $page);
?>
<form method="get" class="filters card">
  <input type="hidden" name="p" value="tickets">
  <label>Szukaj<input name="q" value="<?= h($f['q']) ?>" placeholder="obiekt, opis, telefon"></label>
  <label>Status<select name="status"><option value="">Wszystkie</option><?php foreach (TICKET_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
  <label>Grupa<select name="group"><option value="">Wszystkie</option><?php foreach ($groups as $g): ?><option value="<?= h($g['id']) ?>"<?= $f['group'] === $g['id'] ? ' selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn">Filtruj</button>
  <a class="btn btn-o" href="<?= h(url('tickets', $f + ['export' => 'csv'])) ?>">Eksport CSV</a>
</form>
<?php if (!$list): ?><p class="muted">Brak zgłoszeń.</p><?php endif; ?>
<?php foreach ($list as $r): $num = ticketNumberFrom($r['id'], $r['created'], $r['seq'] !== null ? (int)$r['seq'] : null); ?>
<details class="card item">
  <summary>
    <strong><?= h($num) ?></strong> <?= badge($r['status']) ?>
    <span><?= h($r['group_name']) ?> · <?= h($r['plate']) ?></span>
    <span class="desc"><?= h(mb_strimwidth($r['description'], 0, 90, '…')) ?></span>
    <small class="muted"><?= h(fmt_dt($r['created'])) ?></small>
  </summary>
  <p class="muted">Zgłaszający: <?= h($r['sender']) ?> · treść SMS: „<?= h($r['sms_content']) ?>”</p>
  <form method="post" action="<?= h(url('tickets', $f + ['n' => page_no()])) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= h($r['id']) ?>">
    <label>Opis<textarea name="description" rows="3" maxlength="4000" required<?= can_write($u) ? '' : ' readonly' ?>><?= h($r['description']) ?></textarea></label>
    <label>Status<select name="status"<?= can_write($u) ? '' : ' disabled' ?>><?php foreach (TICKET_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
    <?php if (can_write($u)): ?><button class="btn">Zapisz</button><?php endif; ?>
  </form>
</details>
<?php endforeach; ?>
<?= pager($total, 'tickets', $f) ?>
<?php layout_end();
