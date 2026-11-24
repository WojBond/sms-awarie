<?php
// SMS-Awarie - baza danych z wiersza polecen.
// Uzycie: <XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\db.php status
//         <XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\cli\db.php sqlite   (powrot do SQLite, gdy MS SQL jest niedostepny)
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../common.php';
require __DIR__.'/../groups.php';
$cmd = $argv[1] ?? 'status';
try {
    $c = config();
    $s = db_settings($c);
    if ($cmd === 'status') {
        echo 'Baza: '.db_label($s)."\n";
        $p = db($s);
        echo 'Schemat: v'.schema_version($p)."\n";
        foreach (db_counts($p) as $t => $n) echo str_pad($t, 26).($n === null ? 'brak tabeli' : $n)."\n";
        if (!db_is_mssql($p)) echo 'integrity_check: '.$p->query('PRAGMA integrity_check')->fetchColumn()."\n";
    } elseif ($cmd === 'sqlite') {
        if ($s['driver'] === 'sqlite') exit("Aplikacja juz uzywa SQLite.\n");
        if (!receiver_pause()) echo "UWAGA: odbiornik nie potwierdzil wstrzymania - przelaczam mimo to.\n";
        try {
            $c['db'] = ['driver' => 'sqlite'] + $s;
            saveconfig($c);
            $p = db($c['db']); ensureSchema($p);
        } finally { receiver_resume(); }
        echo "OK - aplikacja uzywa SQLite (data\\awarie.sqlite). Ustawienia MS SQL zostaly zapamietane.\n";
    } else {
        exit("Uzycie: php db.php status|sqlite\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'BLAD: '.$e->getMessage()."\n");
    exit(1);
}
