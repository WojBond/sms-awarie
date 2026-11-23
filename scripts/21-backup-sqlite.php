<?php
if(PHP_SAPI!=='cli')exit(1);
$source='C:/SMS-Awarie/data/awarie.sqlite';
if(!is_file($source))throw new RuntimeException('Brak bazy: '.$source);
$dir='C:/SMS-Awarie/data/backups';if(!is_dir($dir))mkdir($dir,0700,true);
$dest=$dir.'/pre-v3.1-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$p=new PDO('sqlite:'.$source);$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('VACUUM INTO '.$p->quote($dest));
$q=new PDO('sqlite:'.$dest);$check=$q->query('PRAGMA integrity_check')->fetchColumn();if($check!=='ok')throw new RuntimeException('Kopia uszkodzona: '.$check);
echo "BACKUP OK: $dest\n";
