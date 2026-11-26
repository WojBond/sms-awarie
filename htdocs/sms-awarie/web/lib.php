<?php
// SMS-Awarie - pomocnicze funkcje panelu WWW.

const ROLES = ['admin' => 'Administrator', 'operator' => 'Operator', 'read-only' => 'Tylko odczyt'];
const TICKET_STATUSES = ['NOWE' => 'Nowe', 'WERYFIKACJA' => 'Weryfikacja', 'W_REALIZACJI' => 'W realizacji', 'ZAMKNIETE' => 'Zamknięte'];
// Czytelne nazwy pozostalych statusow (SMS, wysylki, odbiornik). W bazie zostaja kody.
const STATUS_LABELS = TICKET_STATUSES + ['OCZEKUJE' => 'Oczekuje', 'W_TRAKCIE' => 'W trakcie', 'PRZYJETY' => 'Przyjęty przez modem', 'WYSLANO' => 'Wysłano',
    'NIEPEWNY' => 'Niepewny', 'ANULOWANY' => 'Anulowany', 'NIEPOPRAWNY' => 'Niepoprawny', 'BLAD' => 'Błąd', 'ERROR' => 'Błąd',
    'OFFLINE' => 'Offline', 'MODEM_OFFLINE' => 'Brak połączenia z modemem', 'RUNNING' => 'Działa', 'UNKNOWN' => 'Nieznany',
    'STOPPED' => 'Zatrzymany', 'STOPPING' => 'Zatrzymuje się', 'PAUSED' => 'Wstrzymany', 'RECEIVER_STOPPED' => 'Wstrzymany (odbiornik zatrzymany)', 'STARTING' => 'Uruchamia się',
    'STALE' => 'Brak odpowiedzi', 'MULTIPLE' => 'Kilka odbiorników', 'PID_MISMATCH' => 'Niezgodny PID',
    'NO_DATA' => 'Brak danych – nie uruchomił się', 'UNREADABLE' => 'Nieczytelny plik stanu', 'SILENT' => 'Nie działa – brak kontroli'];
const PER_PAGE = 50;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function url(string $page, array $params = []): string {
    $params = ['p' => $page] + $params;
    return '?'.http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}
function redirect(string $page, array $params = []): never { header('Location: '.url($page, $params)); exit; }

function flash(?string $msg = null, string $type = 'ok'): array {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return []; }
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function check_csrf(): void {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Sesja wygasła. Odśwież stronę i spróbuj ponownie.');
    }
}

function current_user(PDO $p): ?array {
    if (empty($_SESSION['user'])) return null;
    static $u = false;
    if ($u === false) {
        $q = $p->prepare('SELECT id,login,role,groups_all,can_send,see_unassigned,email,phone FROM web_users WHERE id=? AND active=1 AND login_enabled=1');
        $q->execute([$_SESSION['user']]);
        $u = $q->fetch() ?: null;
    }
    return $u;
}
function can_write(?array $u): bool { return $u && in_array($u['role'], ['admin', 'operator'], true); }
function is_admin(?array $u): bool { return $u && $u['role'] === 'admin'; }

// 4.5.0: uprawnienia do grup zgloszen. Poziomy rosnaco; rola ogranicza najwyzszy poziom (Tylko odczyt = Podglad).
const GROUP_LEVELS = ['view' => 'Podgląd', 'handle' => 'Obsługa zgłoszeń', 'manage' => 'Obsługa i obiekty'];
const LEVEL_RANK = ['view' => 1, 'handle' => 2, 'manage' => 3];
const ROLE_MAX_LEVEL = ['admin' => 3, 'operator' => 3, 'read-only' => 1];
function level_cap(array $u, string $level): int { return min(LEVEL_RANK[$level] ?? 0, ROLE_MAX_LEVEL[$u['role']] ?? 0); }

// [id grupy => poziom 1..3] dla grup, do ktorych uzytkownik ma dostep (bez 'all' = tylko przypisane).
function group_levels(PDO $p, array $u): array {
    static $cache = [];
    if (isset($cache[$u['id']])) return $cache[$u['id']];
    $out = [];
    $all = is_admin($u) ? 3 : level_cap($u, (string)($u['groups_all'] ?? ''));
    if ($all > 0) foreach (rows($p, 'SELECT id FROM incident_groups') as $g) $out[$g['id']] = $all;
    else foreach (rows($p, 'SELECT group_id,level FROM user_group_access WHERE user_id=?', [$u['id']]) as $r) if ($l = level_cap($u, $r['level'])) $out[$r['group_id']] = $l;
    return $cache[$u['id']] = $out;
}
function group_level(PDO $p, array $u, ?string $gid): int { return $gid === null ? (all_groups_level($u) ?: 0) : (group_levels($p, $u)[$gid] ?? 0); }
function all_groups_level(array $u): int { return is_admin($u) ? 3 : level_cap($u, (string)($u['groups_all'] ?? '')); }
function can_handle(PDO $p, ?array $u, ?string $gid): bool { return can_write($u) && group_level($p, $u, $gid) >= 2; }
function can_send(?array $u): bool { return can_write($u) && (is_admin($u) || (int)($u['can_send'] ?? 0) === 1); }
function sees_unassigned(?array $u): bool { return $u && (is_admin($u) || (int)($u['see_unassigned'] ?? 0) === 1); }

// Warunek SQL ograniczajacy kolumne z id grupy do grup z poziomem >= $min. '' = bez ograniczen.
function group_filter(PDO $p, array $u, string $col, int $min = 1): array {
    if (all_groups_level($u) >= $min) return ['', []];
    $ids = array_keys(array_filter(group_levels($p, $u), fn($l) => $l >= $min));
    if (!$ids) return ['1=0', []];
    return ["$col IN (".implode(',', array_fill(0, count($ids), '?')).')', $ids];
}
// Zakres SMS odebranych ($kind='sms') i wychodzacych ('outbox') dla uzytkownika: wiadomosci zwiazane
// ze zgloszeniami dozwolonych grup oraz - gdy ma uprawnienie - nieprzypisane (niepoprawne SMS, wysylki reczne).
function scope_filter(PDO $p, array $u, string $kind, string $alias): array {
    [$gf, $args] = group_filter($p, $u, 'tg.group_id');
    $unassigned = sees_unassigned($u);
    if ($gf === '' && $unassigned) return ['', []];
    $link = $kind === 'sms'
        ? "SELECT 1 FROM tickets st LEFT JOIN ticket_groups tg ON tg.ticket_id=st.id WHERE st.sms_id=$alias.id"
        : "SELECT 1 FROM notification_deliveries nd LEFT JOIN ticket_groups tg ON tg.ticket_id=nd.ticket_id WHERE nd.outbox_id=$alias.id";
    $sql = "EXISTS($link".($gf !== '' ? " AND $gf" : '').')';
    if ($unassigned) $sql = "($sql OR NOT EXISTS($link))";
    return [$sql, $args];
}
// Lista wyboru poziomu dostepu do grupy; rola Tylko odczyt ma tylko Podglad.
function level_select(string $name, string $current, string $role): string {
    $max = ROLE_MAX_LEVEL[$role] ?? 0;
    if ((LEVEL_RANK[$current] ?? 0) > $max) $current = (string)array_search($max, LEVEL_RANK, true);
    $o = '<select name="'.h($name).'"><option value="">Brak dostępu</option>';
    foreach (GROUP_LEVELS as $k => $v) {
        if (LEVEL_RANK[$k] > (ROLE_MAX_LEVEL[$role] ?? 0)) continue;
        $o .= '<option value="'.h($k).'"'.($k === $current ? ' selected' : '').'>'.h($v).'</option>';
    }
    return $o.'</select>';
}
// Krotki opis zakresu konta (lista uzytkownikow, stopka menu).
function access_summary(array $r, int $assigned = 0): string {
    if ($r['role'] === 'admin') return 'pełny dostęp';
    $lvl = array_search(level_cap($r, (string)$r['groups_all']), LEVEL_RANK, true);
    return $lvl ? 'wszystkie grupy: '.GROUP_LEVELS[$lvl] : 'wybrane grupy: '.$assigned;
}

function audit(PDO $p, array $u, string $action, string $target = ''): void {
    $p->prepare('INSERT INTO web_audit(created,actor,action,target) VALUES(?,?,?,?)')->execute([date('Y-m-d H:i:s'), $u['login'], $action, $target]);
}

// 'Y-m-d H:i:s' lub ISO 8601 -> 'DD.MM.RRRR GG:MM'
function fmt_dt(?string $s): string {
    if (!$s) return '';
    $t = strtotime($s);
    return $t ? date('d.m.Y H:i', $t) : $s;
}
function fmt_bytes(int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ').' MB' : number_format($b / 1024, 0, ',', ' ').' kB';
}

function badge(string $status): string {
    $cls = match ($status) {
        'NOWE', 'OCZEKUJE' => 'b-blue',
        'WERYFIKACJA', 'W_TRAKCIE', 'NIEPEWNY', 'MODEM_OFFLINE', 'STOPPED', 'STOPPING', 'PAUSED', 'RECEIVER_STOPPED', 'STALE' => 'b-amber',
        'STARTING' => 'b-blue',
        'W_REALIZACJI' => 'b-violet',
        'ZAMKNIETE', 'PRZYJETY', 'WYSLANO', 'RUNNING' => 'b-green',
        'NIEPOPRAWNY', 'BLAD', 'ERROR', 'OFFLINE', 'ANULOWANY', 'MULTIPLE', 'PID_MISMATCH', 'NO_DATA', 'UNREADABLE', 'SILENT' => 'b-red',
        default => 'b-grey',
    };
    $label = STATUS_LABELS[$status] ?? $status;
    return '<span class="badge '.$cls.'">'.h($label).'</span>';
}

function rows(PDO $p, string $sql, array $args = []): array { $q = $p->prepare($sql); $q->execute($args); $r = $q->fetchAll(); $q->closeCursor(); return $r; }
function one(PDO $p, string $sql, array $args = []) { $q = $p->prepare($sql); $q->execute($args); $r = $q->fetchColumn(); $q->closeCursor(); return $r; }

function page_no(): int { return max(1, (int)($_GET['n'] ?? 1)); }
function pager(int $total, string $page, array $params): string {
    $pages = max(1, (int)ceil($total / PER_PAGE));
    $n = min(page_no(), $pages);
    $out = '<nav class="pager"><span>'.number_format($total, 0, ',', ' ').' rekordów</span>';
    if ($n > 1) $out .= '<a class="btn btn-o" href="'.h(url($page, $params + ['n' => $n - 1])).'">‹ Poprzednia</a>';
    $out .= '<span>'.$n.' / '.$pages.'</span>';
    if ($n < $pages) $out .= '<a class="btn btn-o" href="'.h(url($page, $params + ['n' => $n + 1])).'">Następna ›</a>';
    return $out.'</nav>';
}

function csv_out(string $filename, array $header, iterable $rows): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $header, ';');
    foreach ($rows as $r) fputcsv($f, $r, ';');
    exit;
}

// Plik JSON jako tablica albo null. Usuwa BOM UTF-8 - Windows PowerShell 5.1 (Set-Content/Add-Content
// -Encoding UTF8) dopisuje go na poczatku pliku, a json_decode() wtedy zwraca null.
function strip_bom(string $s): string { return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s; }
function read_json(string $file): ?array {
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $d = json_decode(strip_bom($raw), true);
    return is_array($d) ? $d : null;
}

// Stan Watchdoga do wyswietlenia: ['status', 'checked', 'raw'].
// NO_DATA - brak watchdog-state.json (zadanie nigdy nie zadzialalo), UNREADABLE - plik nieczytelny,
// SILENT - ostatnia kontrola starsza niz 5 min (zadanie w Harmonogramie nie dziala), inaczej status z pliku.
const WATCHDOG_SILENT_SECONDS = 300;
function watchdog_state(): array {
    $f = DATA_DIR.'/watchdog-state.json';
    clearstatcache(true, $f);
    if (!is_file($f)) return ['status' => 'NO_DATA', 'checked' => '', 'raw' => []];
    $wd = read_json($f);
    if ($wd === null) return ['status' => 'UNREADABLE', 'checked' => date('c', (int)filemtime($f)), 'raw' => []];
    $checked = (string)($wd['checked'] ?? '');
    $t = $checked !== '' ? strtotime($checked) : false;
    if (!$t) $t = (int)filemtime($f);
    $status = (string)($wd['status'] ?? '');
    if ($status === '') $status = 'UNKNOWN';
    if (time() - $t > WATCHDOG_SILENT_SECONDS) $status = 'SILENT';
    return ['status' => $status, 'checked' => $checked !== '' ? $checked : date('c', $t), 'raw' => $wd];
}

function heartbeat(): array {
    $hb = read_json(DATA_DIR.'/heartbeat.json');
    $fresh = is_array($hb) && time() - (int)($hb['time'] ?? 0) < 90;
    return ['state' => $fresh ? (string)($hb['state'] ?? 'UNKNOWN') : 'OFFLINE', 'raw' => is_array($hb) ? $hb : []];
}

function asset(string $path): string {
    $file = APP_ROOT.'/'.$path;
    return h($path.'?v='.(is_file($file) ? filemtime($file) : 0));
}
