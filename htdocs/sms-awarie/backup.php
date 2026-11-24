<?php
// SMS-Awarie - codzienna kopia bazy z retencja.
// SQLite: VACUUM INTO + integrity_check. MS SQL: BACKUP DATABASE ... COPY_ONLY + RESTORE VERIFYONLY (plik na serwerze SQL).
// Uzycie: <XAMPP>\php\php.exe <XAMPP>\htdocs\sms-awarie\backup.php [dni_retencji=30] [katalog_docelowy]
// Uruchamiane z Harmonogramu zadan (scripts\install-backup-task.ps1). Dla MS SQL katalog_docelowy jest sciezka na serwerze SQL.
require __DIR__.'/common.php';
if(PHP_SAPI!=='cli')exit(1);
$keepDays=max(1,(int)($argv[1]??30));
try{
    $s=db_settings();
    if($s['driver']==='sqlsrv'){
        if(!empty($argv[2]))$s['backup_dir']=$argv[2];
        $p=db($s);
        $dest=mssql_backup($p,$s,'daily');
        $removed=mssql_backup_cleanup(mssql_backup_dir($p,$s),'daily',$keepDays);
        echo date('c')." BACKUP MSSQL OK: $dest (usunieto starych: $removed)\n";
        exit(0);
    }
    $dir=rtrim(str_replace('\\','/',$argv[2]??__DIR__.'/data/backups'),'/');
    $source=__DIR__.'/data/awarie.sqlite';
    if(!is_file($source))throw new RuntimeException('Brak bazy: '.$source);
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Nie mozna utworzyc katalogu: '.$dir);
    $dest=$dir.'/daily-'.date('Ymd-His').'.sqlite';
    $p=new PDO('sqlite:'.$source,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $p->exec('PRAGMA busy_timeout=30000');
    $p->exec('VACUUM INTO '.$p->quote($dest));
    $check=(new PDO('sqlite:'.$dest))->query('PRAGMA integrity_check')->fetchColumn();
    if($check!=='ok'){@unlink($dest);throw new RuntimeException('integrity_check: '.$check);}
    $removed=0;
    foreach(glob($dir.'/daily-*.sqlite')?:[] as $f){
        if($f!==$dest && filemtime($f)<time()-$keepDays*86400 && @unlink($f))$removed++;
    }
    echo date('c')." BACKUP OK: $dest (usunieto starych: $removed)\n";
}catch(Throwable $e){
    fwrite(STDERR,date('c').' BACKUP BLAD: '.$e->getMessage()."\n");
    exit(1);
}
