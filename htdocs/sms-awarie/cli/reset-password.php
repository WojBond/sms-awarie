<?php
// SMS-Awarie - awaryjne ustawienie hasla (np. gdy administrator zapomnial hasla).
// Uzycie: <XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\reset-password.php LOGIN
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../common.php';
require __DIR__.'/../groups.php';
$login = $argv[1] ?? '';
if ($login === '') exit("Uzycie: php reset-password.php LOGIN\n");
$p = db(); ensureSchema($p);
$q = $p->prepare('SELECT id FROM web_users WHERE login=?'); $q->execute([$login]); $id = $q->fetchColumn();
if (!$id) exit("Brak konta $login\n");
echo 'Nowe haslo (min. 12 znakow): ';
$pass = trim((string)fgets(STDIN));
if (strlen($pass) < 12) exit("Haslo za krotkie\n");
$p->prepare('UPDATE web_users SET password_hash=?,active=1 WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
echo "OK - haslo zmienione, konto aktywne.\n";
