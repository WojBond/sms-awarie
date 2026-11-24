<?php
// SMS-Awarie - odbiornik SMS (Huawei E5373). Uruchamiany wylacznie przez Watchdog, jedna instancja.
require __DIR__.'/common.php';
require __DIR__.'/groups.php';
if(PHP_SAPI!=='cli')exit;
if (!is_dir(__DIR__.'/data')) throw new RuntimeException('Brak katalogu data');
$lock=fopen(__DIR__.'/data/receiver.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit('Odbiornik już działa.');

function request($path,$body=null){global $ch,$cfg,$token;curl_setopt_array($ch,[CURLOPT_URL=>rtrim($cfg['modem'],'/').$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEFILE=>'',CURLOPT_HTTPHEADER=>['X-Requested-With: XMLHttpRequest','Origin: '.$cfg['modem'],'Referer: '.$cfg['modem'].'/html/home.html','__RequestVerificationToken: '.$token,'Content-Type: application/x-www-form-urlencoded; charset=UTF-8']]);if($body===null){curl_setopt($ch,CURLOPT_HTTPGET,true);}else{curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$body);} $r=curl_exec($ch);if($r===false||curl_getinfo($ch,CURLINFO_HTTP_CODE)!==200)throw new Exception('Błąd HTTP modemu');if($path==='/html/home.html')return;$x=simplexml_load_string($r,'SimpleXMLElement',LIBXML_NONET);if($x===false||$x->getName()==='error')throw new Exception('API: '.($x?(string)$x->code:'XML'));return $x;}
function token(){global $token;$token=(string)request('/api/webserver/token')->token;if(!$token)throw new Exception('Brak tokenu');}

function login(){
    global $ch,$cfg,$token;
    $ch=curl_init();
    request('/html/home.html');
    // Nowsze firmware zglaszaja password_type 4 (SHA-256 z tokenem); starsze - base64.
    $type=0;
    try{$type=(int)request('/api/user/state-login')->password_type;}catch(Throwable $e){$type=0;}
    token();
    if($type===4){
        $pw=base64_encode(hash('sha256','admin'.base64_encode(hash('sha256',$cfg['modempass'])).$token));
        $body='<?xml version="1.0" encoding="UTF-8"?><request><Username>admin</Username><Password>'.$pw.'</Password><password_type>4</password_type></request>';
    }else{
        $body='<?xml version="1.0" encoding="UTF-8"?><request><Username>admin</Username><Password>'.base64_encode($cfg['modempass']).'</Password></request>';
    }
    try{$x=request('/api/user/login',$body);}catch(Throwable $e){throw new LoginRejected('Logowanie odrzucone: '.$e->getMessage());}
    if((string)$x!=='OK')throw new LoginRejected('Logowanie odrzucone');
}
class LoginRejected extends Exception {}

function sendOne($p){
    $stmt=$p->query("SELECT * FROM outbox WHERE status='OCZEKUJE' ORDER BY created LIMIT 1");$r=$stmt->fetch();$stmt->closeCursor();
    if(!$r)return false;
    token();
    $q=$p->prepare("UPDATE outbox SET status='W_TRAKCIE' WHERE id=? AND status='OCZEKUJE'");$q->execute([$r['id']]);if(!$q->rowCount())return false;
    $status='NIEPEWNY';$detail='';
    try{
        $phone=htmlspecialchars($r['phone'],ENT_XML1);$msg=htmlspecialchars($r['content'],ENT_XML1);$len=strlen($r['content']);$date=date('Y-m-d H:i:s');
        $x=request('/api/sms/send-sms','<?xml version="1.0" encoding="UTF-8"?>'."<request><Index>-1</Index><Phones><Phone>$phone</Phone></Phones><Sca></Sca><Content>$msg</Content><Length>$len</Length><Reserved>1</Reserved><Date>$date</Date></request>");
        if((string)$x!=='OK')throw new Exception('Nieoczekiwana odpowiedz');
        $status='PRZYJETY';$detail='Modem zwrocil OK; nie jest to potwierdzenie doreczenia.';
    }catch(Throwable $e){$detail=$e->getMessage();}
    $p->prepare('UPDATE outbox SET status=?,detail=? WHERE id=?')->execute([$status,$detail,$r['id']]);
    return true;
}
// v3.1.1: kilka SMS na cykl (domyslnie 5), z przerwa miedzy wysylkami.
function sendQueued($p,$max){
    for($i=0;$i<$max;$i++){
        if(!sendOne($p))return;
        receiverHeartbeat('RUNNING');
        if($i<$max-1)sleep(2);
    }
}

function deleteSms($index){
    token();
    request('/api/sms/delete-sms','<?xml version="1.0" encoding="UTF-8"?><request><Index>'.(int)$index.'</Index></request>');
}
function readModemStorage(){
    try{$x=request('/api/sms/sms-count');$GLOBALS['modemStorage']=['inbox'=>(int)$x->LocalInbox,'max'=>(int)$x->LocalMax,'simInbox'=>(int)$x->SimInbox,'simMax'=>(int)$x->SimMax];}
    catch(Throwable $e){/* informacyjne - brak danych nie jest bledem */}
}

function receiverHeartbeat($state) {
    $path = __DIR__.'/data/heartbeat.json';
    $tmp = $path.'.'.getmypid().'.tmp';
    $data = json_encode(['time'=>time(),'state'=>$state,'pid'=>getmypid(),'updated'=>gmdate('c'),'phase'=>$GLOBALS['receiverPhase']??'idle','lastError'=>$GLOBALS['receiverLastError']??'','modem'=>$GLOBALS['modemStorage']??null,'version'=>APP_VERSION], JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Nie mozna zapisac heartbeat.json');
    }
}

// Zapisuje jeden SMS. Zwraca true, gdy wiadomosc jest juz bezpiecznie w bazie (nowa lub wczesniej zapisana).
// Blad bazy (PDOException) przerywa cykl; kazdy inny blad oznacza tylko te wiadomosc jako BLAD.
function processMessage(PDO $p,string $id,string $idx,string $sender,string $content,string $date): bool {
    $p->beginTransaction();
    try{
        $q=$p->prepare('SELECT id FROM sms WHERE id=?');$q->execute([$id]);$exists=$q->fetch();$q->closeCursor();
        if($exists){$p->commit();return true;}
        $validSender=(bool)preg_match('/^\+?[0-9]{7,15}$/D',$sender);
        [$group,$payload]=groupForMessage($p,$content);
        $parsed=$group?groupParsePayload($group,$payload):null;
        $valid=$validSender && $parsed!==null;
        $status='NIEPOPRAWNY';
        if($valid){
            [$reg,$description]=$parsed;
            if((int)$group['legacy_vehicle']===1){
                $q=$p->prepare('SELECT id FROM vehicles WHERE plate=? AND active=1');$q->execute([$reg]);$vehicle=$q->fetch();$q->closeCursor();
                $status=$vehicle?'NOWE':'WERYFIKACJA';
            }else{$status='NOWE';}
        }
        $p->prepare('INSERT INTO sms VALUES(?,?,?,?,?,?)')->execute([$id,$idx,$sender,$content,$date,$status]);
        if($valid){
            $tid=uid();$created=date('Y-m-d H:i:s');
            $p->prepare('INSERT INTO tickets(id,sms_id,plate,description,status,created,seq) VALUES(?,?,?,?,?,?,?)')->execute([$tid,$id,$reg,$description,$status,$created,nextTicketSeq($p,$created)]);
            $p->prepare('INSERT INTO ticket_groups(ticket_id,group_id,object_code) VALUES(?,?,?)')->execute([$tid,$group['id'],$reg]);
            enqueueGroupNotifications($p,$group,$tid,$sender,$reg,$description);
        }
        $p->commit();
        return true;
    }catch(PDOException $e){
        $p->rollBack();throw $e;
    }catch(Throwable $e){
        $p->rollBack();
        echo date('c').' [SMS BLAD] '.$id.' '.$e->getMessage().PHP_EOL;
        // Zapis samego SMS ze statusem BLAD, zeby nie byl przetwarzany w kolko i byl widoczny w panelu.
        $p->prepare('INSERT OR IGNORE INTO sms VALUES(?,?,?,?,?,?)')->execute([$id,$idx,$sender,$content,$date,'BLAD']);
        return true;
    }
}

$receiverPhase='starting';$receiverLastError='';$modemStorage=null;
$cfg=config();
if(in_array(trim((string)$cfg['modempass']),['','CHANGE_ME'],true))exit('Ustaw prawdziwe haslo modemu w data/config.json lub w panelu.');
$deleteAfterImport=!array_key_exists('delete_after_import',$cfg)||!empty($cfg['delete_after_import']);
$sendPerCycle=max(1,min(20,(int)($cfg['send_per_cycle']??5)));
$p=db('sqlite',$cfg);ensureSchema($p);
$ch=null;$token='';
while(true){
    try{
        $receiverPhase='polling';$receiverLastError='';receiverHeartbeat('RUNNING');
        if(!$ch)login();
        $toDelete=[];
        for($page=1;$page<=25;$page++){
            token();
            $x=request('/api/sms/sms-list',"<request><PageIndex>$page</PageIndex><ReadCount>20</ReadCount><BoxType>1</BoxType><SortType>0</SortType><Ascending>0</Ascending><UnreadPreferred>0</UnreadPreferred></request>");
            $count=0;
            foreach($x->Messages->Message??[] as $m){
                $count++;
                $sender=(string)$m->Phone;$content=(string)$m->Content;$date=(string)$m->Date;$idx=(string)$m->Index;
                $id=hash('sha256',json_encode([$sender,$content,$date,$idx],JSON_THROW_ON_ERROR));
                $receiverPhase='processing';receiverHeartbeat('RUNNING');
                if(processMessage($p,$id,$idx,$sender,$content,$date))$toDelete[]=$idx;
            }
            if($count<20)break;
        }
        // v3.1.1: usuwanie z modemu dopiero po zapisie w bazie i po przejsciu wszystkich stron listy.
        if($deleteAfterImport && $toDelete){
            $receiverPhase='cleanup';
            $deleted=0;
            foreach($toDelete as $idx){
                try{deleteSms($idx);$deleted++;}catch(Throwable $e){echo date('c').' [MODEM] Nie usunieto SMS '.$idx.': '.$e->getMessage().PHP_EOL;break;}
            }
            echo date('c')." [MODEM] Usunieto z modemu: $deleted".PHP_EOL;
        }
        readModemStorage();
        $diag=$p->query("SELECT COUNT(*) FROM outbox WHERE status='OCZEKUJE'");$pending=$diag->fetchColumn();$diag->closeCursor();
        if($pending>0)echo "[OUTBOX] Oczekujace: ".$pending.PHP_EOL;
        $receiverPhase='sending';receiverHeartbeat('RUNNING');
        sendQueued($p,$sendPerCycle);
        $receiverPhase='idle';receiverHeartbeat('RUNNING');
        sleep(15);
    }catch(Throwable $e){
        $receiverPhase='error';$receiverLastError=substr($e->getMessage(),0,240);
        try{receiverHeartbeat('ERROR');}catch(Throwable $ignored){}
        echo date('c').' '.$e->getMessage()."\n";
        if($ch)curl_close($ch);$ch=null;
        // Po odrzuconym logowaniu dluzsza przerwa, zeby nie zablokowac konta admin modemu.
        sleep($e instanceof LoginRejected?300:60);
    }
}
