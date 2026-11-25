<?php
require __DIR__.'/_layout.php';

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    $role = (string)($_POST['role'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $admins = fn() => (int)one($p, "SELECT COUNT(*) FROM web_users WHERE role='admin' AND active=1");
    // 4.5.0: zakres grup i uprawnienia dodatkowe z formularza.
    $scope = (string)($_POST['scope'] ?? 'all');
    $allLevel = (string)($_POST['all_level'] ?? 'manage');
    $groupsAll = $scope === 'all' ? (isset(LEVEL_RANK[$allLevel]) ? $allLevel : 'view') : '';
    $canSend = !empty($_POST['can_send']) ? 1 : 0;
    $seeUn = !empty($_POST['see_unassigned']) ? 1 : 0;
    $saveAccess = function (string $uid) use ($p) {
        $p->prepare('DELETE FROM user_group_access WHERE user_id=?')->execute([$uid]);
        $ins = $p->prepare('INSERT INTO user_group_access(user_id,group_id,level) VALUES(?,?,?)');
        $known = array_column(rows($p, 'SELECT id FROM incident_groups'), 'id');
        foreach ((array)($_POST['lvl'] ?? []) as $gid => $l) if (isset(LEVEL_RANK[$l]) && in_array((string)$gid, $known, true)) $ins->execute([$uid, (string)$gid, $l]);
    };
    if ($act === 'add') {
        $login = trim((string)($_POST['login'] ?? ''));
        if (!isset(ROLES[$role]) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login) || strlen($pass) < 12) flash('Login 3–64 znaki, hasło min. 12 znaków, wybierz rolę.', 'err');
        elseif (one($p, 'SELECT id FROM web_users WHERE login=?', [$login])) flash("Login $login już istnieje.", 'err');
        else {
            $nid = uid();
            $p->beginTransaction();
            $p->prepare('INSERT INTO web_users(id,login,password_hash,role,active,groups_all,can_send,see_unassigned) VALUES(?,?,?,?,1,?,?,?)')->execute([$nid, $login, password_hash($pass, PASSWORD_DEFAULT), $role, $groupsAll, $canSend, $seeUn]);
            $saveAccess($nid);
            audit($p, $u, 'users:add', $login.' '.$role.' grupy:'.($groupsAll ?: 'wybrane'));
            $p->commit();
            flash("Dodano konto $login.");
        }
    } elseif ($act === 'update') {
        $row = rows($p, 'SELECT * FROM web_users WHERE id=?', [$id])[0] ?? null;
        $active = !empty($_POST['active']) ? 1 : 0;
        if (!$row || !isset(ROLES[$role])) flash('Niepoprawne dane.', 'err');
        elseif ($pass !== '' && strlen($pass) < 12) flash('Hasło min. 12 znaków.', 'err');
        elseif ($row['role'] === 'admin' && $row['active'] && ($role !== 'admin' || !$active) && $admins() <= 1) flash('Musi pozostać co najmniej jeden aktywny administrator.', 'err');
        elseif ($id === $u['id'] && !$active) flash('Nie możesz wyłączyć własnego konta.', 'err');
        else {
            $p->beginTransaction();
            $p->prepare('UPDATE web_users SET role=?,active=?,groups_all=?,can_send=?,see_unassigned=? WHERE id=?')->execute([$role, $active, $groupsAll, $canSend, $seeUn, $id]);
            if ($pass !== '') $p->prepare('UPDATE web_users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $saveAccess($id);
            audit($p, $u, 'users:update', $row['login'].' '.$role.' grupy:'.($groupsAll ?: 'wybrane').($active ? '' : ' wylaczony').($pass !== '' ? ' +haslo' : ''));
            $p->commit();
            flash('Zapisano konto '.$row['login'].'.');
        }
    }
    redirect('users');
}

$list = rows($p, 'SELECT id,login,role,active,groups_all,can_send,see_unassigned FROM web_users ORDER BY active DESC, login');
$groups = rows($p, 'SELECT id,name,prefix,active FROM incident_groups ORDER BY legacy_vehicle DESC, name');
$access = [];
foreach (rows($p, 'SELECT user_id,group_id,level FROM user_group_access') as $a) $access[$a['user_id']][$a['group_id']] = $a['level'];
// Uprawnienia do grup zgloszen (4.5.0). Administrator ma zawsze pelny dostep - pola sa wtedy pomijane.
$accessFields = function (array $r, array $mine) use ($groups): string {
    $all = $r['groups_all'] !== '';
    $o = '<fieldset class="access"><legend>Dostęp do grup zgłoszeń <small class="muted">(nie dotyczy Administratora)</small></legend>';
    $o .= '<label class="check"><input type="radio" name="scope" value="all"'.($all ? ' checked' : '').'> Wszystkie grupy, także nowe – poziom ';
    $o .= '<select name="all_level">';
    foreach (GROUP_LEVELS as $k => $v) $o .= '<option value="'.h($k).'"'.(($all ? $r['groups_all'] : 'view') === $k ? ' selected' : '').'>'.h($v).'</option>';
    $o .= '</select></label>';
    $o .= '<label class="check"><input type="radio" name="scope" value="selected"'.($all ? '' : ' checked').'> Tylko wybrane grupy:</label>';
    $o .= '<div class="table-wrap"><table><thead><tr><th>Grupa</th><th>Dostęp</th></tr></thead><tbody>';
    foreach ($groups as $g) $o .= '<tr><td>'.h($g['name']).' <span class="chip">'.h($g['prefix']).'</span>'.((int)$g['active'] ? '' : ' <span class="badge b-grey">Wyłączona</span>').'</td><td>'.level_select('lvl['.$g['id'].']', (string)($mine[$g['id']] ?? ''), 'admin').'</td></tr>';
    $o .= '</tbody></table></div>';
    $o .= '<label class="check"><input type="checkbox" name="can_send" value="1"'.((int)$r['can_send'] ? ' checked' : '').'> Wysyłka i anulowanie SMS (SMS wychodzące)</label>';
    $o .= '<label class="check"><input type="checkbox" name="see_unassigned" value="1"'.((int)$r['see_unassigned'] ? ' checked' : '').'> Widzi SMS-y nieprzypisane do grup (niepoprawne, wysyłki ręczne)</label>';
    return $o.'</fieldset>';
};
$roleSelect = function (string $current): string {
    $o = '<select name="role" required>';
    foreach (ROLES as $k => $v) $o .= '<option value="'.h($k).'"'.($k === $current ? ' selected' : '').'>'.h($v).'</option>';
    return $o.'</select>';
};

layout_start($title, $u, $page);
?>
<details class="card">
  <summary><strong>+ Nowe konto</strong></summary>
  <form method="post" action="<?= h(url('users')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="grid2">
      <label>Login<input name="login" required pattern="[a-zA-Z0-9_.\-]{3,64}"></label>
      <label>Rola<?= $roleSelect('operator') ?></label>
    </div>
    <label>Hasło (min. 12 znaków)<input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
    <?= $accessFields(['groups_all' => 'manage', 'can_send' => 1, 'see_unassigned' => 1], []) ?>
    <button class="btn">Dodaj konto</button>
  </form>
</details>
<?php foreach ($list as $r): ?>
<details class="card item">
  <summary><strong><?= h($r['login']) ?></strong> <span class="chip"><?= h(ROLES[$r['role']] ?? $r['role']) ?></span> <small class="muted"><?= h(access_summary($r, count($access[$r['id']] ?? []))) ?></small> <?= $r['active'] ? '' : '<span class="badge b-grey">Wyłączone</span>' ?><?= $r['id'] === $u['id'] ? ' <small class="muted">(Ty)</small>' : '' ?></summary>
  <form method="post" action="<?= h(url('users')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
    <div class="grid2">
      <label>Rola<?= $roleSelect($r['role']) ?></label>
      <label>Nowe hasło (puste = bez zmian)<input name="password" type="password" minlength="12" autocomplete="new-password"></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1"<?= $r['active'] ? ' checked' : '' ?>> Konto aktywne</label>
    <?= $accessFields($r, $access[$r['id']] ?? []) ?>
    <button class="btn">Zapisz</button>
  </form>
</details>
<?php endforeach; ?>
<p class="muted">Role: Administrator – wszystko (wszystkie grupy, ustawienia, konta); Operator – praca w grupach zgodnie z poziomem dostępu; Tylko odczyt – tylko podgląd, nawet gdy w grupie wybrano wyższy poziom.</p>
<p class="muted">Poziomy w grupie: <strong>Podgląd</strong> – zgłoszenia, statystyki i SMS-y grupy; <strong>Obsługa zgłoszeń</strong> – zmiana statusu i opisu; <strong>Obsługa i obiekty</strong> – dodatkowo dodawanie i wyłączanie obiektów grupy (np. pojazdów). Uprawnienia do jednej grupy można też ustawić na jej stronie (Ustawienia grupy › Uprawnienia użytkowników).</p>
<?php layout_end();
