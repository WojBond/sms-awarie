<?php
// SMS-Awarie - wspolne funkcje odbiornika i panelu.
require_once __DIR__.'/config.php';

function config(){
    $defaults=['modem'=>'http://192.168.8.1','modempass'=>'','ack'=>false,'own_phone'=>'','ack_template'=>'','delete_after_import'=>true,'send_per_cycle'=>5];
    $file=DATA_DIR.'/config.json';
    return array_merge($defaults,is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[]);
}
function saveconfig($c){file_put_contents(DATA_DIR.'/config.json',json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);}
// 4.0.0: tylko SQLite (migracja do MS SQL z wersji 3.x usunieta - nie obejmowala grup i nie byla uzywana przez odbiornik).
function db($type='sqlite',$c=null){
    $p=new PDO('sqlite:'.DATA_DIR.'/awarie.sqlite');
    $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $p->exec('PRAGMA busy_timeout=10000');
    $p->exec('PRAGMA journal_mode=WAL');
    return $p;
}
function uid(){return bin2hex(random_bytes(16));}
function plate($s){return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$s));}
function schema($p,$type='sqlite'){
    $p->exec('CREATE TABLE IF NOT EXISTS outbox (id varchar(64) NOT NULL PRIMARY KEY, phone varchar(32) NOT NULL, content TEXT NOT NULL, status varchar(32) NOT NULL, created varchar(32) NOT NULL, detail TEXT NOT NULL)');
    $p->exec('CREATE TABLE IF NOT EXISTS vehicles (id varchar(64) NOT NULL PRIMARY KEY, plate varchar(20) NOT NULL UNIQUE)');
    $p->exec('CREATE TABLE IF NOT EXISTS sms (id varchar(64) NOT NULL PRIMARY KEY, modem_index varchar(32) NOT NULL, sender varchar(100) NOT NULL, content TEXT NOT NULL, received varchar(32) NOT NULL, status varchar(32) NOT NULL)');
    $p->exec('CREATE TABLE IF NOT EXISTS tickets (id varchar(64) NOT NULL PRIMARY KEY, sms_id varchar(64) NOT NULL UNIQUE, plate varchar(20) NOT NULL, description TEXT NOT NULL, status varchar(32) NOT NULL, created varchar(32) NOT NULL)');
}
function hasColumn(PDO $p,string $table,string $col): bool {
    foreach($p->query("PRAGMA table_info($table)")->fetchAll() as $c)if($c['name']===$col)return true;
    return false;
}

function queueSms($p,$id,$phone,$message){$message=smsAscii($message);if(!preg_match('/^\+?[0-9]{9,15}$/D',$phone))throw new Exception('Niepoprawny numer telefonu');if(!preg_match('/^[A-Za-z0-9 .,!:?+\/-]{1,150}$/D',$message))throw new Exception('SMS: maks. 150 prostych znakow ASCII');$p->prepare('INSERT INTO outbox VALUES(?,?,?,?,?,?)')->execute([$id,$phone,$message,'OCZEKUJE',date('Y-m-d H:i:s'),'']);}

// Stable human-readable reference. Does not change the SQLite schema.
function ticketNumber($p, $id) {
    $q = $p->prepare('SELECT created,seq FROM tickets WHERE id=?');
    $q->execute([$id]);
    $r = $q->fetch();
    $q->closeCursor();
    if (!$r) return null;
    return ticketNumberFrom((string)$id, (string)$r['created'], $r['seq'] !== null ? (int)$r['seq'] : null);
}
// 4.0.0: nowe zgloszenia maja kolejny numer w roku (AW/2026/00042). Starsze zachowuja numer z 3.x,
// bo ten numer mogl juz zostac wyslany SMS-em.
function ticketNumberFrom(string $id, string $created, ?int $seq = null): string {
    $year = preg_match('/^\d{4}/', $created) ? substr($created, 0, 4) : '0000';
    if ($seq !== null && $seq > 0) return 'AW/'.$year.'/'.str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    return 'AW/'.$year.'/'.strtoupper(substr($id, 0, 8));
}
function nextTicketSeq(PDO $p, string $created): int {
    $q = $p->prepare('SELECT COALESCE(MAX(seq),0)+1 FROM tickets WHERE substr(created,1,4)=?');
    $q->execute([substr($created, 0, 4)]);
    $n = (int)$q->fetchColumn();
    $q->closeCursor();
    return $n;
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
