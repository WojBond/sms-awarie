<?php
// CLI only, creates a transactionally consistent SQLite snapshot via VACUUM INTO.
if (PHP_SAPI !== 'cli' || $argc !== 3) { fwrite(STDERR,"Usage: php 02-backup-sqlite.php SOURCE.sqlite DEST.sqlite\n"); exit(2); }
[$script,$source,$dest]=$argv;
if (!is_file($source) || file_exists($dest)) { fwrite(STDERR,"Source missing or destination exists\n"); exit(2); }
try {
 $db=new PDO('sqlite:'.$source,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('PRAGMA busy_timeout=10000');
 $db->exec('VACUUM INTO '.$db->quote($dest));
 $check=new PDO('sqlite:'.$dest);
 $result=$check->query('PRAGMA integrity_check')->fetchColumn();
 if ($result!=='ok') throw new RuntimeException('integrity_check: '.$result);
 echo "OK snapshot: $dest\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
