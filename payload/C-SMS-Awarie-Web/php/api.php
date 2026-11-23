<?php
// Place outside the public htdocs tree; expose only this file via Apache Alias.
ini_set('display_errors','0');
require 'C:/SMS-Awarie/common.php';
require 'C:/SMS-Awarie/groups.php';
session_name('smsweb'); session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','path'=>'/']); session_start();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function reply($v,$code=200){http_response_code($code);echo json_encode($v,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;}
function fail($m,$c=400){reply(['error'=>$m],$c);}
function rows($p,$sql,$args=[]){$q=$p->prepare($sql);$q->execute($args);$r=$q->fetchAll();$q->closeCursor();return $r;}
function run($p,$sql,$args=[]){$q=$p->prepare($sql);$q->execute($args);return $q->rowCount();}
try{
$c=config();$p=db('sqlite',$c);ensureSchema($p);
$a=$_GET['section']??'dashboard';$post=$_SERVER['REQUEST_METHOD']==='POST';$b=$post?json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR):[];
$_SESSION['csrf']??=bin2hex(random_bytes(32));
if($post&&!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF']??''))fail('Niepoprawny token CSRF',403);
if($a==='session')reply(['user'=>$_SESSION['user']??null,'csrf'=>$_SESSION['csrf']]);
if($a==='login'&&$post){$u=rows($p,'SELECT * FROM web_users WHERE login=?',[$b['login']??''])[0]??null;if(!$u||!password_verify($b['password']??'',$u['password_hash'])){sleep(1);fail('Niepoprawny login lub haslo',401);}session_regenerate_id(true);$_SESSION['user']=$u['id'];reply(['ok'=>true]);}
if($a==='logout'&&$post){session_destroy();reply(['ok'=>true]);}
$u=rows($p,'SELECT id,login,role FROM web_users WHERE id=?',[$_SESSION['user']??''])[0]??null;if(!$u)fail('Zaloguj sie',401);
$admin=$u['role']==='admin';$write=$admin||$u['role']==='operator';if(in_array($a,['users','settings','database','logs','modem','groups'],true)&&!$admin)fail('Brak uprawnien',403);if($post&&!$write)fail('Brak uprawnien',403);
if($post){$act=$b['action']??'';$id=$b['id']??'';
switch($a){case 'groups':
if(!$admin)fail('Brak uprawnien',403);
if($act==='save'){
 $gid=(string)($b['id']??'');$name=trim((string)($b['name']??''));$prefix=strtoupper(trim((string)($b['prefix']??'')));
 if(!preg_match('/^[A-Za-z0-9 _-]{2,60}$/D',$name)||!preg_match('/^[A-Z][A-Z0-9_-]{1,15}$/D',$prefix))fail('Niepoprawna nazwa lub prefiks');
 $senderTemplate=trim((string)($b['sender_template']??''));$recipientTemplate=trim((string)($b['recipient_template']??''));
 foreach([$senderTemplate,$recipientTemplate] as $template){if(strlen($template)>150||!preg_match('/^[A-Za-z0-9 {}.,!:?+\/-]{1,150}$/D',$template))fail('Szablon musi zawierac 1-150 znakow ASCII');}
 $active=!empty($b['active'])?1:0;$notify=!empty($b['notify_sender'])?1:0;
 $recipients=$b['recipients']??[];if(!is_array($recipients)||count($recipients)>50)fail('Maksymalnie 50 odbiorcow');
 $phones=[];foreach($recipients as $phone){if(!is_string($phone)||!preg_match('/^\+?[0-9]{9,15}$/D',$phone))fail('Niepoprawny numer telefonu');$phones[$phone]=true;}
 $p->beginTransaction();
 if($gid===''){$gid=uid();run($p,'INSERT INTO incident_groups(id,name,prefix,active,legacy_vehicle,notify_sender,sender_template,recipient_template) VALUES(?,?,?,?,0,?,?,?)',[$gid,$name,$prefix,$active,$notify,$senderTemplate,$recipientTemplate]);}
 else{if(!rows($p,'SELECT id FROM incident_groups WHERE id=?',[$gid]))fail('Nie znaleziono grupy',404);if($gid==='vehicles'){$active=1;$prefix='POJ';}run($p,'UPDATE incident_groups SET name=?,prefix=?,active=?,notify_sender=?,sender_template=?,recipient_template=? WHERE id=?',[$name,$prefix,$active,$notify,$senderTemplate,$recipientTemplate,$gid]);}
 run($p,'DELETE FROM group_recipients WHERE group_id=?',[$gid]);
 foreach(array_keys($phones) as $phone)run($p,'INSERT INTO group_recipients(id,group_id,phone,active) VALUES(?,?,?,1)',[uid(),$gid,$phone]);
 $id=$gid;
}elseif($act==='deactivate'){
 if($id==='vehicles')fail('Nie mozna wylaczyc grupy Pojazdy');
 run($p,'UPDATE incident_groups SET active=0 WHERE id=?',[$id]);
}else fail('Nieznana akcja');
break;
case 'tickets':if(!in_array($b['status']??'',['NOWE','WERYFIKACJA','W_REALIZACJI','ZAMKNIETE'],true)||!is_string($b['description']??null)||trim($b['description'])===''||strlen($b['description'])>4000)fail('Niepoprawne dane');$p->beginTransaction();run($p,'UPDATE tickets SET status=?,description=? WHERE id=?',[$b['status'],$b['description'],$id]);break;
case 'vehicles':$reg=plate($b['plate']??'');if(!preg_match('/^[A-Z0-9]{4,12}$/D',$reg))fail('Niepoprawna rejestracja');run($p,'INSERT INTO vehicles(id,plate) VALUES(?,?)',[uid(),$reg]);break;
case 'outbox':if($act==='cancel'){run($p,"UPDATE outbox SET status='ANULOWANY' WHERE id=? AND status='OCZEKUJE'",[$id]);}elseif($act==='send'){if(time()-($_SESSION['last_send']??0)<10)fail('Odczekaj 10 sekund');queueSms($p,uid(),trim($b['phone']??''),trim($b['content']??''));$_SESSION['last_send']=time();}else fail('Nieznana akcja');break;
case 'users':if(!in_array($b['role']??'',['admin','operator','read-only'],true)||!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D',$b['login']??'')||strlen($b['password']??'')<12)fail('Login 3-64 znaki, haslo min. 12 znakow');run($p,'INSERT INTO web_users VALUES(?,?,?,?)',[uid(),$b['login'],password_hash($b['password'],PASSWORD_DEFAULT),$b['role']]);break;
case 'settings':$c['ack']=!empty($b['ack']);$c['own_phone']=trim($b['own_phone']??'');$c['ack_template']=trim($b['ack_template']??'');if($c['ack']&&!preg_match('/^\+?[0-9]{9,15}$/D',$c['own_phone']))fail('Podaj numer SIM');$sample=ackText($c,'AW/2026/00001','ABCDEFGHIJKL','WERYFIKACJA');if(!preg_match('/^[A-Za-z]+ [A-Za-z0-9 .,!:?+\/-]{1,140}$/D',$sample))fail('Szablon ASCII, zacznij od slowa bez cyfr');saveconfig($c);
 // v3.1.1: odbiornik uzywa ustawien grupy Pojazdy - synchronizacja z zakladka Ustawienia.
 $tpl=$c['ack_template']!==''?str_replace('{plate}','{object}',$c['ack_template']):'Przyjeto zgloszenie {number} dla {object}.';
 run($p,"UPDATE incident_groups SET notify_sender=?,sender_template=? WHERE id='vehicles'",[$c['ack']?1:0,$tpl]);break;
case 'modem':if(!preg_match('~^http://192\.168\.8\.1/?$~D',$b['modem']??''))fail('Dozwolony adres modemu 192.168.8.1');$c['modem']=rtrim($b['modem'],'/');if(!empty($b['modempass']))$c['modempass']=$b['modempass'];saveconfig($c);break;
case 'database':if($act==='backup'){$dir='C:/SMS-Awarie/data/backups';if(!is_dir($dir))mkdir($dir,0700,true);$p->exec('VACUUM INTO '.$p->quote($dir.'/web-'.date('Ymd-His').'-'.uid().'.sqlite'));}elseif($act==='save'){foreach(['server','database','user'] as $k)$c[$k]=trim($b[$k]??'');if(!empty($b['password']))$c['password']=$b['password'];saveconfig($c);}elseif($act==='test'){db('mssql',$c)->query('SELECT 1');}elseif(in_array($act,['to_sql','to_local'],true)&&!empty($b['confirm'])){$hb=@json_decode((string)@file_get_contents('C:/SMS-Awarie/data/heartbeat.json'),true);if(is_array($hb)&&time()-($hb['time']??0)<90)fail('Zatrzymaj odbiornik i odczekaj 90 sekund');$other=db('mssql',$c);schema($other,'mssql');migrate($act==='to_sql'?$p:$other,$act==='to_sql'?$other:$p);}else fail('Potwierdz operacje');break;default:fail('Nieznana akcja');}
run($p,'INSERT INTO web_audit(created,actor,action,target) VALUES(?,?,?,?)',[date('c'),$u['login'],$a.':'.$act,$id]);if($p->inTransaction())$p->commit();reply(['ok'=>true]);}
$hb=@json_decode((string)@file_get_contents('C:/SMS-Awarie/data/heartbeat.json'),true);$worker=is_array($hb)&&time()-($hb['time']??0)<90?($hb['state']??'UNKNOWN'):'OFFLINE';
$result=['user'=>$u,'csrf'=>$_SESSION['csrf'],'worker'=>$worker,'version'=>APP_VERSION,'changelog'=>APP_CHANGELOG];
if(is_array($hb)&&is_array($hb['modem']??null))$result['modemStorage']=['inbox'=>(int)($hb['modem']['inbox']??0),'max'=>(int)($hb['modem']['max']??0)];
if($admin && in_array($a,['dashboard','modem'],true)){
 $wdFile='C:/SMS-Awarie/data/watchdog-state.json';
 $wd=@json_decode((string)@file_get_contents($wdFile),true);
 if(is_array($wd)){
  $result['watchdog']=[
   'status'=>(string)($wd['status']??'UNKNOWN'),
   'checked'=>(string)($wd['checked']??''),
   'lastRestart'=>(string)($wd['lastRestart']??''),
   'lastEvent'=>(string)($wd['lastEvent']??''),
   'lastProblem'=>(string)($wd['lastProblem']??''),
   'pid'=>(int)($wd['pid']??0),
   'restartsLastHour'=>count(is_array($wd['restartHistory']??null)?$wd['restartHistory']:[]),
   'maxRestartsPerHour'=>3,
   'healthy'=>in_array((string)($wd['status']??''),['RUNNING','STARTING'],true)&&strtotime((string)($wd['checked']??''))>=time()-180
  ];
 }else{$result['watchdog']=['status'=>'NO_DATA','healthy'=>false];}
 $result['watchdog']['needsVerification']=rows($p,"SELECT id,phone,content,status,created,detail FROM outbox WHERE status IN ('W_TRAKCIE','NIEPEWNY') ORDER BY created DESC LIMIT 50");
 $result['watchdog']['needsVerificationCount']=(int)$p->query("SELECT COUNT(*) FROM outbox WHERE status IN ('W_TRAKCIE','NIEPEWNY')")->fetchColumn();
 $eventsFile='C:/SMS-Awarie/logs/watchdog-events.jsonl';$events=[];
 foreach(tailLines($eventsFile,100) as $line){$entry=json_decode($line,true);if(is_array($entry)&&isset($entry['event'],$entry['time']))$events[]=['time'=>(string)$entry['time'],'event'=>(string)$entry['event'],'detail'=>substr((string)($entry['detail']??''),0,500),'pid'=>(int)($entry['pid']??0)];}
 $result['watchdog']['events']=array_slice(array_reverse($events),0,20);
}

switch($a){case 'dashboard':$result['groupStats']=rows($p,'SELECT g.name,COUNT(tg.ticket_id) AS total FROM incident_groups g LEFT JOIN ticket_groups tg ON tg.group_id=g.id GROUP BY g.id ORDER BY g.name');$result['kpis']=[];foreach(['Nowe'=>"SELECT count(*) FROM tickets WHERE status='NOWE'",'Weryfikacja'=>"SELECT count(*) FROM tickets WHERE status='WERYFIKACJA'",'W realizacji'=>"SELECT count(*) FROM tickets WHERE status='W_REALIZACJI'",'SMS oczekujace'=>"SELECT count(*) FROM outbox WHERE status='OCZEKUJE'"] as $k=>$sql)$result['kpis'][$k]=(int)$p->query($sql)->fetchColumn();$result['rows']=rows($p,"SELECT t.id,t.plate,t.description,t.status,t.created,COALESCE(g.name,'Pojazdy') AS group_name FROM tickets t LEFT JOIN ticket_groups tg ON tg.ticket_id=t.id LEFT JOIN incident_groups g ON g.id=tg.group_id WHERE t.status IN ('NOWE','WERYFIKACJA') ORDER BY t.created DESC LIMIT 10");break;
case 'groups':
$result['rows']=rows($p,'SELECT id,name,prefix,active,legacy_vehicle,notify_sender,sender_template,recipient_template FROM incident_groups ORDER BY legacy_vehicle DESC,name');
$byGroup=[];foreach(rows($p,'SELECT group_id,phone FROM group_recipients WHERE active=1 ORDER BY phone') as $rr)$byGroup[$rr['group_id']][]=$rr['phone'];
foreach($result['rows'] as &$g){$g['recipients']=$byGroup[$g['id']]??[];}unset($g);break;
case 'tickets':
// v3.1.1: jedno zapytanie z JOIN zamiast 2 zapytan na wiersz; limit 1000 najnowszych.
$result['rows']=rows($p,"SELECT t.id,t.plate,t.description,t.status,t.created,COALESCE(g.name,'Pojazdy') AS group_name FROM tickets t LEFT JOIN ticket_groups tg ON tg.ticket_id=t.id LEFT JOIN incident_groups g ON g.id=tg.group_id ORDER BY t.created DESC LIMIT 1000");
foreach($result['rows'] as &$r){$r=['number'=>ticketNumberFrom($r['id'],$r['created'])]+$r;}unset($r);break;
case 'messages':case 'outbox':case 'vehicles':case 'users':$spec=['messages'=>['sms','id,sender,content,status,received','received',1000],'outbox'=>['outbox','id,phone,content,status,created,detail','created',1000],'vehicles'=>['vehicles','id,plate','plate',0],'users'=>['web_users','id,login,role','login',0]][$a];[$table,$cols,$sort,$limit]=$spec;$result['rows']=rows($p,"SELECT $cols FROM $table ORDER BY $sort DESC".($limit?" LIMIT $limit":''));break;
case 'settings':case 'modem':case 'database':$keys=['settings'=>['ack','own_phone','ack_template'],'modem'=>['modem'],'database'=>['server','database','user']][$a];$result['config']=array_intersect_key($c,array_flip($keys));break;
case 'logs':$result['rows']=rows($p,'SELECT * FROM web_audit ORDER BY id DESC LIMIT 500');$result['log']=implode("\n",array_merge(tailLines('C:/SMS-Awarie/logs/receiver-stderr.log',20),tailLines('C:/SMS-Awarie/logs/receiver-stdout.log',100)));break;default:fail('Nieznany modul',404);}
reply($result);
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();error_log($e->getMessage());fail('Operacja nie powiodla sie. Sprawdz log Apache.',500);}
