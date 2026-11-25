<?php
require_once __DIR__.'/mail.php';
// Wersja schematu bazy. Podnies przy kazdej zmianie DDL; migracje uruchamiaja sie raz.
const SCHEMA_VERSION = 5;
function ensureSchema(PDO $p): void {
    if(schema_version($p)>=SCHEMA_VERSION) return;
    if(db_is_mssql($p)){
        // 4.2.0: MS SQL - od razu pelny schemat v3.
        mssqlSchema($p);
    }else{
        schema($p,'sqlite');
        $p->exec('CREATE TABLE IF NOT EXISTS web_users(id TEXT PRIMARY KEY, login TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL)');
        $p->exec('CREATE TABLE IF NOT EXISTS web_audit(id INTEGER PRIMARY KEY, created TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL)');
        groupsSchema($p);
        $p->exec('CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox(status,created)');
        $p->exec('CREATE INDEX IF NOT EXISTS idx_tickets_created ON tickets(created)');
        $p->exec('CREATE INDEX IF NOT EXISTS idx_sms_received ON sms(received)');
        $p->exec('CREATE INDEX IF NOT EXISTS idx_ticket_groups_group ON ticket_groups(group_id)');
        $p->exec('CREATE INDEX IF NOT EXISTS idx_tickets_plate ON tickets(plate)');
        // v3 (4.0.0): soft delete pojazdow i uzytkownikow, kolejny numer zgloszenia w roku.
        if(!hasColumn($p,'vehicles','active'))$p->exec('ALTER TABLE vehicles ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
        if(!hasColumn($p,'tickets','seq'))$p->exec('ALTER TABLE tickets ADD COLUMN seq INTEGER');
        if(!hasColumn($p,'web_users','active'))$p->exec('ALTER TABLE web_users ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    }
    accessSchema($p);
    notifySchema($p);
    groupsSeed($p);
    set_schema_version($p,SCHEMA_VERSION);
}
// v4 (4.5.0): uprawnienia uzytkownikow do grup zgloszen (obie bazy).
// web_users.groups_all: poziom dla wszystkich grup (view/handle/manage) albo '' = tylko grupy z user_group_access.
// Domyslnie 'manage' + wysylka + SMS nieprzypisane = dotychczasowe zachowanie istniejacych kont.
function accessSchema(PDO $p): void {
    $ms=db_is_mssql($p);
    $add=['groups_all'=>$ms?"NVARCHAR(16) NOT NULL DEFAULT 'manage'":"TEXT NOT NULL DEFAULT 'manage'",'can_send'=>'INT NOT NULL DEFAULT 1','see_unassigned'=>'INT NOT NULL DEFAULT 1'];
    foreach($add as $col=>$def) if(!hasColumn($p,'web_users',$col)) $p->exec('ALTER TABLE web_users ADD '.($ms?'':'COLUMN ').$col.' '.$def);
    if($ms) $p->exec("IF OBJECT_ID(N'dbo.user_group_access', N'U') IS NULL CREATE TABLE dbo.user_group_access (user_id NVARCHAR(64) NOT NULL REFERENCES web_users(id), group_id NVARCHAR(64) NOT NULL REFERENCES incident_groups(id), level NVARCHAR(16) NOT NULL, PRIMARY KEY(user_id,group_id))");
    else $p->exec('CREATE TABLE IF NOT EXISTS user_group_access (user_id TEXT NOT NULL REFERENCES web_users(id), group_id TEXT NOT NULL REFERENCES incident_groups(id), level TEXT NOT NULL, PRIMARY KEY(user_id,group_id))');
}
// v5 (4.6.0): e-mail i telefon uzytkownika, konta bez logowania (tylko powiadomienia), czlonkowie grup
// (powiadomienia SMS i e-mail) oraz kolejka e-maili. Dotychczasowe numery z group_recipients zostaja i dzialaja.
function notifySchema(PDO $p): void {
    $ms=db_is_mssql($p);
    $add=['email'=>$ms?"NVARCHAR(254) NOT NULL DEFAULT ''":"TEXT NOT NULL DEFAULT ''",'phone'=>$ms?"NVARCHAR(32) NOT NULL DEFAULT ''":"TEXT NOT NULL DEFAULT ''",'login_enabled'=>'INT NOT NULL DEFAULT 1'];
    foreach($add as $col=>$def) if(!hasColumn($p,'web_users',$col)) $p->exec('ALTER TABLE web_users ADD '.($ms?'':'COLUMN ').$col.' '.$def);
    if($ms){
        $p->exec("IF OBJECT_ID(N'dbo.group_members', N'U') IS NULL CREATE TABLE dbo.group_members (group_id NVARCHAR(64) NOT NULL REFERENCES incident_groups(id), user_id NVARCHAR(64) NOT NULL REFERENCES web_users(id), notify_sms INT NOT NULL DEFAULT 1, notify_email INT NOT NULL DEFAULT 1, PRIMARY KEY(group_id,user_id))");
        $p->exec("IF OBJECT_ID(N'dbo.mail_outbox', N'U') IS NULL CREATE TABLE dbo.mail_outbox (id NVARCHAR(64) NOT NULL PRIMARY KEY, ticket_id NVARCHAR(64) NULL, email NVARCHAR(254) NOT NULL, subject NVARCHAR(200) NOT NULL, body NVARCHAR(MAX) NOT NULL, status NVARCHAR(32) NOT NULL, created NVARCHAR(32) NOT NULL, sent NVARCHAR(32) NULL, attempts INT NOT NULL DEFAULT 0, next_try NVARCHAR(32) NOT NULL, detail NVARCHAR(MAX) NOT NULL)");
        $p->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='idx_mail_status') CREATE INDEX idx_mail_status ON mail_outbox(status,next_try)");
    }else{
        $p->exec('CREATE TABLE IF NOT EXISTS group_members (group_id TEXT NOT NULL REFERENCES incident_groups(id), user_id TEXT NOT NULL REFERENCES web_users(id), notify_sms INTEGER NOT NULL DEFAULT 1, notify_email INTEGER NOT NULL DEFAULT 1, PRIMARY KEY(group_id,user_id))');
        $p->exec('CREATE TABLE IF NOT EXISTS mail_outbox (id TEXT PRIMARY KEY, ticket_id TEXT, email TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL, status TEXT NOT NULL, created TEXT NOT NULL, sent TEXT, attempts INTEGER NOT NULL DEFAULT 0, next_try TEXT NOT NULL, detail TEXT NOT NULL)');
        $p->exec('CREATE INDEX IF NOT EXISTS idx_mail_status ON mail_outbox(status,next_try)');
    }
}
// Klucz porownania numerow: ostatnie 9 cyfr (600100200 = +48600100200 = 0048 600 100 200).
function phone_key(string $phone): string { $d=preg_replace('/\D/','',$phone)??''; return strlen($d)>9?substr($d,-9):$d; }
// Numer z formularza: bez spacji, myslnikow i nawiasow. Zwraca '' dla pustego.
function phone_clean(string $phone): string { return preg_replace('/[\s().-]/','',trim($phone))??''; }
// Usuwa z dodatkowych numerow grupy te, ktore naleza juz do czlonka grupy z powiadomieniem SMS (bez dublowania).
function group_dedupe_recipients(PDO $p, ?string $gid = null): int {
    $sql='SELECT r.id,r.phone,u.phone AS uphone FROM group_recipients r JOIN group_members m ON m.group_id=r.group_id AND m.notify_sms=1 JOIN web_users u ON u.id=m.user_id AND u.active=1 WHERE u.phone<>\'\''.($gid!==null?' AND r.group_id=?':'');
    $q=$p->prepare($sql);$q->execute($gid!==null?[$gid]:[]);$n=0;
    foreach($q->fetchAll() as $r) if(phone_key($r['phone'])===phone_key($r['uphone'])){ $p->prepare('DELETE FROM group_recipients WHERE id=?')->execute([$r['id']]); $n++; }
    return $n;
}

// SMS-Awarie v3.1: tabele grup (SQLite).
function groupsSchema(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS incident_groups (id TEXT PRIMARY KEY, name TEXT NOT NULL, prefix TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, legacy_vehicle INTEGER NOT NULL DEFAULT 0, notify_sender INTEGER NOT NULL DEFAULT 1, sender_template TEXT NOT NULL DEFAULT 'Przyjeto zgloszenie {number} dla {object}.', recipient_template TEXT NOT NULL DEFAULT 'Nowe zgloszenie {number}: {group} {object} {description}')");
    $p->exec("CREATE TABLE IF NOT EXISTS group_recipients (id TEXT PRIMARY KEY, group_id TEXT NOT NULL REFERENCES incident_groups(id), phone TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, UNIQUE(group_id,phone))");
    $p->exec("CREATE TABLE IF NOT EXISTS ticket_groups (ticket_id TEXT PRIMARY KEY REFERENCES tickets(id), group_id TEXT NOT NULL REFERENCES incident_groups(id), object_code TEXT NOT NULL)");
    $p->exec("CREATE TABLE IF NOT EXISTS notification_deliveries (id TEXT PRIMARY KEY, ticket_id TEXT NOT NULL REFERENCES tickets(id), recipient TEXT NOT NULL, kind TEXT NOT NULL, outbox_id TEXT NOT NULL UNIQUE REFERENCES outbox(id), UNIQUE(ticket_id,recipient,kind))");
}
// Grupa Pojazdy (z ustawien autoodpowiedzi) i przypisanie starszych zgloszen. Dziala w obu bazach.
function groupsSeed(PDO $p): void {
    $c=config();
    $ack=!empty($c['ack'])?1:0;
    $template=trim((string)($c['ack_template']??''));
    if($template==='')$template='Przyjeto zgloszenie {number} dla {object}.';
    $template=str_replace('{plate}','{object}',$template);
    if(!(int)$p->query("SELECT COUNT(*) FROM incident_groups WHERE id='vehicles'")->fetchColumn())
        $p->prepare("INSERT INTO incident_groups(id,name,prefix,active,legacy_vehicle,notify_sender,sender_template) VALUES('vehicles','Pojazdy','POJ',1,1,?,?)")->execute([$ack,$template]);
    // Historical tickets are classified but no historical notification is generated.
    $p->exec("INSERT INTO ticket_groups(ticket_id,group_id,object_code) SELECT t.id,'vehicles',t.plate FROM tickets t WHERE NOT EXISTS (SELECT 1 FROM ticket_groups x WHERE x.ticket_id=t.id)");
}
function groupForMessage(PDO $p,string $message): array {
    $text=trim($message);
    if(preg_match('/^([A-Za-z][A-Za-z0-9_-]{1,15})(?:\s+|$)/u',$text,$m)) {
        $q=$p->prepare('SELECT * FROM incident_groups WHERE prefix=? AND active=1');
        $q->execute([strtoupper($m[1])]);$g=$q->fetch();$q->closeCursor();
        if($g) return [$g,trim(substr($text,strlen($m[1])))];
    }
    // Legacy vehicles: registration starts with letters/digits, contains a digit, followed by description.
    $q=$p->query("SELECT * FROM incident_groups WHERE legacy_vehicle=1 AND active=1 ORDER BY id".sql_page($p,1));$g=$q->fetch();$q->closeCursor();
    if($g && preg_match('/^([A-Za-z0-9-]{4,12})\s+(.+)$/su',$text,$m) && preg_match('/\d/',$m[1])) return [$g,$text];
    return [null,$text];
}
function groupParsePayload(array $group,string $payload): ?array {
    if(!preg_match('/^([A-Za-z0-9_-]{1,32})\s+(.+)$/su',trim($payload),$m)) return null;
    $code= strtoupper($m[1]);
    if($group['legacy_vehicle']) {
        $code=plate($code);
        if(!preg_match('/^[A-Z0-9]{4,12}$/D',$code) || !preg_match('/\d/',$code))return null;
    }
    return [$code,trim($m[2])];
}
function notificationText(string $template,array $values): string {
    // v3.1.1: wartosci z SMS (opis, kod obiektu) i nazwa grupy sa transliterowane do ASCII.
    // Funkcja nie rzuca wyjatku - bledna tresc nie moze zatrzymac odbiornika.
    foreach($values as $k=>$v)$values[$k]=smsAscii((string)$v);
    $message=smsAscii(strtr($template,$values));
    if(strlen($message)>150 && str_contains($template,'{description}')) {
        $excess=strlen($message)-150;
        $values['{description}']=rtrim(substr($values['{description}'],0,max(0,strlen($values['{description}'])-$excess)));
        $message=smsAscii(strtr($template,$values));
    }
    if(strlen($message)>150)$message=rtrim(substr($message,0,150));
    if($message==='')$message=substr('Nowe zgloszenie '.($values['{number}']??''),0,150);
    return $message;
}
function enqueueGroupNotifications(PDO $p,array $group,string $ticketId,string $sender,string $code,string $description): void {
    $number=ticketNumber($p,$ticketId);
    $vars=['{number}'=>$number,'{group}'=>$group['name'],'{object}'=>$code,'{plate}'=>$code,'{description}'=>$description,'{status}'=>'NOWE'];
    $recipients=[];
    if((int)$group['notify_sender']===1 && preg_match('/^\+?[0-9]{9,15}$/D',$sender)) $recipients[]=['sender',$sender,$group['sender_template']];
    // 4.6.0: czlonkowie grupy (aktywne konta) - SMS na telefon z konta, e-mail na adres z konta.
    $members=rows_q($p,'SELECT u.phone,u.email,m.notify_sms,m.notify_email FROM group_members m JOIN web_users u ON u.id=m.user_id WHERE m.group_id=? AND u.active=1 ORDER BY u.login',[$group['id']]);
    foreach($members as $m) if((int)$m['notify_sms']===1 && preg_match('/^\+?[0-9]{9,15}$/D',(string)$m['phone'])) $recipients[]=['user',$m['phone'],$group['recipient_template']];
    // Dodatkowe numery spoza kont (z wersji 4.5 i starszych).
    foreach(rows_q($p,'SELECT phone FROM group_recipients WHERE group_id=? AND active=1 ORDER BY phone',[$group['id']]) as $r)$recipients[]=['group',$r['phone'],$group['recipient_template']];
    $seen=[];
    foreach($recipients as [$kind,$phone,$template]) {
        // One SMS per telephone even if the sender is also in the group recipient list.
        $key=phone_key($phone);
        if(isset($seen[$key]))continue;$seen[$key]=true;
        $outboxId=hash('sha256',"notify-v1\0".$ticketId."\0".$phone);
        $deliveryId=hash('sha256',"delivery-v1\0".$ticketId."\0".$phone);
        $content=notificationText($template,$vars);
        $q=$p->prepare('SELECT id FROM notification_deliveries WHERE ticket_id=? AND recipient=?');$q->execute([$ticketId,$phone]);$exists=$q->fetchColumn();$q->closeCursor();
        if($exists)continue;
        queueSms($p,$outboxId,$phone,$content);
        $p->prepare('INSERT INTO notification_deliveries(id,ticket_id,recipient,kind,outbox_id) VALUES(?,?,?,?,?)')->execute([$deliveryId,$ticketId,$phone,$kind,$outboxId]);
    }
    // E-mail tylko przy wlaczonej poczcie (Ustawienia > Poczta e-mail); wysyla odbiornik po SMS-ach.
    $smtp=smtp_settings();
    if(!smtp_ready($smtp))return;
    $status=(string)(rows_q($p,'SELECT status FROM tickets WHERE id=?',[$ticketId])[0]['status']??'NOWE');
    [$subject,$body]=ticketMail($smtp,$group,(string)$number,$code,$description,$sender,$status);
    $mailed=[];
    foreach($members as $m) {
        $email=strtolower(trim((string)$m['email']));
        if((int)$m['notify_email']!==1 || !valid_email($email) || isset($mailed[$email]))continue;
        $mailed[$email]=true;
        queueMail($p,hash('sha256',"mail-v1\0".$ticketId."\0".$email),$ticketId,$email,$subject,$body);
    }
}
// Zapytanie z parametrami (odbiornik nie laduje web/lib.php).
function rows_q(PDO $p,string $sql,array $args=[]): array { $q=$p->prepare($sql);$q->execute($args);$r=$q->fetchAll();$q->closeCursor();return $r; }
