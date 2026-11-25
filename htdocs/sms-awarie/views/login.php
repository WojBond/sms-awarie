<?php
require __DIR__.'/_layout.php';
if ($post) {
    $login = trim((string)($_POST['login'] ?? ''));
    $q = $p->prepare('SELECT * FROM web_users WHERE login=? AND active=1 AND login_enabled=1');
    $q->execute([$login]);
    $row = $q->fetch();
    if ($row && password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = $row['id'];
        redirect('dashboard');
    }
    sleep(1);
    flash('Niepoprawny login lub hasło.', 'err');
    redirect('login');
}
layout_start('Logowanie', null, 'login');
?>
<div class="auth">
<form method="post" action="<?= h(url('login')) ?>" class="card">
  <?= brand() ?>
  <h1>Zaloguj się do panelu</h1>
  <?php flash_html(); ?>
  <?= csrf_field() ?>
  <label>Login<input name="login" required autofocus autocomplete="username"></label>
  <label>Hasło<input name="password" type="password" required autocomplete="current-password"></label>
  <button class="btn">Zaloguj</button>
</form>
</div>
<?php layout_end();
