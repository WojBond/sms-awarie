<?php
// Spojna kopia SQLite przez VACUUM INTO. Uzywane przez install.ps1 przed aktualizacja.
// Uzycie: php snapshot-sqlite.php ZRODLO.sqlite CEL.sqlite
if (PHP_SAPI !== 'cli' || $argc !== 3) { fwrite(STDERR, "Uzycie: php snapshot-sqlite.php ZRODLO CEL\n"); exit(2); }
[, $source, $dest] = $argv;
if (!is_file($source) || file_exists($dest)) { fwrite(STDERR, "Brak zrodla lub cel juz istnieje\n"); exit(2); }
try {
    $db = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('VACUUM INTO '.$db->quote($dest));
    $r = (new PDO('sqlite:'.$dest))->query('PRAGMA integrity_check')->fetchColumn();
    if ($r !== 'ok') throw new RuntimeException('integrity_check: '.$r);
    echo "OK kopia bazy: $dest\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
