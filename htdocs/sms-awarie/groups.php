<?php
// Wersja schematu bazy. Podnies przy kazdej zmianie DDL; migracje uruchamiaja sie raz.
const SCHEMA_VERSION = 3;
function ensureSchema(PDO $p): void {
    if((int)$p->query('PRAGMA user_version')->fetchColumn()>=SCHEMA_VERSION) return;
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
    $p->exec('PRAGMA user_version='.SCHEMA_VERSION);
}
// SMS-Awarie v3.1: SQLite-only additive schema; no modifications to historical tables.
function groupsSchema(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS incident_groups (id TEXT PRIMARY KEY, name TEXT NOT NULL, prefix TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, legacy_vehicle INTEGER NOT NULL DEFAULT 0, notify_sender INTEGER NOT NULL DEFAULT 1, sender_template TEXT NOT NULL DEFAULT 'Przyjeto zgloszenie {number} dla {object}.', recipient_template TEXT NOT NULL DEFAULT 'Nowe zgloszenie {number}: {group} {object} {description}')");
    $p->exec("CREATE TABLE IF NOT EXISTS group_recipients (id TEXT PRIMARY KEY, group_id TEXT NOT NULL REFERENCES incident_groups(id), phone TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, UNIQUE(group_id,phone))");
    $p->exec("CREATE TABLE IF NOT EXISTS ticket_groups (ticket_id TEXT PRIMARY KEY REFERENCES tickets(id), group_id TEXT NOT NULL REFERENCES incident_groups(id), object_code TEXT NOT NULL)");
    $p->exec("CREATE TABLE IF NOT EXISTS notification_deliveries (id TEXT PRIMARY KEY, ticket_id TEXT NOT NULL REFERENCES tickets(id), recipient TEXT NOT NULL, kind TEXT NOT NULL, outbox_id TEXT NOT NULL UNIQUE REFERENCES outbox(id), UNIQUE(ticket_id,recipient,kind))");
    $c=config();
    $ack=!empty($c['ack'])?1:0;
    $template=trim((string)($c['ack_template']??''));
    if($template==='')$template='Przyjeto zgloszenie {number} dla {object}.';
    $template=str_replace('{plate}','{object}',$template);
    $q=$p->prepare("INSERT OR IGNORE INTO incident_groups(id,name,prefix,active,legacy_vehicle,notify_sender,sender_template) VALUES('vehicles','Pojazdy','POJ',1,1,?,?)");
    $q->execute([$ack,$template]);
    // Historical tickets are classified but no historical notification is generated.
    $p->exec("INSERT OR IGNORE INTO ticket_groups(ticket_id,group_id,object_code) SELECT id,'vehicles',plate FROM tickets");
}
function groupForMessage(PDO $p,string $message): array {
    $text=trim($message);
    if(preg_match('/^([A-Za-z][A-Za-z0-9_-]{1,15})(?:\s+|$)/u',$text,$m)) {
        $q=$p->prepare('SELECT * FROM incident_groups WHERE prefix=? AND active=1');
        $q->execute([strtoupper($m[1])]);$g=$q->fetch();$q->closeCursor();
        if($g) return [$g,trim(substr($text,strlen($m[1])))];
    }
    // Legacy vehicles: registration starts with letters/digits, contains a digit, followed by description.
    $q=$p->query("SELECT * FROM incident_groups WHERE legacy_vehicle=1 AND active=1 LIMIT 1");$g=$q->fetch();$q->closeCursor();
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
    $q=$p->prepare('SELECT phone FROM group_recipients WHERE group_id=? AND active=1 ORDER BY phone');$q->execute([$group['id']]);
    foreach($q->fetchAll() as $r)$recipients[]=['group',$r['phone'],$group['recipient_template']];$q->closeCursor();
    $seen=[];
    foreach($recipients as [$kind,$phone,$template]) {
        // One SMS per telephone even if the sender is also in the group recipient list.
        if(isset($seen[$phone]))continue;$seen[$phone]=true;
        $outboxId=hash('sha256',"notify-v1\0".$ticketId."\0".$phone);
        $deliveryId=hash('sha256',"delivery-v1\0".$ticketId."\0".$phone);
        $content=notificationText($template,$vars);
        $q=$p->prepare('SELECT id FROM notification_deliveries WHERE ticket_id=? AND recipient=?');$q->execute([$ticketId,$phone]);$exists=$q->fetchColumn();$q->closeCursor();
        if($exists)continue;
        queueSms($p,$outboxId,$phone,$content);
        $p->prepare('INSERT INTO notification_deliveries(id,ticket_id,recipient,kind,outbox_id) VALUES(?,?,?,?,?)')->execute([$deliveryId,$ticketId,$phone,$kind,$outboxId]);
    }
}
