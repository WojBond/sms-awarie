<?php
require __DIR__.'/_layout.php';
require __DIR__.'/_groups.php';

if ($post) {
    if (!is_admin($u)) { flash('Grupy zmienia administrator.', 'err'); redirect('groups'); }
    if (($_POST['action'] ?? '') === 'save') { $_POST['id'] = ''; $gid = group_save($p, $u, 'groups', []); redirect('group', ['id' => $gid]); }
    redirect('groups');
}

[$gf, $ga] = group_filter($p, $u, 'g.id');
$list = rows($p, "SELECT g.id,g.name,g.prefix,g.active,g.legacy_vehicle,COUNT(t.id) AS total,SUM(CASE WHEN t.status IN ('NOWE','WERYFIKACJA','W_REALIZACJI') THEN 1 ELSE 0 END) AS open_count
    FROM incident_groups g LEFT JOIN ticket_groups tg ON tg.group_id=g.id LEFT JOIN tickets t ON t.id=tg.ticket_id".($gf !== '' ? " WHERE $gf" : '')."
    GROUP BY g.id,g.name,g.prefix,g.active,g.legacy_vehicle ORDER BY g.legacy_vehicle DESC, g.active DESC, g.name", $ga);
$levels = group_levels($p, $u);
$recipients = [];
foreach (rows($p, 'SELECT group_id,COUNT(*) AS n FROM group_recipients WHERE active=1 GROUP BY group_id') as $r) $recipients[$r['group_id']] = (int)$r['n'];

layout_start($title, $u, $page);
?>
<p class="muted">SMS zaczynający się od prefiksu grupy (np. „MAS CNC12 brak zasilania”) trafia do tej grupy. SMS bez prefiksu, zaczynający się od rejestracji, trafia do grupy Pojazdy.</p>
<div class="table-wrap card"><table>
<thead><tr><th>Grupa</th><th>Prefiks</th><th>Stan</th><?= is_admin($u) ? '' : '<th>Twój dostęp</th>' ?><th class="num">Otwarte</th><th class="num">Wszystkie</th><th class="num">Odbiorcy</th></tr></thead>
<tbody>
<?php foreach ($list as $g): ?>
  <tr><td><a href="<?= h(url('group', ['id' => $g['id']])) ?>"><strong><?= h($g['name']) ?></strong></a></td><td><span class="chip"><?= h($g['prefix']) ?></span></td>
  <td><?= (int)$g['active'] ? '<span class="badge b-green">Aktywna</span>' : '<span class="badge b-grey">Wyłączona</span>' ?></td>
  <?php if (!is_admin($u)): ?><td><?= h(GROUP_LEVELS[array_search($levels[$g['id']] ?? 0, LEVEL_RANK, true) ?: 'view']) ?></td><?php endif; ?>
  <td class="num"><?= (int)$g['open_count'] ?></td><td class="num"><?= (int)$g['total'] ?></td><td class="num"><?= $recipients[$g['id']] ?? 0 ?></td></tr>
<?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="muted">Nie masz dostępu do żadnej grupy zgłoszeń.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (is_admin($u)): ?>
<details class="card item">
  <summary><strong>+ Nowa grupa</strong></summary>
  <?php group_form(['id' => '', 'name' => '', 'prefix' => '', 'active' => 1, 'notify_sender' => 1, 'sender_template' => 'Przyjeto zgloszenie {number} dla {object}.', 'recipient_template' => 'Nowe zgloszenie {number}: {group} {object} {description}'], [], url('groups')); ?>
</details>
<?php endif; ?>
<?php layout_end();
