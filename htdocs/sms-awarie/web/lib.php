<?php
// SMS-Awarie - pomocnicze funkcje panelu WWW.

const ROLES = ['admin' => 'Administrator', 'operator' => 'Operator', 'read-only' => 'Tylko odczyt'];
const TICKET_STATUSES = ['NOWE' => 'Nowe', 'WERYFIKACJA' => 'Weryfikacja', 'W_REALIZACJI' => 'W realizacji', 'ZAMKNIETE' => 'Zamknięte'];
// Czytelne nazwy pozostalych statusow (SMS, wysylki, odbiornik). W bazie zostaja kody.
const STATUS_LABELS = TICKET_STATUSES + ['OCZEKUJE' => 'Oczekuje', 'W_TRAKCIE' => 'W trakcie', 'PRZYJETY' => 'Przyjęty przez modem', 'WYSLANO' => 'Wysłano',
    'NIEPEWNY' => 'Niepewny', 'ANULOWANY' => 'Anulowany', 'NIEPOPRAWNY' => 'Niepoprawny', 'BLAD' => 'Błąd', 'ERROR' => 'Błąd',
    'OFFLINE' => 'Offline', 'MODEM_OFFLINE' => 'Brak połączenia z modemem', 'RUNNING' => 'Działa', 'UNKNOWN' => 'Nieznany'];
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
        $q = $p->prepare('SELECT id,login,role FROM web_users WHERE id=? AND active=1');
        $q->execute([$_SESSION['user']]);
        $u = $q->fetch() ?: null;
    }
    return $u;
}
function can_write(?array $u): bool { return $u && in_array($u['role'], ['admin', 'operator'], true); }
function is_admin(?array $u): bool { return $u && $u['role'] === 'admin'; }

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
        'WERYFIKACJA', 'W_TRAKCIE', 'NIEPEWNY', 'MODEM_OFFLINE' => 'b-amber',
        'W_REALIZACJI' => 'b-violet',
        'ZAMKNIETE', 'PRZYJETY', 'WYSLANO', 'RUNNING' => 'b-green',
        'NIEPOPRAWNY', 'BLAD', 'ERROR', 'OFFLINE', 'ANULOWANY' => 'b-red',
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

function heartbeat(): array {
    $hb = @json_decode((string)@file_get_contents(DATA_DIR.'/heartbeat.json'), true);
    $fresh = is_array($hb) && time() - (int)($hb['time'] ?? 0) < 90;
    return ['state' => $fresh ? (string)($hb['state'] ?? 'UNKNOWN') : 'OFFLINE', 'raw' => is_array($hb) ? $hb : []];
}

function asset(string $path): string {
    $file = APP_ROOT.'/'.$path;
    return h($path.'?v='.(is_file($file) ? filemtime($file) : 0));
}
