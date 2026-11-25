<?php
// SMS-Awarie 4.2 - polaczenie z baza: SQLite (domyslnie, data\awarie.sqlite) albo Microsoft SQL Server.
// Ustawienia w data\config.json, klucz "db":
//   {"driver":"sqlite"}
//   {"driver":"sqlsrv","server":"localhost\\SQLEXPRESS","database":"SMSAwarie","user":"smsawarie","pass":"...","encrypt":true,"trust_cert":true}
// Pusty "user" = uwierzytelnianie Windows (konto, na ktorym dziala Apache / odbiornik).
// MS SQL wymaga rozszerzenia pdo_sqlsrv (Microsoft Drivers for PHP) i ODBC Driver 18 - patrz docs\MSSQL.md.
// "pdo":"odbc" laczy przez pdo_odbc zamiast pdo_sqlsrv - tylko do testow (na Windows psuje polskie znaki).

const DB_DEFAULTS = ['driver' => 'sqlite', 'server' => '', 'database' => 'SMSAwarie', 'user' => '', 'pass' => '', 'encrypt' => true, 'trust_cert' => true, 'backup_dir' => '', 'pdo' => 'sqlsrv', 'odbc_driver' => 'ODBC Driver 18 for SQL Server'];

function db_settings(?array $c = null): array {
    $c ??= config();
    $s = array_merge(DB_DEFAULTS, is_array($c['db'] ?? null) ? $c['db'] : []);
    if ($s['driver'] !== 'sqlsrv') $s['driver'] = 'sqlite';
    return $s;
}

// Zmiana tej wartosci oznacza, ze odbiornik ma polaczyc sie z inna baza.
function db_signature(array $s): string {
    if ($s['driver'] === 'sqlite') return 'sqlite';
    return hash('sha256', json_encode([$s['pdo'], $s['server'], $s['database'], $s['user'], $s['pass'], $s['encrypt'], $s['trust_cert']]));
}

function db_label(array $s): string {
    return $s['driver'] === 'sqlite' ? 'SQLite (data\\awarie.sqlite)' : 'MS SQL: '.$s['server'].' / '.$s['database'];
}

// 4.3.1: db('sqlite', $cfg) z wersji 4.0-4.1 nadal dziala (np. stary receiver.php po niepelnej aktualizacji),
// ale ustawienia bazy zawsze pochodza z config.json, tak jak w nowym kodzie.
function db(array|string|null $s = null, ?array $c = null): PDO {
    if (!is_array($s)) $s = db_settings($c);
    return db_connect($s);
}

function db_connect(array $s, bool $withDatabase = true): PDO {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($s['driver'] === 'sqlite') {
        $p = new PDO('sqlite:'.DATA_DIR.'/awarie.sqlite', null, null, $opts);
        $p->exec('PRAGMA busy_timeout=10000');
        $p->exec('PRAGMA journal_mode=WAL');
        return $p;
    }
    if (trim((string)$s['server']) === '') throw new RuntimeException('Nie podano serwera MS SQL.');
    $user = trim((string)$s['user']) !== '' ? (string)$s['user'] : null;
    $pass = $user !== null ? (string)$s['pass'] : null;
    $db = $withDatabase ? (string)$s['database'] : 'master';
    if ($s['pdo'] === 'odbc') {
        $dsn = 'odbc:Driver={'.$s['odbc_driver'].'};Server='.$s['server'].';Database='.$db.';Encrypt='.($s['encrypt'] ? 'yes' : 'no')
            .';TrustServerCertificate='.($s['trust_cert'] ? 'yes' : 'no').';MARS_Connection=yes;LoginTimeout=5'.($user === null ? ';Trusted_Connection=yes' : '');
    } else {
        if (!extension_loaded('pdo_sqlsrv')) throw new RuntimeException('Brak rozszerzenia PHP pdo_sqlsrv - zobacz docs\\MSSQL.md.');
        $dsn = 'sqlsrv:Server='.$s['server'].';Database='.$db.';Encrypt='.($s['encrypt'] ? '1' : '0').';TrustServerCertificate='.($s['trust_cert'] ? '1' : '0').';LoginTimeout=5;APP=SMS-Awarie';
    }
    $p = new PDO($dsn, $user, $pass, $opts);
    $p->exec('SET DATEFORMAT ymd; SET LOCK_TIMEOUT 10000');
    return $p;
}

function db_is_mssql(PDO $p): bool { return $p->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'; }

// Klauzula stronicowania. Zapytanie musi miec ORDER BY (wymaga tego MS SQL).
function sql_page(PDO $p, int $limit, int $offset = 0): string {
    $limit = max(1, $limit); $offset = max(0, $offset);
    return db_is_mssql($p) ? " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY" : " LIMIT $limit OFFSET $offset";
}

function schema_version(PDO $p): int {
    if (!db_is_mssql($p)) return (int)$p->query('PRAGMA user_version')->fetchColumn();
    if (!db_table_exists($p, 'app_meta')) return 0;
    return (int)$p->query("SELECT value FROM app_meta WHERE name='schema_version'")->fetchColumn();
}
function set_schema_version(PDO $p, int $v): void {
    if (!db_is_mssql($p)) { $p->exec('PRAGMA user_version='.$v); return; }
    $q = $p->prepare("UPDATE app_meta SET value=? WHERE name='schema_version'");
    $q->execute([(string)$v]);
    if (!$q->rowCount()) $p->prepare("INSERT INTO app_meta(name,value) VALUES('schema_version',?)")->execute([(string)$v]);
}

function db_table_exists(PDO $p, string $table): bool {
    if (db_is_mssql($p)) { $q = $p->prepare('SELECT OBJECT_ID(?, \'U\')'); $q->execute(['dbo.'.$table]); return $q->fetchColumn() !== null; }
    $q = $p->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?"); $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function hasColumn(PDO $p, string $table, string $col): bool {
    if (db_is_mssql($p)) { $q = $p->prepare('SELECT COL_LENGTH(?,?)'); $q->execute(['dbo.'.$table, $col]); return $q->fetchColumn() !== null; }
    foreach ($p->query("PRAGMA table_info($table)")->fetchAll() as $c) if ($c['name'] === $col) return true;
    return false;
}

// INSERT, ktory nie zglasza bledu przy duplikacie klucza (odpowiednik INSERT OR IGNORE w obu bazach).
function insert_ignore(PDO $p, string $sql, array $args): bool {
    try { $p->prepare($sql)->execute($args); return true; }
    catch (PDOException $e) { if (($e->errorInfo[0] ?? $e->getCode()) === '23000') return false; throw $e; }
}

// Pelny schemat v3 dla MS SQL (nowa baza). Kolejne wersje: migracje w ensureSchema().
function mssqlSchema(PDO $p): void {
    $t = [
        'app_meta' => 'name NVARCHAR(64) NOT NULL PRIMARY KEY, value NVARCHAR(255) NOT NULL',
        'outbox' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, phone NVARCHAR(32) NOT NULL, content NVARCHAR(MAX) NOT NULL, status NVARCHAR(32) NOT NULL, created NVARCHAR(32) NOT NULL, detail NVARCHAR(MAX) NOT NULL',
        'vehicles' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, plate NVARCHAR(20) NOT NULL UNIQUE, active INT NOT NULL DEFAULT 1',
        'sms' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, modem_index NVARCHAR(32) NOT NULL, sender NVARCHAR(100) NOT NULL, content NVARCHAR(MAX) NOT NULL, received NVARCHAR(32) NOT NULL, status NVARCHAR(32) NOT NULL',
        'tickets' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, sms_id NVARCHAR(64) NOT NULL UNIQUE, plate NVARCHAR(32) NOT NULL, description NVARCHAR(MAX) NOT NULL, status NVARCHAR(32) NOT NULL, created NVARCHAR(32) NOT NULL, seq INT NULL',
        'web_users' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, login NVARCHAR(64) NOT NULL UNIQUE, password_hash NVARCHAR(255) NOT NULL, role NVARCHAR(32) NOT NULL, active INT NOT NULL DEFAULT 1',
        'web_audit' => 'id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY, created NVARCHAR(32) NOT NULL, actor NVARCHAR(64) NOT NULL, action NVARCHAR(64) NOT NULL, target NVARCHAR(MAX) NOT NULL',
        'incident_groups' => "id NVARCHAR(64) NOT NULL PRIMARY KEY, name NVARCHAR(100) NOT NULL, prefix NVARCHAR(16) NOT NULL UNIQUE, active INT NOT NULL DEFAULT 1, legacy_vehicle INT NOT NULL DEFAULT 0, notify_sender INT NOT NULL DEFAULT 1, sender_template NVARCHAR(400) NOT NULL DEFAULT 'Przyjeto zgloszenie {number} dla {object}.', recipient_template NVARCHAR(400) NOT NULL DEFAULT 'Nowe zgloszenie {number}: {group} {object} {description}'",
        'group_recipients' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, group_id NVARCHAR(64) NOT NULL REFERENCES incident_groups(id), phone NVARCHAR(32) NOT NULL, active INT NOT NULL DEFAULT 1, UNIQUE(group_id,phone)',
        'ticket_groups' => 'ticket_id NVARCHAR(64) NOT NULL PRIMARY KEY REFERENCES tickets(id), group_id NVARCHAR(64) NOT NULL REFERENCES incident_groups(id), object_code NVARCHAR(32) NOT NULL',
        'notification_deliveries' => 'id NVARCHAR(64) NOT NULL PRIMARY KEY, ticket_id NVARCHAR(64) NOT NULL REFERENCES tickets(id), recipient NVARCHAR(32) NOT NULL, kind NVARCHAR(16) NOT NULL, outbox_id NVARCHAR(64) NOT NULL UNIQUE REFERENCES outbox(id), UNIQUE(ticket_id,recipient,kind)',
    ];
    foreach ($t as $name => $cols) $p->exec("IF OBJECT_ID(N'dbo.$name', N'U') IS NULL CREATE TABLE dbo.$name ($cols)");
    $idx = ['idx_outbox_status' => 'outbox(status,created)', 'idx_tickets_created' => 'tickets(created)', 'idx_sms_received' => 'sms(received)', 'idx_ticket_groups_group' => 'ticket_groups(group_id)', 'idx_tickets_plate' => 'tickets(plate)'];
    foreach ($idx as $name => $on) $p->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='$name') CREATE INDEX $name ON $on");
}

// Tabele w kolejnosci kluczy obcych (kopiowanie danych).
const DB_TABLES = [
    'web_users' => ['id', 'login', 'password_hash', 'role', 'active', 'groups_all', 'can_send', 'see_unassigned', 'email', 'phone', 'login_enabled'],
    'web_audit' => ['created', 'actor', 'action', 'target'],
    'vehicles' => ['id', 'plate', 'active'],
    'incident_groups' => ['id', 'name', 'prefix', 'active', 'legacy_vehicle', 'notify_sender', 'sender_template', 'recipient_template'],
    'group_recipients' => ['id', 'group_id', 'phone', 'active'],
    'user_group_access' => ['user_id', 'group_id', 'level'],
    'group_members' => ['group_id', 'user_id', 'notify_sms', 'notify_email'],
    'outbox' => ['id', 'phone', 'content', 'status', 'created', 'detail'],
    'sms' => ['id', 'modem_index', 'sender', 'content', 'received', 'status'],
    'tickets' => ['id', 'sms_id', 'plate', 'description', 'status', 'created', 'seq'],
    'ticket_groups' => ['ticket_id', 'group_id', 'object_code'],
    'notification_deliveries' => ['id', 'ticket_id', 'recipient', 'kind', 'outbox_id'],
    'mail_outbox' => ['id', 'ticket_id', 'email', 'subject', 'body', 'status', 'created', 'sent', 'attempts', 'next_try', 'detail'],
];
const DB_ORDER = ['web_users' => 'id', 'web_audit' => 'id', 'vehicles' => 'id', 'incident_groups' => 'id', 'group_recipients' => 'id', 'user_group_access' => 'user_id,group_id', 'group_members' => 'group_id,user_id', 'outbox' => 'id', 'sms' => 'id', 'tickets' => 'id', 'ticket_groups' => 'ticket_id', 'notification_deliveries' => 'id', 'mail_outbox' => 'id'];

// Liczba wierszy w tabelach aplikacji; null = tabela nie istnieje.
function db_counts(PDO $p): array {
    $out = [];
    foreach (array_keys(DB_TABLES) as $t) $out[$t] = db_table_exists($p, $t) ? (int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn() : null;
    return $out;
}
// Baza docelowa jest "pusta", gdy nie ma w niej zgloszen, SMS, kolejki ani kont.
function db_has_data(array $counts): bool {
    foreach (['web_users', 'tickets', 'sms', 'outbox'] as $t) if (($counts[$t] ?? 0) > 0) return true;
    return false;
}

// Kopiuje dane z $src do $dst (schemat $dst musi istniec). $tables = lista tabel albo null = wszystkie.
// Calosc w jednej transakcji w bazie docelowej; po skopiowaniu porownuje liczby wierszy. Zwraca [tabela => wiersze].
function db_copy(PDO $src, PDO $dst, ?array $tables = null): array {
    $tables ??= array_keys(DB_TABLES);
    $done = [];
    $dst->beginTransaction();
    try {
        foreach (array_reverse(array_keys(DB_TABLES)) as $t) $dst->exec("DELETE FROM $t");
        foreach (DB_TABLES as $t => $cols) {
            if (!in_array($t, $tables, true)) continue;
            $list = implode(',', $cols);
            $ins = $dst->prepare("INSERT INTO $t($list) VALUES(".implode(',', array_fill(0, count($cols), '?')).')');
            $q = $src->query("SELECT $list FROM $t ORDER BY ".DB_ORDER[$t]);
            $n = 0;
            while ($r = $q->fetch(PDO::FETCH_NUM)) { $ins->execute($r); $n++; }
            $q->closeCursor();
            $check = (int)$dst->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            if ($check !== $n) throw new RuntimeException("Tabela $t: skopiowano $n, w bazie docelowej $check.");
            $done[$t] = $n;
        }
        $dst->commit();
    } catch (Throwable $e) {
        if ($dst->inTransaction()) $dst->rollBack();
        throw $e;
    }
    return $done;
}

// Utworzenie bazy na serwerze MS SQL (wymaga uprawnienia dbcreator).
function mssql_create_database(array $s): bool {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', (string)$s['database'])) throw new RuntimeException('Nazwa bazy: litery, cyfry i _ (zaczyna sie litera).');
    $m = db_connect($s, false);
    $q = $m->prepare('SELECT DB_ID(?)'); $q->execute([$s['database']]);
    if ($q->fetchColumn() !== null) return false;
    $m->exec('CREATE DATABASE ['.$s['database'].'] COLLATE Polish_100_CI_AS');
    return true;
}

// Wstrzymanie odbiornika na czas przelaczania bazy (odbiornik sprawdza plik na poczatku kazdego cyklu).
const MAINTENANCE_FLAG = DATA_DIR.'/maintenance.flag';
function receiver_pause(int $timeout = 60): bool {
    file_put_contents(MAINTENANCE_FLAG, date('c'));
    $start = time();
    while (time() - $start < $timeout) {
        clearstatcache();
        $hb = @json_decode((string)@file_get_contents(DATA_DIR.'/heartbeat.json'), true);
        if (!is_array($hb) || time() - (int)($hb['time'] ?? 0) > 90) return true;      // odbiornik nie dziala
        if (($hb['phase'] ?? '') === 'paused' && (int)$hb['time'] >= $start) return true;
        if (($hb['phase'] ?? '') === 'error') return true;                                   // odbiornik czeka po bledzie, nic nie przetwarza
        sleep(1);
    }
    @unlink(MAINTENANCE_FLAG);
    return false;
}
function receiver_resume(): void { @unlink(MAINTENANCE_FLAG); }

// Kopia MS SQL: BACKUP DATABASE ... WITH COPY_ONLY (nie psuje lancucha kopii DBA) + RESTORE VERIFYONLY.
// Plik powstaje na serwerze SQL: w backup_dir z ustawien albo w domyslnym katalogu kopii instancji.
function mssql_backup_dir(PDO $p, array $s): string {
    $dir = trim((string)$s['backup_dir']);
    if ($dir === '') $dir = (string)$p->query("SELECT CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400))")->fetchColumn();
    if ($dir === '') throw new RuntimeException('Nie znam katalogu kopii serwera SQL - ustaw go w Ustawienia > Baza danych.');
    return rtrim($dir, '\\/');
}
function mssql_backup(PDO $p, array $s, string $prefix): string {
    $dir = mssql_backup_dir($p, $s);
    $sep = (str_contains($dir, '/') && !str_contains($dir, '\\')) ? '/' : '\\';
    $file = $dir.$sep.$prefix.'-'.$s['database'].'-'.date('Ymd-His').'.bak';
    $lit = "N'".str_replace("'", "''", $file)."'";
    foreach (['BACKUP DATABASE ['.$s['database']."] TO DISK = $lit WITH COPY_ONLY, INIT, CHECKSUM", "RESTORE VERIFYONLY FROM DISK = $lit WITH CHECKSUM"] as $sql) {
        $st = $p->query($sql);
        try { while ($st->nextRowset()) {} } catch (PDOException $e) { if (($e->errorInfo[0] ?? '') !== 'IM001') throw $e; }
        $st->closeCursor();
    }
    return $file;
}
// Usuwa stare kopie, jesli katalog kopii serwera SQL jest widoczny z tego komputera. Zwraca liczbe usunietych.
function mssql_backup_cleanup(string $dir, string $prefix, int $keepDays): int {
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (glob($dir.'/'.$prefix.'-*.bak') ?: [] as $f) if (filemtime($f) < time() - $keepDays * 86400 && @unlink($f)) $n++;
    return $n;
}
