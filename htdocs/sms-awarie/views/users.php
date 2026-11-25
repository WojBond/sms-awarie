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
    // 4.6.0: e-mail i telefon do powiadomien; konto bez logowania = tylko powiadomienia.
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $phone = phone_clean((string)($_POST['phone'] ?? ''));
    $loginOn = !empty($_POST['login_enabled']) ? 1 : 0;
    $contactErr = ($email !== '' && !valid_email($email)) ? 'Niepoprawny adres e-mail.' : (($phone !== '' && !preg_match('/^\+?[0-9]{9,15}$/D', $phone)) ? 'Telefon: 9–15 cyfr, opcjonalnie z + na początku (np. +48600100200).' : null);
    $saveAccess = function (string $uid) use ($p) {
        $p->prepare('DELETE FROM user_group_access WHERE user_id=?')->execute([$uid]);
        $ins = $p->prepare('INSERT INTO user_group_access(user_id,group_id,level) VALUES(?,?,?)');
        $known = array_column(rows($p, 'SELECT id FROM incident_groups'), 'id');
        foreach ((array)($_POST['lvl'] ?? []) as $gid => $l) if (isset(LEVEL_RANK[$l]) && in_array((string)$gid, $known, true)) $ins->execute([$uid, (string)$gid, $l]);
    };
    if ($act === 'add') {
        $login = trim((string)($_POST['login'] ?? ''));
        $joinGroup = (string)($_POST['join_group'] ?? '');
        if (!isset(ROLES[$role]) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login) || ($loginOn && strlen($pass) < 12)) flash('Login 3–64 znaki, hasło min. 12 znaków (gdy konto może się logować), wybierz rolę.', 'err');
        elseif ($contactErr) flash($contactErr, 'err');
        elseif (!$loginOn && $email === '' && $phone === '') flash('Konto tylko do powiadomień wymaga telefonu lub adresu e-mail.', 'err');
        elseif (one($p, 'SELECT id FROM web_users WHERE login=?', [$login])) flash("Login $login już istnieje.", 'err');
        else {
            $nid = uid();
            // Bez logowania: losowe haslo, ktorego nikt nie zna.
            $hash = password_hash($loginOn ? $pass : bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $p->beginTransaction();
            $p->prepare('INSERT INTO web_users(id,login,password_hash,role,active,groups_all,can_send,see_unassigned,email,phone,login_enabled) VALUES(?,?,?,?,1,?,?,?,?,?,?)')->execute([$nid, $login, $hash, $role, $groupsAll, $canSend, $seeUn, $email, $phone, $loginOn]);
            $saveAccess($nid);
            // Z przycisku "Zamien na konto" na stronie grupy: konto od razu odbiera powiadomienia tej grupy.
            if ($joinGroup !== '' && one($p, 'SELECT id FROM incident_groups WHERE id=?', [$joinGroup])) {
                $p->prepare('INSERT INTO group_members(group_id,user_id,notify_sms,notify_email) VALUES(?,?,1,1)')->execute([$joinGroup, $nid]);
                group_dedupe_recipients($p, $joinGroup);
            }
            audit($p, $u, 'users:add', $login.' '.$role.' grupy:'.($groupsAll ?: 'wybrane').($loginOn ? '' : ' bez logowania').($joinGroup !== '' ? ' +grupa '.$joinGroup : ''));
            $p->commit();
            flash("Dodano konto $login.");
            if ($joinGroup !== '') redirect('group', ['id' => $joinGroup]);
        }
    } elseif ($act === 'update') {
        $row = rows($p, 'SELECT * FROM web_users WHERE id=?', [$id])[0] ?? null;
        $active = !empty($_POST['active']) ? 1 : 0;
        if (!$row || !isset(ROLES[$role])) flash('Niepoprawne dane.', 'err');
        elseif ($pass !== '' && strlen($pass) < 12) flash('Hasło min. 12 znaków.', 'err');
        elseif ($contactErr) flash($contactErr, 'err');
        elseif ($loginOn && !(int)$row['login_enabled'] && $pass === '') flash('Włączasz logowanie – ustaw hasło (min. 12 znaków).', 'err');
        elseif ($row['role'] === 'admin' && $row['active'] && $row['login_enabled'] && !$loginOn && (int)one($p, "SELECT COUNT(*) FROM web_users WHERE role='admin' AND active=1 AND login_enabled=1") <= 1) flash('Musi pozostać co najmniej jeden administrator, który może się logować.', 'err');
        elseif ($id === $u['id'] && !$loginOn) flash('Nie możesz wyłączyć logowania na własnym koncie.', 'err');
        elseif ($row['role'] === 'admin' && $row['active'] && ($role !== 'admin' || !$active) && $admins() <= 1) flash('Musi pozostać co najmniej jeden aktywny administrator.', 'err');
        elseif ($id === $u['id'] && !$active) flash('Nie możesz wyłączyć własnego konta.', 'err');
        else {
            $p->beginTransaction();
            $p->prepare('UPDATE web_users SET role=?,active=?,groups_all=?,can_send=?,see_unassigned=?,email=?,phone=?,login_enabled=? WHERE id=?')->execute([$role, $active, $groupsAll, $canSend, $seeUn, $email, $phone, $loginOn, $id]);
            if ($pass !== '') $p->prepare('UPDATE web_users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $saveAccess($id);
            group_dedupe_recipients($p);
            audit($p, $u, 'users:update', $row['login'].' '.$role.' grupy:'.($groupsAll ?: 'wybrane').($active ? '' : ' wylaczony').($loginOn ? '' : ' bez logowania').($pass !== '' ? ' +haslo' : ''));
            $p->commit();
            flash('Zapisano konto '.$row['login'].'.');
        }
    }
    redirect('users');
}

$list = rows($p, 'SELECT id,login,role,active,groups_all,can_send,see_unassigned,email,phone,login_enabled FROM web_users ORDER BY active DESC, login');
$member = [];
foreach (rows($p, 'SELECT m.user_id,g.name,m.notify_sms,m.notify_email FROM group_members m JOIN incident_groups g ON g.id=m.group_id ORDER BY g.name') as $m) $member[$m['user_id']][] = $m;
// Dane kontaktowe i logowanie (4.6.0).
$contactFields = function (array $r): string {
    $o = '<div class="grid2 tight">';
    $o .= '<label>E-mail (powiadomienia)<input name="email" type="email" maxlength="254" value="'.h($r['email'] ?? '').'" placeholder="jan.kowalski@firma.pl" autocomplete="off"></label>';
    $o .= '<label>Telefon (powiadomienia SMS)<input name="phone" inputmode="tel" maxlength="20" pattern="[+0-9 ()\-]{9,20}" value="'.h($r['phone'] ?? '').'" placeholder="+48600100200" autocomplete="off"></label>';
    $o .= '</div>';
    $o .= '<label class="check"><input type="checkbox" name="login_enabled" value="1"'.((int)($r['login_enabled'] ?? 1) ? ' checked' : '').'> Może logować się do panelu <small class="muted">(odznacz dla osób, które tylko dostają powiadomienia)</small></label>';
    return $o;
};
$memberInfo = function (array $r) use ($member): string {
    $m = $member[$r['id']] ?? [];
    if (!$m) return '<p class="muted hint">Powiadomienia: nie należy do żadnej grupy zgłoszeń. Odbiorców dodasz na stronie grupy › Odbiorcy powiadomień.</p>';
    $list = array_map(fn($x) => h($x['name']).' ('.implode(', ', array_filter([(int)$x['notify_sms'] ? 'SMS' : '', (int)$x['notify_email'] ? 'e-mail' : ''])).')', $m);
    return '<p class="muted hint">Powiadomienia z grup: '.implode(', ', $list).'</p>';
};
// Prefill z przycisku "Zamien na konto" (strona grupy, dodatkowe numery).
$newPhone = phone_clean((string)($_GET['new_phone'] ?? ''));
$newGroup = (string)($_GET['new_group'] ?? '');
$newGroupName = $newGroup !== '' ? (string)one($p, 'SELECT name FROM incident_groups WHERE id=?', [$newGroup]) : '';
$groups = rows($p, 'SELECT id,name,prefix,active FROM incident_groups ORDER BY legacy_vehicle DESC, name');
$access = [];
foreach (rows($p, 'SELECT user_id,group_id,level FROM user_group_access') as $a) $access[$a['user_id']][$a['group_id']] = $a['level'];
// Uprawnienia do grup zgloszen (4.5.0). Administrator ma zawsze pelny dostep - pola sa wtedy pomijane.
$accessFields = function (array $r, array $mine) use ($groups): string {
    $all = $r['groups_all'] !== '';
    $o = '<fieldset class="access"><legend>Dostęp do grup zgłoszeń</legend>';
    $o .= '<p class="muted hint">Dotyczy ról Operator i Tylko odczyt. Administrator ma zawsze pełny dostęp, a Tylko odczyt najwyżej Podgląd.</p>';
    $o .= '<div class="scope" role="radiogroup" aria-label="Zakres grup">';
    $o .= '<label class="opt"><input type="radio" name="scope" value="all"'.($all ? ' checked' : '').'><span><strong>Wszystkie grupy</strong><small>także grupy dodane w przyszłości</small></span></label>';
    $o .= '<label class="opt"><input type="radio" name="scope" value="selected"'.($all ? '' : ' checked').'><span><strong>Tylko wybrane grupy</strong><small>poziom osobno dla każdej grupy</small></span></label>';
    $o .= '</div>';
    $o .= '<div class="scope-all"><label>Poziom we wszystkich grupach<select name="all_level">';
    foreach (GROUP_LEVELS as $k => $v) $o .= '<option value="'.h($k).'"'.(($all ? $r['groups_all'] : 'view') === $k ? ' selected' : '').'>'.h($v).'</option>';
    $o .= '</select></label></div>';
    $o .= '<div class="scope-sel"><div class="table-wrap"><table><thead><tr><th>Grupa</th><th>Dostęp</th></tr></thead><tbody>';
    foreach ($groups as $g) $o .= '<tr><td>'.h($g['name']).' <span class="chip">'.h($g['prefix']).'</span>'.((int)$g['active'] ? '' : ' <span class="badge b-grey">Wyłączona</span>').'</td><td>'.level_select('lvl['.$g['id'].']', (string)($mine[$g['id']] ?? ''), 'admin').'</td></tr>';
    if (!$groups) $o .= '<tr><td colspan="2" class="muted">Brak grup zgłoszeń.</td></tr>';
    $o .= '</tbody></table></div></div>';
    $o .= '<div class="extra"><strong>Uprawnienia dodatkowe</strong>';
    $o .= '<label class="check"><input type="checkbox" name="can_send" value="1"'.((int)$r['can_send'] ? ' checked' : '').'> Wysyłka i anulowanie SMS (SMS wychodzące)</label>';
    $o .= '<label class="check"><input type="checkbox" name="see_unassigned" value="1"'.((int)$r['see_unassigned'] ? ' checked' : '').'> Widzi SMS-y nieprzypisane do grup (niepoprawne, wysyłki ręczne)</label>';
    return $o.'</div></fieldset>';
};
$roleSelect = function (string $current): string {
    $o = '<select name="role" required>';
    foreach (ROLES as $k => $v) $o .= '<option value="'.h($k).'"'.($k === $current ? ' selected' : '').'>'.h($v).'</option>';
    return $o.'</select>';
};

layout_start($title, $u, $page);
?>
<details class="card"<?= $newPhone !== '' ? ' open' : '' ?>>
  <summary><strong>+ Nowe konto</strong></summary>
  <form method="post" action="<?= h(url('users')) ?>" class="user-new">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <?php if ($newGroupName !== ''): ?><input type="hidden" name="join_group" value="<?= h($newGroup) ?>"><p class="flash flash-ok">Konto dostanie powiadomienia grupy <strong><?= h($newGroupName) ?></strong> (SMS i e-mail), a numer <?= h($newPhone) ?> zniknie z dodatkowych numerów grupy.</p><?php endif; ?>
    <div class="grid2">
      <label>Login<input name="login" required pattern="[a-zA-Z0-9_.\-]{3,64}"></label>
      <label>Rola<?= $roleSelect($newPhone !== '' ? 'read-only' : 'operator') ?></label>
    </div>
    <?= $contactFields(['phone' => $newPhone, 'login_enabled' => $newPhone !== '' ? 0 : 1]) ?>
    <label class="pass-new">Hasło (min. 12 znaków; niepotrzebne dla konta bez logowania)<input name="password" type="password" minlength="12" autocomplete="new-password"></label>
    <?= $accessFields(['groups_all' => 'manage', 'can_send' => 1, 'see_unassigned' => 1], []) ?>
    <button class="btn">Dodaj konto</button>
  </form>
</details>
<?php foreach ($list as $r): ?>
<details class="card item">
  <summary><strong><?= h($r['login']) ?></strong> <span class="chip"><?= h(ROLES[$r['role']] ?? $r['role']) ?></span> <small class="muted"><?= h(access_summary($r, count($access[$r['id']] ?? []))) ?></small> <?= (int)$r['login_enabled'] ? '' : '<span class="badge b-blue">Tylko powiadomienia</span>' ?> <?= $r['active'] ? '' : '<span class="badge b-grey">Wyłączone</span>' ?><?= $r['id'] === $u['id'] ? ' <small class="muted">(Ty)</small>' : '' ?></summary>
  <form method="post" action="<?= h(url('users')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
    <div class="grid2">
      <label>Rola<?= $roleSelect($r['role']) ?></label>
      <label>Nowe hasło (puste = bez zmian)<input name="password" type="password" minlength="12" autocomplete="new-password"></label>
    </div>
    <?= $contactFields($r) ?>
    <label class="check"><input type="checkbox" name="active" value="1"<?= $r['active'] ? ' checked' : '' ?>> Konto aktywne <small class="muted">(wyłączone konto nie loguje się i nie dostaje powiadomień)</small></label>
    <?= $memberInfo($r) ?>
    <?= $accessFields($r, $access[$r['id']] ?? []) ?>
    <button class="btn">Zapisz</button>
  </form>
</details>
<?php endforeach; ?>
<p class="muted">Role: Administrator – wszystko (wszystkie grupy, ustawienia, konta); Operator – praca w grupach zgodnie z poziomem dostępu; Tylko odczyt – tylko podgląd, nawet gdy w grupie wybrano wyższy poziom.</p>
<p class="muted">Poziomy w grupie: <strong>Podgląd</strong> – zgłoszenia, statystyki i SMS-y grupy; <strong>Obsługa zgłoszeń</strong> – zmiana statusu i opisu; <strong>Obsługa i obiekty</strong> – dodatkowo dodawanie i wyłączanie obiektów grupy (np. pojazdów). Uprawnienia do jednej grupy można też ustawić na jej stronie (Ustawienia grupy › Uprawnienia użytkowników).</p>
<?php layout_end();
