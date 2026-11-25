<?php
// SMS-Awarie - panel WWW (router). Katalog aplikacji: <XAMPP>\htdocs\sms-awarie, adres http://serwer/sms-awarie/
ini_set('display_errors', '0');
require __DIR__.'/common.php';
require __DIR__.'/groups.php';
require __DIR__.'/web/lib.php';

session_name('smsawarie');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

// strona => [plik widoku, tytul, wymagana rola: '' = kazdy zalogowany, 'admin']
const PAGES = [
    'dashboard' => ['dashboard', 'Dashboard', ''],
    'tickets'   => ['tickets', 'Zgłoszenia', ''],
    'messages'  => ['messages', 'SMS odebrane', ''],
    'outbox'    => ['outbox', 'SMS wychodzące', ''],
    'groups'    => ['groups', 'Grupy zgłoszeń', ''],
    'group'     => ['group', 'Grupa', ''],
    'users'     => ['users', 'Użytkownicy', 'admin'],
    'settings'  => ['settings', 'Autoodpowiedzi', 'admin'],
    'modem'     => ['modem', 'Modem', 'admin'],
    'mail'      => ['mail', 'Poczta e-mail', 'admin'],
    'database'  => ['database', 'Baza danych', 'admin'],
    'services'  => ['services', 'Usługi', 'admin'],
    'logs'      => ['logs', 'Dziennik zdarzeń', 'admin'],
];

try {
    try { $p = db(); ensureSchema($p); }
    catch (PDOException $e) { db_unavailable($e); }
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
    if ($page === 'vehicles') redirect('group', ['id' => 'vehicles']); // adres z wersji 4.0-4.1

    if (!isset(PAGES[$page])) { http_response_code(404); $page = 'dashboard'; }
    [$view, $title, $role] = PAGES[$page];
    if ($role === 'admin' && !is_admin($u)) { http_response_code(403); flash('Brak uprawnień do tej strony.', 'err'); redirect('dashboard'); }
    if ($post && !can_write($u)) { http_response_code(403); flash('Konto tylko do odczytu.', 'err'); redirect($page); }

    require APP_ROOT.'/views/'.$view.'.php';
} catch (Throwable $e) {
    if (isset($p) && $p instanceof PDO && $p->inTransaction()) $p->rollBack();
    error_log('SMS-Awarie: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Błąd</title><p style="font-family:sans-serif;padding:2rem">Operacja nie powiodła się. Szczegóły w logu Apache (xampp\apache\logs\error.log). <a href="?">Wróć</a></p>';
}

// Baza (np. serwer MS SQL) niedostepna: komunikat zamiast pustej strony bledu.
function db_unavailable(PDOException $e): never {
    $s = db_settings();
    error_log('SMS-Awarie: baza niedostepna: '.$e->getMessage());
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Baza niedostępna</title><div style="font-family:sans-serif;padding:2rem;max-width:720px">'
        .'<h1>Brak połączenia z bazą danych</h1><p>'.h(db_label($s)).'</p><p>Odbiornik zapisze nowe SMS-y, gdy baza znów będzie dostępna (SMS-y czekają w modemie).</p>';
    if ($s['driver'] === 'sqlsrv') echo '<p>Sprawdź, czy usługa SQL Server działa i czy serwer jest osiągalny z tego komputera. Powrót do lokalnej bazy SQLite (bez danych zapisanych w MS SQL):</p><pre style="background:#eee;padding:.75rem;white-space:pre-wrap">&lt;XAMPP&gt;\php\php.exe &lt;XAMPP&gt;\htdocs\sms-awarie\cli\db.php sqlite</pre>';
    echo '<p><a href="?">Spróbuj ponownie</a></p></div>';
    exit;
}
