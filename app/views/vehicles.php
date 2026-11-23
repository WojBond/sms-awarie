<?php
require __DIR__.'/_layout.php';

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'add') {
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
    } elseif (in_array($act, ['deactivate', 'activate'], true)) {
        $id = (string)($_POST['id'] ?? '');
        $p->prepare('UPDATE vehicles SET active=? WHERE id=?')->execute([$act === 'activate' ? 1 : 0, $id]);
        audit($p, $u, 'vehicles:'.$act, $id);
        flash($act === 'activate' ? 'Pojazd włączony.' : 'Pojazd wyłączony. Zgłoszenia dla niego trafią do weryfikacji.');
    }
    redirect('vehicles', ['all' => $_GET['all'] ?? '']);
}

$all = !empty($_GET['all']);
$list = rows($p, 'SELECT v.id,v.plate,v.active,COUNT(t.id) AS tickets FROM vehicles v LEFT JOIN tickets t ON t.plate=v.plate'.($all ? '' : ' WHERE v.active=1').' GROUP BY v.id ORDER BY v.plate');

layout_start($title, $u, $page);
?>
<?php if (can_write($u)): ?>
<form method="post" action="<?= h(url('vehicles')) ?>" class="filters card">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label>Nowa rejestracja<input name="plate" required placeholder="PZ12345"></label>
  <button class="btn">Dodaj pojazd</button>
</form>
<?php endif; ?>
<p><a href="<?= h(url('vehicles', $all ? [] : ['all' => 1])) ?>"><?= $all ? 'Pokaż tylko aktywne' : 'Pokaż także wyłączone' ?></a></p>
<div class="table-wrap"><table>
<thead><tr><th>Rejestracja</th><th>Zgłoszenia</th><th>Stan</th><th></th></tr></thead>
<tbody>
<?php foreach ($list as $r): ?>
  <tr><td><strong><?= h($r['plate']) ?></strong></td><td><?= (int)$r['tickets'] ?></td><td><?= $r['active'] ? '<span class="badge b-green">Aktywny</span>' : '<span class="badge b-grey">Wyłączony</span>' ?></td>
  <td><?php if (can_write($u)): $a = $r['active'] ? 'deactivate' : 'activate'; ?><form method="post" action="<?= h(url('vehicles', ['all' => $all ? 1 : ''])) ?>"<?= $r['active'] ? ' data-confirm="Wyłączyć pojazd '.h($r['plate']).'?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><button class="btn btn-o"><?= $r['active'] ? 'Wyłącz' : 'Włącz' ?></button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="4" class="muted">Brak pojazdów.</td></tr><?php endif; ?>
</tbody></table></div>
<?php layout_end();
