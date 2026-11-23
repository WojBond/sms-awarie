<?php
// SMS-Awarie - wspolne funkcje odbiornika i API.
define('APP_VERSION', '3.1.1');
const APP_CHANGELOG = [
    '3.1.1' => [
        'Polskie znaki i nawiasy w SMS nie blokuja juz odbiornika (transliteracja, bledna wiadomosc dostaje status BLAD).',
        'Odebrane SMS-y sa usuwane z modemu po zapisie w bazie; zajetosc pamieci modemu na Dashboardzie.',
        'Ustawienia autoodpowiedzi dzialaja dla grupy Pojazdy.',
        'Schemat bazy sprawdzany raz (PRAGMA user_version) zamiast przy kazdym zapytaniu API; indeksy.',
        'Dziennik odbiornika w panelu czyta logs/receiver-stdout.log; rotacja logow Watchdoga.',
        'Listy bez zapytan N+1, limit 1000 rekordow; do 5 SMS na cykl wysylki.',
        'Blokada startu z haslem CHANGE_ME, dluzsza przerwa po odrzuconym logowaniu, obsluga password_type 4.',
        'Codzienny backup SQLite z retencja (backup.php + 16-install-backup-task.ps1).',
        'Strefa czasowa Europe/Warsaw, wersja w panelu.',
    ],
];
date_default_timezone_set('Europe/Warsaw');
function config(){return array_merge(['modem'=>'http://192.168.8.1','modempass'=>'','ack'=>false,'server'=>'','database'=>'','user'=>'','password'=>''],is_file(__DIR__.'/data/config.json')?json_decode(file_get_contents(__DIR__.'/data/config.json'),true,512,JSON_THROW_ON_ERROR):[]);}
function saveconfig($c){file_put_contents(__DIR__.'/data/config.json',json_encode($c,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);}
function db($type,$c){if($type==='sqlite'){$p=new PDO('sqlite:'.__DIR__.'/data/awarie.sqlite');$p->exec('PRAGMA busy_timeout=10000');$p->exec('PRAGMA journal_mode=WAL');}else{if(!in_array('sqlsrv',PDO::getAvailableDrivers()))throw new Exception('Brak rozszerzenia pdo_sqlsrv');foreach(['server','database'] as $k)if(preg_match('/[;{}]/',$c[$k]))throw new Exception('Nieprawidłowa konfiguracja');$p=new PDO('sqlsrv:Server='.$c['server'].';Database='.$c['database'].';Encrypt=yes;TrustServerCertificate=no',$c['user'],$c['password']);}$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);return $p;}
function uid(){return bin2hex(random_bytes(16));}function plate($s){return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$s));}
function definitions(){return ['outbox'=>['id'=>'varchar(64) NOT NULL PRIMARY KEY','phone'=>'varchar(32) NOT NULL','content'=>'nvarchar(max) NOT NULL','status'=>'varchar(32) NOT NULL','created'=>'varchar(32) NOT NULL','detail'=>'nvarchar(max) NOT NULL'],'vehicles'=>['id'=>'varchar(64) NOT NULL PRIMARY KEY','plate'=>'varchar(20) NOT NULL UNIQUE'],'sms'=>['id'=>'varchar(64) NOT NULL PRIMARY KEY','modem_index'=>'varchar(32) NOT NULL','sender'=>'nvarchar(100) NOT NULL','content'=>'nvarchar(max) NOT NULL','received'=>'varchar(32) NOT NULL','status'=>'varchar(32) NOT NULL'],'tickets'=>['id'=>'varchar(64) NOT NULL PRIMARY KEY','sms_id'=>'varchar(64) NOT NULL UNIQUE','plate'=>'varchar(20) NOT NULL','description'=>'nvarchar(max) NOT NULL','status'=>'varchar(32) NOT NULL','created'=>'varchar(32) NOT NULL']];}
function schema($p,$type){foreach(definitions() as $t=>$cols){$parts=[];foreach($cols as $k=>$v)$parts[]="$k ".($type==='sqlite'?str_replace('nvarchar(max)','TEXT',$v):$v);$ddl="CREATE TABLE $t (".implode(',',$parts).')';$p->exec($type==='sqlite'?str_replace('CREATE TABLE','CREATE TABLE IF NOT EXISTS',$ddl):"IF OBJECT_ID('dbo.$t','U') IS NULL $ddl");}}
function migrate($src,$dst){$n=0;$dst->beginTransaction();try{foreach(definitions() as $t=>$cols){$keys=array_keys($cols);$fields=implode(',',$keys);$ins=$dst->prepare("INSERT INTO $t ($fields) VALUES (".implode(',',array_fill(0,count($keys),'?')).')');$sel=$dst->prepare("SELECT $fields FROM $t WHERE id=?");foreach($src->query("SELECT $fields FROM $t") as $r){$sel->execute([$r['id']]);$old=$sel->fetch();$sel->closeCursor();if($old){foreach($keys as $k)if((string)$old[$k] !== (string)$r[$k])throw new Exception("Konflikt w $t: ".$r['id']);continue;}$ins->execute(array_values($r));$n++;}}$dst->commit();return $n;}catch(Throwable $e){$dst->rollBack();throw $e;}}

function queueSms($p,$id,$phone,$message){$message=smsAscii($message);if(!preg_match('/^\+?[0-9]{9,15}$/D',$phone))throw new Exception('Niepoprawny numer telefonu');if(!preg_match('/^[A-Za-z0-9 .,!:?+\/-]{1,150}$/D',$message))throw new Exception('SMS: maks. 150 prostych znakow ASCII');$p->prepare('INSERT INTO outbox VALUES(?,?,?,?,?,?)')->execute([$id,$phone,$message,'OCZEKUJE',date('Y-m-d H:i:s'),'']);}

// Stable human-readable reference. Does not change the SQLite schema.
function ticketNumber($p, $id) {
    $q = $p->prepare('SELECT created FROM tickets WHERE id=?');
    $q->execute([$id]);
    $created = $q->fetchColumn();
    $q->closeCursor();
    if ($created === false) return null;
    return ticketNumberFrom((string)$id, (string)$created);
}
function ticketNumberFrom(string $id, string $created): string {
    $year = preg_match('/^\d{4}/', $created) ? substr($created, 0, 4) : '0000';
    return 'AW/'.$year.'/'.strtoupper(substr($id, 0, 8));
}

// Zamienia tekst na bezpieczne ASCII dla SMS: polskie litery -> lacinskie,
// znaki spoza dozwolonego zestawu usuwane lub zamieniane na spacje. Nigdy nie rzuca wyjatku.
function smsAscii(string $s): string {
    $s = strtr($s, [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z',
        '(' =>'', ')'=>'', '['=>'', ']'=>'', '"'=>'', "'"=>'', '„'=>'', '”'=>'', '“'=>'', '’'=>'',
        '_'=>'-', ';'=>',', '–'=>'-', '—'=>'-', '…'=>'...', '#'=>'nr ', '%'=>' proc.', '&'=>' i ', '*'=>'', '='=>' ',
        "\r"=>' ', "\n"=>' ', "\t"=>' ',
    ]);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = preg_replace('/[^A-Za-z0-9 .,!:?+\/-]/', ' ', $s) ?? '';
    return trim(preg_replace('/ {2,}/', ' ', $s) ?? '');
}

// Ostatnie linie pliku bez wczytywania calosci (logi moga byc duze).
function tailLines(string $file, int $lines = 100, int $maxBytes = 65536): array {
    if (!is_file($file)) return [];
    $size = filesize($file);
    $f = @fopen($file, 'rb');
    if (!$f) return [];
    $start = max(0, $size - $maxBytes);
    fseek($f, $start);
    $data = (string)stream_get_contents($f);
    fclose($f);
    $all = preg_split('/\r?\n/', $data);
    if ($start > 0) array_shift($all); // pierwsza linia moze byc ucieta
    $all = array_values(array_filter($all, fn($l) => trim($l) !== ''));
    return array_slice($all, -$lines);
}

// Supported placeholders: {number}, {plate}, {status}.
function ackText($c, $number, $registration, $status) {
    $template = trim((string)($c['ack_template'] ?? ''));
    if ($template === '') $template = 'Przyjeto zgloszenie {number} dla {plate}.';
    return strtr($template, [
        '{number}' => (string)$number,
        '{plate}' => (string)$registration,
        '{status}' => (string)$status,
    ]);
}
