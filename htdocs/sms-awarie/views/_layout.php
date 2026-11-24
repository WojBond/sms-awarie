<?php
// Wspolny szablon strony z menu bocznym. Uzycie: layout_start($title, $u, $page); ... layout_end();

// Menu: [strona, etykieta, parametry, rola] albo [null, etykieta, [], rola, podmenu].
function nav_items(?array $u): array {
    $groups = [['groups', 'Wszystkie grupy', [], '']];
    try {
        foreach (rows($GLOBALS['p'], 'SELECT id,name FROM incident_groups WHERE active=1 ORDER BY legacy_vehicle DESC, name') as $g) $groups[] = ['group', $g['name'], ['id' => $g['id']], ''];
    } catch (Throwable $e) { /* menu bez listy grup */ }
    return [
        ['dashboard', 'Dashboard', [], ''],
        ['tickets', 'Zgłoszenia', [], ''],
        ['messages', 'SMS odebrane', [], ''],
        ['outbox', 'SMS wychodzące', [], ''],
        [null, 'Grupy zgłoszeń', [], '', $groups],
        ['users', 'Użytkownicy', [], 'admin'],
        [null, 'Ustawienia', [], 'admin', [['settings', 'Autoodpowiedzi', [], ''], ['modem', 'Modem', [], ''], ['database', 'Baza danych', [], '']]],
        ['logs', 'Dziennik zdarzeń', [], 'admin'],
    ];
}
function nav_current(string $page, array $params): bool {
    if ($page !== ($GLOBALS['navPage'] ?? '')) return false;
    foreach ($params as $k => $v) if ((string)($_GET[$k] ?? '') !== (string)$v) return false;
    return true;
}
function nav_link(array $i): string {
    return '<a href="'.h(url($i[0], $i[2])).'"'.(nav_current($i[0], $i[2]) ? ' aria-current="page"' : '').'>'.h($i[1]).'</a>';
}

function layout_start(string $title, ?array $u, string $page): void {
    $GLOBALS['navPage'] = $page;
    ?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · SMS-Awarie</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<script src="<?= asset('assets/js/app.js') ?>" defer></script>
</head>
<body<?= $u ? ' class="with-side"' : '' ?>>
<?php if ($u): ?>
<input type="checkbox" id="navt" class="navt" aria-hidden="true" tabindex="-1">
<header class="top">
  <label for="navt" class="btn btn-o" role="button">Menu</label>
  <a class="brand" href="<?= h(url('dashboard')) ?>">SMS-Awarie</a>
</header>
<label for="navt" class="side-shade" aria-hidden="true"></label>
<aside class="side">
  <a class="brand" href="<?= h(url('dashboard')) ?>">SMS-Awarie</a>
  <nav aria-label="Menu główne">
  <?php foreach (nav_items($u) as $i): if ($i[3] === 'admin' && !is_admin($u)) continue; ?>
    <?php if ($i[0] !== null): echo nav_link($i); else:
      $active = false; foreach ($i[4] as $c) $active = $active || nav_current($c[0], $c[2]); ?>
      <details class="sub" open>
        <summary<?= $active ? ' class="active"' : '' ?>><?= h($i[1]) ?></summary>
        <?php foreach ($i[4] as $c) echo nav_link($c); ?>
      </details>
    <?php endif; ?>
  <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <span class="who"><?= h($u['login']) ?> · <?= h(ROLES[$u['role']] ?? $u['role']) ?></span>
    <form method="post" action="<?= h(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-o">Wyloguj</button></form>
  </div>
</aside>
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
