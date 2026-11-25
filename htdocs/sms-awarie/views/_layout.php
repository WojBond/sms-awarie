<?php
// Wspolny szablon strony z menu bocznym. Uzycie: layout_start($title, $u, $page); ... layout_end();

// Menu: [strona, etykieta, parametry, rola] albo [null, etykieta, [], rola, podmenu].
function nav_items(?array $u): array {
    $groups = [['groups', 'Wszystkie grupy', [], '']];
    try {
        $lv = $u ? group_levels($GLOBALS['p'], $u) : [];
        foreach (rows($GLOBALS['p'], 'SELECT id,name FROM incident_groups WHERE active=1 ORDER BY legacy_vehicle DESC, name') as $g) if (isset($lv[$g['id']])) $groups[] = ['group', $g['name'], ['id' => $g['id']], ''];
    } catch (Throwable $e) { /* menu bez listy grup */ }
    return [
        ['dashboard', 'Dashboard', [], ''],
        ['tickets', 'Zgłoszenia', [], ''],
        ['messages', 'SMS odebrane', [], ''],
        ['outbox', 'SMS wychodzące', [], ''],
        [null, 'Grupy zgłoszeń', [], '', $groups],
        ['users', 'Użytkownicy', [], 'admin'],
        [null, 'Ustawienia', [], 'admin', [['settings', 'Autoodpowiedzi', [], ''], ['modem', 'Modem', [], ''], ['mail', 'Poczta e-mail', [], ''], ['database', 'Baza danych', [], ''], ['services', 'Usługi', [], '']]],
        ['logs', 'Dziennik zdarzeń', [], 'admin'],
    ];
}
// Ikony liniowe (inline SVG, bez bibliotek i CDN). Klucz = strona albo nazwa podmenu.
const NAV_ICONS = [
    'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    'tickets' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    'messages' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/>',
    'outbox' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
    'Grupy zgłoszeń' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 3.1a4 4 0 0 1 0 7.8M22 21a7 7 0 0 0-4-6.3"/>',
    'Ustawienia' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'logs' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'brand' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v4M12 14h.01"/>',
];
function icon(string $key): string {
    return isset(NAV_ICONS[$key]) ? '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">'.NAV_ICONS[$key].'</svg>' : '';
}
function brand(): string {
    return '<a class="brand" href="'.h(url('dashboard')).'"><span class="brand-mark">'.icon('brand').'</span>SMS-Awarie</a>';
}

function nav_current(string $page, array $params): bool {
    if ($page !== ($GLOBALS['navPage'] ?? '')) return false;
    foreach ($params as $k => $v) if ((string)($_GET[$k] ?? '') !== (string)$v) return false;
    return true;
}
function nav_link(array $i, bool $withIcon = true): string {
    return '<a href="'.h(url($i[0], $i[2])).'"'.(nav_current($i[0], $i[2]) ? ' aria-current="page"' : '').'>'.($withIcon ? icon($i[0]) : '').h($i[1]).'</a>';
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
  <label for="navt" class="btn btn-o" role="button"><?= icon('menu') ?>Menu</label>
  <?= brand() ?>
</header>
<label for="navt" class="side-shade" aria-hidden="true"></label>
<aside class="side">
  <?= brand() ?>
  <nav aria-label="Menu główne">
  <?php foreach (nav_items($u) as $i): if ($i[3] === 'admin' && !is_admin($u)) continue; ?>
    <?php if ($i[0] !== null): echo nav_link($i); else:
      $active = false; foreach ($i[4] as $c) $active = $active || nav_current($c[0], $c[2]); ?>
      <details class="sub" open>
        <summary<?= $active ? ' class="active"' : '' ?>><?= icon($i[1]) ?><span><?= h($i[1]) ?></span></summary>
        <?php foreach ($i[4] as $c) echo nav_link($c, false); ?>
      </details>
    <?php endif; ?>
  <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <span class="avatar" aria-hidden="true"><?= h(mb_substr($u['login'], 0, 1)) ?></span>
    <span class="who"><strong><?= h($u['login']) ?></strong><small><?= h(ROLES[$u['role']] ?? $u['role']) ?></small></span>
    <form method="post" action="<?= h(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-o"><?= icon('logout') ?>Wyloguj</button></form>
    <?php version_badge(); ?>
  </div>
</aside>
<?php endif; ?>
<main>
<?php if ($u): ?><h1><?= h($title) ?></h1><?php endif; ?>
<?php if ($u) flash_html();
}

function flash_html(): void {
    foreach (flash() as [$type, $msg]) echo '<p class="flash flash-'.h($type).'" role="'.($type === 'err' ? 'alert' : 'status').'">'.h($msg).'</p>';
}

function layout_end(): void {
    echo "</main>\n";
    if (empty($GLOBALS['versionShown'])) version_badge();
    echo "</body>\n</html>\n";
}

// Plakietka wersji z changelogiem: w stopce menu bocznego, a bez logowania w prawym dolnym rogu.
function version_badge(): void {
    $GLOBALS['versionShown'] = true;
    ?>
<details class="version">
  <summary>v<?= h(APP_VERSION) ?></summary>
  <div class="changelog">
  <?php foreach (APP_CHANGELOG as $v => $items): ?>
    <strong><?= h($v) ?></strong>
    <ul><?php foreach ($items as $i): ?><li><?= h($i) ?></li><?php endforeach; ?></ul>
  <?php endforeach; ?>
  </div>
</details>
<?php
}
