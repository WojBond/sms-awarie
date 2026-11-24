<?php
// SMS-Awarie - panel WWW (router). Udostepniany przez Apache: Alias /sms-awarie -> C:/SMS-Awarie/public
ini_set('display_errors', '0');
require __DIR__.'/../common.php';
require __DIR__.'/../groups.php';
require __DIR__.'/../web/lib.php';

session_name('smsawarie');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

// strona => [plik widoku, tytul, wymagana rola: '' = kazdy zalogowany, 'admin']
const PAGES = [
    'dashboard' => ['dashboard', 'Dashboard', ''],
    'tickets'   => ['tickets', 'Zgłoszenia', ''],
    'messages'  => ['messages', 'SMS odebrane', ''],
    'outbox'    => ['outbox', 'SMS wychodzące', ''],
    'vehicles'  => ['vehicles', 'Pojazdy', ''],
    'groups'    => ['groups', 'Grupy zgłoszeń', 'admin'],
    'users'     => ['users', 'Użytkownicy', 'admin'],
    'settings'  => ['settings', 'Ustawienia i modem', 'admin'],
    'database'  => ['database', 'Baza danych', 'admin'],
    'logs'      => ['logs', 'Dziennik zdarzeń', 'admin'],
];

try {
    $p = db();
    ensureSchema($p);
    $page = (string)($_GET['p'] ?? 'dashboard');
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($post) check_csrf();

    // Pierwsze uruchomienie: brak kont -> formularz pierwszego administratora.
    if ((int)one($p, 'SELECT COUNT(*) FROM web_users') === 0) { require APP_ROOT.'/views/setup.php'; exit; }

    if ($page === 'logout' && $post) { session_destroy(); session_start(); flash('Wylogowano.'); redirect('login'); }
    $u = current_user($p);
    if (!$u && !empty($_GET['partial'])) { http_response_code(401); exit; }
    if (!$u) { require APP_ROOT.'/views/login.php'; exit; }
    if ($page === 'login') redirect('dashboard');

    if (!isset(PAGES[$page])) { http_response_code(404); $page = 'dashboard'; }
    [$view, $title, $role] = PAGES[$page];
    if ($role === 'admin' && !is_admin($u)) { http_response_code(403); flash('Brak uprawnień do tej strony.', 'err'); redirect('dashboard'); }
    if ($post && !can_write($u)) { http_response_code(403); flash('Konto tylko do odczytu.', 'err'); redirect($page); }

    require APP_ROOT.'/views/'.$view.'.php';
} catch (Throwable $e) {
    if (isset($p) && $p->inTransaction()) $p->rollBack();
    error_log('SMS-Awarie: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Błąd</title><p style="font-family:sans-serif;padding:2rem">Operacja nie powiodła się. Szczegóły w logu Apache (C:\xampp\apache\logs\error.log). <a href="?">Wróć</a></p>';
}
