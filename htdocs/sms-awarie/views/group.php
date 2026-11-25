<?php
// Strona jednej grupy zgloszen: statystyki, pojazdy (grupa Pojazdy) i ustawienia (administrator).
require __DIR__.'/_layout.php';
require __DIR__.'/_groups.php';

$gid = (string)($_GET['id'] ?? '');
$g = rows($p, 'SELECT * FROM incident_groups WHERE id=?', [$gid])[0] ?? null;
if (!$g) { flash('Nie znaleziono grupy.', 'err'); redirect('groups'); }
// 4.5.0: strona grupy tylko z dostepem do niej; pojazdy (obiekty grupy) zmienia poziom "Obsluga i obiekty".
$level = group_level($p, $u, $gid);
if ($level < 1) { http_response_code(403); flash('Brak dostępu do tej grupy zgłoszeń.', 'err'); redirect('groups'); }
$canObjects = can_write($u) && $level >= 3;
$isVehicles = (int)$g['legacy_vehicle'] === 1;
$all = !empty($_GET['all']);

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        if (!is_admin($u)) { flash('Grupy zmienia administrator.', 'err'); redirect('group', ['id' => $gid]); }
        $_POST['id'] = $gid;
        group_save($p, $u, 'group', ['id' => $gid]);
    } elseif ($act === 'members') {
        if (!is_admin($u)) { flash('Odbiorców ustawia administrator.', 'err'); redirect('group', ['id' => $gid]); }
        group_members_save($p, $u, $gid);
    } elseif ($act === 'access') {
        if (!is_admin($u)) { flash('Uprawnienia nadaje administrator.', 'err'); redirect('group', ['id' => $gid]); }
        $lv = (array)($_POST['lvl'] ?? []);
        $p->beginTransaction();
        foreach (rows($p, "SELECT id,login FROM web_users WHERE role<>'admin'") as $usr) {
            if (!array_key_exists($usr['id'], $lv)) continue;
            $l = (string)$lv[$usr['id']];
            $p->prepare('DELETE FROM user_group_access WHERE user_id=? AND group_id=?')->execute([$usr['id'], $gid]);
            if (isset(LEVEL_RANK[$l])) $p->prepare('INSERT INTO user_group_access(user_id,group_id,level) VALUES(?,?,?)')->execute([$usr['id'], $gid, $l]);
        }
        audit($p, $u, 'groups:access', $gid);
        $p->commit();
        flash('Zapisano uprawnienia do grupy.');
    } elseif (!$canObjects) {
        flash('Brak uprawnień do zmiany obiektów tej grupy.', 'err');
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
$recipientCount = group_recipient_counts($p)[$gid] ?? 0;
$vehicles = $isVehicles ? rows($p, 'SELECT v.id,v.plate,v.active,COUNT(t.id) AS tickets FROM vehicles v LEFT JOIN tickets t ON t.plate=v.plate'.($all ? '' : ' WHERE v.active=1').' GROUP BY v.id,v.plate,v.active ORDER BY v.plate') : [];

layout_start($g['name'], $u, $page);
?>
<p><span class="chip">Prefiks SMS: <strong><?= h($g['prefix']) ?></strong></span> <?= (int)$g['active'] ? '<span class="badge b-green">Aktywna</span>' : '<span class="badge b-grey">Wyłączona</span>' ?>
  <small class="muted">odbiorców powiadomień: <?= $recipientCount ?></small></p>
<div class="kpis">
<?php foreach (TICKET_STATUSES as $k => $label): ?>
  <a class="card kpi <?= ['NOWE' => 'k-blue', 'WERYFIKACJA' => 'k-amber', 'W_REALIZACJI' => 'k-violet', 'ZAMKNIETE' => 'k-green'][$k] ?? '' ?>" href="<?= h(url('tickets', ['group' => $gid, 'status' => $k])) ?>"><span><?= h($label) ?></span><strong><?= $stats[$k] ?></strong></a>
<?php endforeach; ?>
</div>
<section class="card">
  <div class="card-head"><h2>Ostatnie zgłoszenia</h2><a class="btn btn-o" href="<?= h(url('tickets', ['group' => $gid])) ?>">Wszystkie zgłoszenia grupy</a></div>
  <?php if (!$recent): ?><p class="muted">Brak zgłoszeń w tej grupie.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Numer</th><th>Obiekt</th><th>Opis</th><th>Status</th><th>Utworzono</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?><tr><td><a href="<?= h(url('tickets', ['group' => $gid, 'q' => $r['plate']])) ?>"><?= h(ticketNumberFrom($r['id'], $r['created'], $r['seq'] !== null ? (int)$r['seq'] : null)) ?></a></td><td><?= h($r['plate']) ?></td><td><?= h(mb_strimwidth($r['description'], 0, 90, '…')) ?></td><td><?= badge($r['status']) ?></td><td><?= h(fmt_dt($r['created'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<?php if ($isVehicles): ?>
<section class="card">
  <div class="card-head"><h2>Pojazdy</h2><a class="btn btn-o" href="<?= h(url('group', $all ? ['id' => $gid] : ['id' => $gid, 'all' => 1])) ?>"><?= $all ? 'Pokaż tylko aktywne' : 'Pokaż także wyłączone' ?></a></div>
  <?php if ($canObjects): ?>
  <form method="post" action="<?= h(url('group', ['id' => $gid])) ?>" class="filters">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Nowa rejestracja<input name="plate" required placeholder="PZ12345"></label>
    <button class="btn">Dodaj pojazd</button>
  </form>
  <?php endif; ?>
  <div class="table-wrap"><table>
  <thead><tr><th>Rejestracja</th><th>Zgłoszenia</th><th>Stan</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($vehicles as $r): ?>
    <tr><td><strong><?= h($r['plate']) ?></strong></td><td><?= (int)$r['tickets'] ?></td><td><?= $r['active'] ? '<span class="badge b-green">Aktywny</span>' : '<span class="badge b-grey">Wyłączony</span>' ?></td>
    <td><?php if ($canObjects): $a = $r['active'] ? 'deactivate' : 'activate'; ?><form method="post" action="<?= h(url('group', ['id' => $gid, 'all' => $all ? 1 : ''])) ?>"<?= $r['active'] ? ' data-confirm="Wyłączyć pojazd '.h($r['plate']).'?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><button class="btn btn-o"><?= $r['active'] ? 'Wyłącz' : 'Włącz' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$vehicles): ?><tr><td colspan="4" class="muted">Brak pojazdów.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if (is_admin($u)): ?>
<details class="card item"<?= $isVehicles ? '' : ' open' ?>>
  <summary><strong>Ustawienia grupy</strong> <small class="muted">nazwa, prefiks i szablony SMS</small></summary>
  <?php group_form($g, url('group', ['id' => $gid])); ?>
</details>
<details class="card item" id="odbiorcy"<?= $recipientCount ? '' : ' open' ?>>
  <summary><strong>Odbiorcy powiadomień</strong> <small class="muted">użytkownicy dostający SMS i e-mail o nowych zgłoszeniach: <?= $recipientCount ?></small></summary>
  <?php group_members_form($p, $g); ?>
</details>
<?php $people = rows($p, "SELECT w.id,w.login,w.role,w.groups_all,a.level FROM web_users w LEFT JOIN user_group_access a ON a.user_id=w.id AND a.group_id=? WHERE w.role<>'admin' AND w.active=1 ORDER BY w.login", [$gid]); ?>
<details class="card item">
  <summary><strong>Uprawnienia użytkowników</strong> <small class="muted">kto widzi i obsługuje tę grupę</small></summary>
  <?php if (!$people): ?><p class="muted">Brak aktywnych kont Operator i Tylko odczyt.</p><?php else: ?>
  <form method="post" action="<?= h(url('group', ['id' => $gid])) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="access">
    <div class="table-wrap access-table"><table><thead><tr><th>Konto</th><th>Rola</th><th>Dostęp do grupy</th></tr></thead><tbody>
    <?php foreach ($people as $r): $allLvl = level_cap($r, (string)$r['groups_all']); ?>
      <tr><td><strong><?= h($r['login']) ?></strong></td><td><?= h(ROLES[$r['role']] ?? $r['role']) ?></td>
      <td><?php if ($allLvl): ?><span class="muted">wszystkie grupy: <?= h(GROUP_LEVELS[array_search($allLvl, LEVEL_RANK, true)]) ?> (zmień w Użytkownicy)</span>
      <?php else: echo level_select('lvl['.$r['id'].']', (string)$r['level'], $r['role']); endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <button class="btn">Zapisz uprawnienia</button>
  </form>
  <?php endif; ?>
</details>
<?php endif; ?>
<?php layout_end();
