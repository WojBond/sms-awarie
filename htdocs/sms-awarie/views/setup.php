<?php
// Widoczny tylko, gdy w bazie nie ma zadnego konta.
require __DIR__.'/_layout.php';
if ($post) {
    $login = trim((string)($_POST['login'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login) || strlen($pass) < 12) {
        flash('Login 3–64 znaki (litery, cyfry, . _ -), hasło min. 12 znaków.', 'err');
    } elseif ($pass !== (string)($_POST['password2'] ?? '')) {
        flash('Hasła nie są takie same.', 'err');
    } elseif ((int)one($p, 'SELECT COUNT(*) FROM web_users') === 0) {
        $id = uid();
        $p->prepare('INSERT INTO web_users(id,login,password_hash,role,active) VALUES(?,?,?,?,1)')->execute([$id, $login, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
        session_regenerate_id(true);
        $_SESSION['user'] = $id;
        audit($p, ['login' => $login], 'users:setup', $login);
        flash('Utworzono konto administratora.');
    }
    redirect('dashboard');
}
layout_start('Pierwsze uruchomienie', null, 'setup');
?>
<div class="auth">
<form method="post" action="<?= h(url('setup')) ?>" class="card">
  <?= brand() ?>
  <h1>Pierwsze uruchomienie</h1>
  <p class="muted">W bazie nie ma jeszcze żadnego konta. Utwórz konto administratora.</p>
  <?php flash_html(); ?>
  <?= csrf_field() ?>
  <label>Login<input name="login" required autofocus pattern="[a-zA-Z0-9_.\-]{3,64}"></label>
  <label>Hasło (min. 12 znaków)<input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
  <label>Powtórz hasło<input name="password2" type="password" required minlength="12" autocomplete="new-password"></label>
  <button class="btn">Utwórz administratora</button>
</form>
</div>
<?php layout_end();
