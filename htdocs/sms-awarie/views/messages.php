<?php
require __DIR__.'/_layout.php';

$f = ['status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$where = [];
$args = [];
if ($f['status'] !== '') { $where[] = 'status=?'; $args[] = $f['status']; }
if ($f['q'] !== '') { $where[] = '(sender LIKE ? OR content LIKE ?)'; array_push($args, "%{$f['q']}%", "%{$f['q']}%"); }
$w = $where ? ' WHERE '.implode(' AND ', $where) : '';
$total = (int)one($p, "SELECT COUNT(*) FROM sms$w", $args);
$list = rows($p, "SELECT sender,content,received,status FROM sms$w ORDER BY received DESC".sql_page($p, PER_PAGE, (page_no() - 1) * PER_PAGE), $args);
$statuses = array_column(rows($p, 'SELECT DISTINCT status FROM sms ORDER BY status'), 'status');

layout_start($title, $u, $page);
?>
<form method="get" class="filters card">
  <input type="hidden" name="p" value="messages">
  <label>Szukaj<input name="q" value="<?= h($f['q']) ?>" placeholder="telefon, treść"></label>
  <label>Status<select name="status"><option value="">Wszystkie</option><?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= h(STATUS_LABELS[$s] ?? $s) ?></option><?php endforeach; ?></select></label>
  <button class="btn">Filtruj</button>
</form>
<p class="muted">Niepoprawny – nie rozpoznano grupy lub obiektu. Błąd – wiadomość nie dała się przetworzyć (szczegóły w dzienniku odbiornika).</p>
<div class="table-wrap"><table>
<thead><tr><th>Odebrano</th><th>Nadawca</th><th>Treść</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($list as $r): ?>
  <tr><td><?= h(fmt_dt($r['received'])) ?></td><td><?= h($r['sender']) ?></td><td><?= h($r['content']) ?></td><td><?= badge($r['status']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="4" class="muted">Brak wiadomości.</td></tr><?php endif; ?>
</tbody></table></div>
<?= pager($total, 'messages', $f) ?>
<?php layout_end();
