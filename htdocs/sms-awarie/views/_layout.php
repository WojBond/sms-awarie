<?php
// Wspolny szablon strony. Uzycie: layout_start($title, $u, $page); ... layout_end();

function layout_start(string $title, ?array $u, string $page): void {
    $nav = [
        'Obsługa' => ['dashboard' => 'Dashboard', 'tickets' => 'Zgłoszenia', 'messages' => 'SMS odebrane', 'outbox' => 'SMS wychodzące', 'vehicles' => 'Pojazdy'],
        'Administracja' => ['groups' => 'Grupy zgłoszeń', 'users' => 'Użytkownicy', 'settings' => 'Ustawienia i modem', 'database' => 'Baza danych', 'logs' => 'Dziennik zdarzeń'],
    ];
    ?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · SMS-Awarie</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<script src="<?= asset('assets/js/app.js') ?>" defer></script>
</head>
<body>
<?php if ($u): ?>
<header class="top">
  <a class="brand" href="<?= h(url('dashboard')) ?>">SMS-Awarie</a>
  <details class="menu">
    <summary class="btn btn-o">Menu</summary>
    <nav>
    <?php foreach ($nav as $group => $items): if ($group === 'Administracja' && !is_admin($u)) continue; ?>
      <p class="nav-h"><?= h($group) ?></p>
      <?php foreach ($items as $key => $label): ?>
        <a href="<?= h(url($key)) ?>"<?= $key === $page ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>
  </details>
  <span class="who"><?= h($u['login']) ?> · <?= h(ROLES[$u['role']] ?? $u['role']) ?></span>
  <form method="post" action="<?= h(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-o">Wyloguj</button></form>
</header>
<?php endif; ?>
<main>
<h1><?= h($title) ?></h1>
<?php foreach (flash() as [$type, $msg]): ?>
  <p class="flash flash-<?= h($type) ?>" role="<?= $type === 'err' ? 'alert' : 'status' ?>"><?= h($msg) ?></p>
<?php endforeach;
}

function layout_end(): void {
    ?>
</main>
<details class="version">
  <summary>v<?= h(APP_VERSION) ?></summary>
  <div class="changelog">
  <?php foreach (APP_CHANGELOG as $v => $items): ?>
    <strong><?= h($v) ?></strong>
    <ul><?php foreach ($items as $i): ?><li><?= h($i) ?></li><?php endforeach; ?></ul>
  <?php endforeach; ?>
  </div>
</details>
</body>
</html>
<?php
}
