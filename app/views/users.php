<?php
require __DIR__.'/_layout.php';

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    $role = (string)($_POST['role'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $admins = fn() => (int)one($p, "SELECT COUNT(*) FROM web_users WHERE role='admin' AND active=1");
    if ($act === 'add') {
        $login = trim((string)($_POST['login'] ?? ''));
        if (!isset(ROLES[$role]) || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login) || strlen($pass) < 12) flash('Login 3–64 znaki, hasło min. 12 znaków, wybierz rolę.', 'err');
        elseif (one($p, 'SELECT id FROM web_users WHERE login=?', [$login])) flash("Login $login już istnieje.", 'err');
        else {
            $p->prepare('INSERT INTO web_users(id,login,password_hash,role,active) VALUES(?,?,?,?,1)')->execute([uid(), $login, password_hash($pass, PASSWORD_DEFAULT), $role]);
            audit($p, $u, 'users:add', $login.' '.$role);
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
            $p->prepare('UPDATE web_users SET role=?,active=? WHERE id=?')->execute([$role, $active, $id]);
            if ($pass !== '') $p->prepare('UPDATE web_users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            audit($p, $u, 'users:update', $row['login'].' '.$role.($active ? '' : ' wylaczony').($pass !== '' ? ' +haslo' : ''));
            flash('Zapisano konto '.$row['login'].'.');
        }
    }
    redirect('users');
}

$list = rows($p, 'SELECT id,login,role,active FROM web_users ORDER BY active DESC, login');
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
    <button class="btn">Dodaj konto</button>
  </form>
</details>
<?php foreach ($list as $r): ?>
<details class="card item">
  <summary><strong><?= h($r['login']) ?></strong> <span class="chip"><?= h(ROLES[$r['role']] ?? $r['role']) ?></span> <?= $r['active'] ? '' : '<span class="badge b-grey">Wyłączone</span>' ?><?= $r['id'] === $u['id'] ? ' <small class="muted">(Ty)</small>' : '' ?></summary>
  <form method="post" action="<?= h(url('users')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
    <div class="grid2">
      <label>Rola<?= $roleSelect($r['role']) ?></label>
      <label>Nowe hasło (puste = bez zmian)<input name="password" type="password" minlength="12" autocomplete="new-password"></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1"<?= $r['active'] ? ' checked' : '' ?>> Konto aktywne</label>
    <button class="btn">Zapisz</button>
  </form>
</details>
<?php endforeach; ?>
<p class="muted">Role: Administrator – wszystko; Operator – zgłoszenia, SMS, pojazdy; Tylko odczyt – podgląd.</p>
<?php layout_end();
