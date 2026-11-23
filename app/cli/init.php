<?php
// SMS-Awarie - inicjalizacja / migracja schematu bazy. Uruchamiane przez scripts\install.ps1.
// Uzycie: C:\xampp\php\php.exe C:\SMS-Awarie\cli\init.php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../common.php';
require __DIR__.'/../groups.php';
try {
    foreach (['pdo_sqlite', 'curl', 'SimpleXML'] as $ext) if (!extension_loaded($ext)) throw new RuntimeException("Brak rozszerzenia PHP: $ext (wlacz w C:\\xampp\\php\\php.ini)");
    foreach ([DATA_DIR, LOG_DIR, DATA_DIR.'/backups'] as $d) if (!is_dir($d) && !mkdir($d, 0700, true)) throw new RuntimeException("Nie mozna utworzyc $d");
    if (!is_file(DATA_DIR.'/config.json')) {
        saveconfig(['modem' => 'http://192.168.8.1', 'modempass' => 'CHANGE_ME', 'ack' => false, 'own_phone' => '', 'ack_template' => '', 'delete_after_import' => true, 'send_per_cycle' => 5]);
        echo "Utworzono data\\config.json - ustaw haslo modemu w panelu (Ustawienia i modem).\n";
    }
    $p = db();
    $before = (int)$p->query('PRAGMA user_version')->fetchColumn();
    ensureSchema($p);
    $after = (int)$p->query('PRAGMA user_version')->fetchColumn();
    $users = (int)$p->query('SELECT COUNT(*) FROM web_users')->fetchColumn();
    echo "Baza OK: schemat v$before -> v$after, zgloszen: ".$p->query('SELECT COUNT(*) FROM tickets')->fetchColumn().", kont: $users\n";
    if ($users === 0) echo "Brak kont - pierwszego administratora utworzysz w przegladarce przy pierwszym wejsciu.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BLAD: '.$e->getMessage()."\n");
    exit(1);
}
