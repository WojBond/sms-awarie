<?php
// Strona jednej grupy zgloszen: statystyki, pojazdy (grupa Pojazdy) i ustawienia (administrator).
require __DIR__.'/_layout.php';
require __DIR__.'/_groups.php';

$gid = (string)($_GET['id'] ?? '');
$g = rows($p, 'SELECT * FROM incident_groups WHERE id=?', [$gid])[0] ?? null;
if (!$g) { flash('Nie znaleziono grupy.', 'err'); redirect('groups'); }
$isVehicles = (int)$g['legacy_vehicle'] === 1;
$all = !empty($_GET['all']);

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        if (!is_admin($u)) { flash('Grupy zmienia administrator.', 'err'); redirect('group', ['id' => $gid]); }
        $_POST['id'] = $gid;
        group_save($p, $u, 'group', ['id' => $gid]);
    } elseif ($isVehicles && $act === 'add') {
        $reg = plate((string)($_POST['plate'] ?? ''));
        if (!preg_match('/^[A-Z0-9]{4,12}$/D', $reg)) {
            flash('Rejestracja: 4–12 liter i cyfr.', 'err');
        } elseif ($id = one($p, 'SELECT id FROM vehicles WHERE plate=?', [$reg])) {
            $p->prepare('UPDATE vehicles SET active=1 WHERE id=?')->execute([$id]);
            audit($p, $u, 'vehicles:activate', $reg);
            flash("Pojazd $reg jest aktywny.");
        } else {
            $p->prepare('INSERT INTO vehicles(id,plate,active) VALUES(?,?,1)')->execute([uid(), $reg]);
            audit($p, $u, 'vehicles:add', $reg);
            flash("Dodano pojazd $reg.");
        }
    } elseif ($isVehicles && in_array($act, ['deactivate', 'activate'], true)) {
        $id = (string)($_POST['id'] ?? '');
        $p->prepare('UPDATE vehicles SET active=? WHERE id=?')->execute([$act === 'activate' ? 1 : 0, $id]);
        audit($p, $u, 'vehicles:'.$act, $id);
        flash($act === 'activate' ? 'Pojazd włączony.' : 'Pojazd wyłączony. Zgłoszenia dla niego trafią do weryfikacji.');
    }
    redirect('group', ['id' => $gid, 'all' => $all ? 1 : '']);
}

$stats = [];
foreach (TICKET_STATUSES as $k => $label) $stats[$k] = 0;
foreach (rows($p, 'SELECT t.status,COUNT(*) AS n FROM tickets t JOIN ticket_groups tg ON tg.ticket_id=t.id WHERE tg.group_id=? GROUP BY t.status', [$gid]) as $r) $stats[$r['status']] = (int)$r['n'];
$recent = rows($p, 'SELECT t.id,t.plate,t.description,t.status,t.created,t.seq FROM tickets t JOIN ticket_groups tg ON tg.ticket_id=t.id WHERE tg.group_id=? ORDER BY t.created DESC'.sql_page($p, 10), [$gid]);
$phones = array_column(rows($p, 'SELECT phone FROM group_recipients WHERE group_id=? AND active=1 ORDER BY phone', [$gid]), 'phone');
$vehicles = $isVehicles ? rows($p, 'SELECT v.id,v.plate,v.active,COUNT(t.id) AS tickets FROM vehicles v LEFT JOIN tickets t ON t.plate=v.plate'.($all ? '' : ' WHERE v.active=1').' GROUP BY v.id,v.plate,v.active ORDER BY v.plate') : [];

layout_start($g['name'], $u, $page);
?>
<p><span class="chip">Prefiks SMS: <strong><?= h($g['prefix']) ?></strong></span> <?= (int)$g['active'] ? '<span class="badge b-green">Aktywna</span>' : '<span class="badge b-grey">Wyłączona</span>' ?>
  <small class="muted">odbiorców powiadomień: <?= count($phones) ?></small></p>
<div class="kpis">
<?php foreach (TICKET_STATUSES as $k => $label): ?>
  <a class="card kpi" href="<?= h(url('tickets', ['group' => $gid, 'status' => $k])) ?>"><span><?= h($label) ?></span><strong><?= $stats[$k] ?></strong></a>
<?php endforeach; ?>
</div>
<section class="card">
  <h2>Ostatnie zgłoszenia</h2>
  <?php if (!$recent): ?><p class="muted">Brak zgłoszeń w tej grupie.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Numer</th><th>Obiekt</th><th>Opis</th><th>Status</th><th>Utworzono</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?><tr><td><?= h(ticketNumberFrom($r['id'], $r['created'], $r['seq'] !== null ? (int)$r['seq'] : null)) ?></td><td><?= h($r['plate']) ?></td><td><?= h(mb_strimwidth($r['description'], 0, 90, '…')) ?></td><td><?= badge($r['status']) ?></td><td><?= h(fmt_dt($r['created'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <p><a href="<?= h(url('tickets', ['group' => $gid])) ?>">Wszystkie zgłoszenia grupy ›</a></p>
</section>

<?php if ($isVehicles): ?>
<section class="card">
  <h2>Pojazdy</h2>
  <?php if (can_write($u)): ?>
  <form method="post" action="<?= h(url('group', ['id' => $gid])) ?>" class="filters">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Nowa rejestracja<input name="plate" required placeholder="PZ12345"></label>
    <button class="btn">Dodaj pojazd</button>
  </form>
  <?php endif; ?>
  <p><a href="<?= h(url('group', $all ? ['id' => $gid] : ['id' => $gid, 'all' => 1])) ?>"><?= $all ? 'Pokaż tylko aktywne' : 'Pokaż także wyłączone' ?></a></p>
  <div class="table-wrap"><table>
  <thead><tr><th>Rejestracja</th><th>Zgłoszenia</th><th>Stan</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($vehicles as $r): ?>
    <tr><td><strong><?= h($r['plate']) ?></strong></td><td><?= (int)$r['tickets'] ?></td><td><?= $r['active'] ? '<span class="badge b-green">Aktywny</span>' : '<span class="badge b-grey">Wyłączony</span>' ?></td>
    <td><?php if (can_write($u)): $a = $r['active'] ? 'deactivate' : 'activate'; ?><form method="post" action="<?= h(url('group', ['id' => $gid, 'all' => $all ? 1 : ''])) ?>"<?= $r['active'] ? ' data-confirm="Wyłączyć pojazd '.h($r['plate']).'?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><button class="btn btn-o"><?= $r['active'] ? 'Wyłącz' : 'Włącz' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$vehicles): ?><tr><td colspan="4" class="muted">Brak pojazdów.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if (is_admin($u)): ?>
<details class="card item"<?= $isVehicles ? '' : ' open' ?>>
  <summary><strong>Ustawienia grupy</strong> <small class="muted">szablony SMS i odbiorcy</small></summary>
  <?php group_form($g, $phones, url('group', ['id' => $gid])); ?>
</details>
<?php endif; ?>
<?php layout_end();
