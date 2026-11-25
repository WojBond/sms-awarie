<?php
require __DIR__.'/_layout.php';

[$of, $oa] = scope_filter($p, $u, 'outbox', 'o');
if ($post) {
    $act = (string)($_POST['action'] ?? '');
    // 4.5.0: wysylka i anulowanie tylko z uprawnieniem "Wysylka SMS"; anulowanie w zakresie widocznych wiadomosci.
    if (!can_send($u)) { flash('Brak uprawnień do wysyłki SMS.', 'err'); redirect('outbox'); }
    if ($act === 'cancel') {
        $id = (string)($_POST['id'] ?? '');
        $q = $p->prepare("UPDATE outbox SET status='ANULOWANY' WHERE id=? AND status='OCZEKUJE' AND id IN (SELECT o.id FROM outbox o WHERE o.id=?".($of !== '' ? " AND $of" : '').')');
        $q->execute(array_merge([$id, $id], $oa));
        if ($q->rowCount()) { audit($p, $u, 'outbox:cancel', $id); flash('Anulowano SMS.'); }
        else flash('SMS został już wysłany lub anulowany.', 'err');
    } elseif ($act === 'send') {
        if (time() - ($_SESSION['last_send'] ?? 0) < 10) {
            flash('Odczekaj 10 sekund przed kolejnym SMS.', 'err');
        } else {
            try {
                $id = uid();
                queueSms($p, $id, trim((string)($_POST['phone'] ?? '')), trim((string)($_POST['content'] ?? '')));
                $_SESSION['last_send'] = time();
                audit($p, $u, 'outbox:send', $id);
                flash('SMS dodany do kolejki. Odbiornik wyśle go w najbliższym cyklu.');
            } catch (Exception $e) { flash($e->getMessage(), 'err'); }
        }
    }
    redirect('outbox');
}

$f = ['status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$where = [];
$args = [];
if ($of !== '') { $where[] = $of; $args = $oa; }
if ($f['status'] !== '') { $where[] = 'o.status=?'; $args[] = $f['status']; }
if ($f['q'] !== '') { $where[] = '(o.phone LIKE ? OR o.content LIKE ?)'; array_push($args, "%{$f['q']}%", "%{$f['q']}%"); }
$w = $where ? ' WHERE '.implode(' AND ', $where) : '';
$total = (int)one($p, "SELECT COUNT(*) FROM outbox o$w", $args);
$list = rows($p, "SELECT o.id,o.phone,o.content,o.status,o.created,o.detail FROM outbox o$w ORDER BY o.created DESC".sql_page($p, PER_PAGE, (page_no() - 1) * PER_PAGE), $args);

layout_start($title, $u, $page);
?>
<?php if (can_send($u)): ?>
<details class="card">
  <summary><strong>Nowy SMS</strong></summary>
  <form method="post" action="<?= h(url('outbox')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="send">
    <label>Telefon<input name="phone" required pattern="\+?[0-9]{9,15}" inputmode="tel" placeholder="+48600100200"></label>
    <label>Treść (maks. 150 znaków)<textarea name="content" rows="2" maxlength="150" required></textarea></label>
    <p class="muted">Polskie litery są zamieniane na łacińskie (ą → a). Status PRZYJETY oznacza przyjęcie przez modem, nie doręczenie.</p>
    <button class="btn">Dodaj do kolejki</button>
  </form>
</details>
<?php endif; ?>
<form method="get" class="filters card">
  <input type="hidden" name="p" value="outbox">
  <label>Szukaj<input name="q" value="<?= h($f['q']) ?>" placeholder="telefon, treść"></label>
  <label>Status<select name="status"><option value="">Wszystkie</option><?php foreach (['OCZEKUJE', 'W_TRAKCIE', 'PRZYJETY', 'NIEPEWNY', 'ANULOWANY'] as $s): ?><option value="<?= h($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= h(STATUS_LABELS[$s] ?? $s) ?></option><?php endforeach; ?></select></label>
  <button class="btn">Filtruj</button>
</form>
<div class="table-wrap"><table>
<thead><tr><th>Utworzono</th><th>Telefon</th><th>Treść</th><th>Status</th><th>Szczegóły</th><th></th></tr></thead>
<tbody>
<?php foreach ($list as $r): ?>
  <tr><td><?= h(fmt_dt($r['created'])) ?></td><td><?= h($r['phone']) ?></td><td><?= h($r['content']) ?></td><td><?= badge($r['status']) ?></td><td><?= h($r['detail']) ?></td>
  <td><?php if (can_send($u) && $r['status'] === 'OCZEKUJE'): ?><form method="post" action="<?= h(url('outbox')) ?>" data-confirm="Anulować ten SMS?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><button class="btn btn-o">Anuluj</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="muted">Brak wiadomości.</td></tr><?php endif; ?>
</tbody></table></div>
<?= pager($total, 'outbox', $f) ?>
<?php layout_end();
