<?php
// SMS-Awarie 4.6 - wysylka e-mail przez SMTP (bez bibliotek zewnetrznych) i kolejka mail_outbox.
// Ustawienia w data\config.json, klucz "smtp" (Ustawienia > Poczta e-mail):
//   {"enabled":true,"host":"smtp.firma.pl","port":587,"security":"tls","user":"awarie@firma.pl","pass":"...",
//    "from_email":"awarie@firma.pl","from_name":"SMS-Awarie","verify_cert":true,"panel_url":"http://serwer/sms-awarie/"}
// security: "ssl" = SSL/TLS od poczatku (zwykle port 465), "tls" = STARTTLS (587), "none" = bez szyfrowania (25).
// Wymaga rozszerzenia PHP openssl (w XAMPP wlaczone domyslnie) dla "ssl" i "tls".

const SMTP_DEFAULTS = ['enabled' => false, 'host' => '', 'port' => 587, 'security' => 'tls', 'user' => '', 'pass' => '',
    'from_email' => '', 'from_name' => 'SMS-Awarie', 'verify_cert' => true, 'panel_url' => '', 'timeout' => 15];
const SMTP_SECURITY = ['tls' => 'STARTTLS (zwykle port 587)', 'ssl' => 'SSL/TLS (zwykle port 465)', 'none' => 'Bez szyfrowania (zwykle port 25)'];
// Ponowienia nieudanej wysylki: po 1, 5, 15 i 60 minutach, potem status BLAD.
const MAIL_RETRY_MINUTES = [1, 5, 15, 60];

function smtp_settings(?array $c = null): array {
    $c ??= config();
    $s = array_merge(SMTP_DEFAULTS, is_array($c['smtp'] ?? null) ? $c['smtp'] : []);
    if (!isset(SMTP_SECURITY[$s['security']])) $s['security'] = 'tls';
    $s['port'] = (int)$s['port'] ?: 587;
    return $s;
}
// Wysylka wlaczona i ustawienia kompletne.
function smtp_ready(array $s): bool { return !empty($s['enabled']) && trim((string)$s['host']) !== '' && valid_email((string)$s['from_email']); }

function valid_email(string $e): bool { return strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

class SmtpError extends Exception {}

// Wysyla jedna wiadomosc tekstowa (UTF-8). Rzuca SmtpError z odpowiedzia serwera przy bledzie.
// $log (opcjonalnie) dostaje przebieg rozmowy z serwerem bez hasla - do testu w panelu.
function smtp_send(array $s, string $to, string $subject, string $body, ?array &$log = null): void {
    $log = [];
    $host = trim((string)$s['host']);
    if ($host === '') throw new SmtpError('Nie podano serwera SMTP.');
    if (!valid_email($to)) throw new SmtpError('Niepoprawny adres odbiorcy: '.$to);
    if (!valid_email((string)$s['from_email'])) throw new SmtpError('Niepoprawny adres nadawcy.');
    if ($s['security'] !== 'none' && !extension_loaded('openssl')) throw new SmtpError('Brak rozszerzenia PHP openssl (php.ini: extension=openssl).');
    $timeout = max(5, (int)$s['timeout']);
    $ssl = ['verify_peer' => !empty($s['verify_cert']), 'verify_peer_name' => !empty($s['verify_cert']), 'allow_self_signed' => empty($s['verify_cert']), 'SNI_enabled' => true, 'peer_name' => $host];
    $ctx = stream_context_create(['ssl' => $ssl]);
    $remote = ($s['security'] === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.(int)$s['port'];
    $f = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$f) {
        $why = $errstr !== '' ? $errstr : ($s['security'] === 'ssl' ? 'nie udało się nawiązać połączenia SSL (sprawdź port, rodzaj szyfrowania i certyfikat)' : 'błąd '.$errno);
        throw new SmtpError('Brak połączenia z '.$host.':'.(int)$s['port'].' – '.$why.'.');
    }
    stream_set_timeout($f, $timeout);
    try {
        $read = function () use ($f, &$log): array {
            $lines = [];
            while (($l = fgets($f, 4096)) !== false) {
                $lines[] = rtrim($l, "\r\n");
                if (strlen($l) < 4 || $l[3] !== '-') break;
            }
            if (!$lines) { $m = stream_get_meta_data($f); throw new SmtpError($m['timed_out'] ? 'Serwer SMTP nie odpowiada (limit czasu).' : 'Serwer SMTP zamknął połączenie.'); }
            foreach ($lines as $l) $log[] = 'S: '.$l;
            return [(int)substr($lines[0], 0, 3), $lines];
        };
        $cmd = function (string $line, array $ok, ?string $shown = null) use ($f, $read, &$log): array {
            $log[] = 'C: '.($shown ?? $line);
            if (@fwrite($f, $line."\r\n") === false) throw new SmtpError('Błąd zapisu do serwera SMTP.');
            [$code, $lines] = $read();
            if (!in_array($code, $ok, true)) throw new SmtpError('Serwer SMTP odrzucił '.(str_starts_with((string)$shown, '(') || str_starts_with($line, 'AUTH') ? 'logowanie (login lub hasło)' : strtok($line, ' :')).': '.implode(' ', array_map(fn($l) => substr($l, 4), $lines)));
            return $lines;
        };
        [$code, $lines] = $read();
        if ($code !== 220) throw new SmtpError('Nieoczekiwane powitanie serwera: '.implode(' ', $lines));
        $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string)gethostname()) ?: 'localhost';
        $ehlo = $cmd('EHLO '.$me, [250]);
        if ($s['security'] === 'tls') {
            $cmd('STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (@stream_socket_enable_crypto($f, true, $crypto) !== true) {
                $e = error_get_last();
                throw new SmtpError('Nie udało się włączyć szyfrowania STARTTLS'.(!empty($s['verify_cert']) ? ' (sprawdź certyfikat serwera albo wyłącz jego weryfikację)' : '').'. '.($e['message'] ?? ''));
            }
            $ehlo = $cmd('EHLO '.$me, [250]);
        }
        $user = (string)$s['user'];
        if ($user !== '') {
            $caps = strtoupper(implode(' ', $ehlo));
            if (preg_match('/AUTH[ =][^\n]*\bLOGIN\b/', $caps) || !preg_match('/AUTH[ =][^\n]*\bPLAIN\b/', $caps)) {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334], '(login)');
                $cmd(base64_encode((string)$s['pass']), [235], '(hasło)');
            } else {
                $cmd('AUTH PLAIN '.base64_encode("\0".$user."\0".(string)$s['pass']), [235], 'AUTH PLAIN (login i hasło)');
            }
        }
        $cmd('MAIL FROM:<'.$s['from_email'].'>', [250]);
        $cmd('RCPT TO:<'.$to.'>', [250, 251]);
        $cmd('DATA', [354]);
        $msg = mail_build($s, $to, $subject, $body);
        $log[] = 'C: (treść wiadomości, '.strlen($msg).' B)';
        // Kropka na poczatku linii musi byc podwojona (RFC 5321).
        $msg = preg_replace('/^\./m', '..', $msg);
        if (@fwrite($f, $msg."\r\n.\r\n") === false) throw new SmtpError('Błąd zapisu treści wiadomości.');
        [$code, $lines] = $read();
        if ($code !== 250) throw new SmtpError('Serwer SMTP nie przyjął wiadomości: '.implode(' ', array_map(fn($l) => substr($l, 4), $lines)));
        try { $cmd('QUIT', [221]); } catch (SmtpError $ignored) {}
    } finally {
        fclose($f);
    }
}

function mail_header_encode(string $s): string {
    $s = str_replace(["\r", "\n"], ' ', $s);
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?'.base64_encode($s).'?=' : $s;
}
function mail_build(array $s, string $to, string $subject, string $body): string {
    $fromName = trim((string)$s['from_name']);
    $domain = substr(strrchr((string)$s['from_email'], '@'), 1) ?: 'localhost';
    $h = [
        'Date: '.date('r'),
        'From: '.($fromName !== '' ? mail_header_encode($fromName).' ' : '').'<'.$s['from_email'].'>',
        'To: <'.$to.'>',
        'Subject: '.mail_header_encode($subject),
        'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$domain.'>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
        'X-Mailer: SMS-Awarie '.APP_VERSION,
    ];
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    return implode("\r\n", $h)."\r\n\r\n".rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
}

// Dodaje e-mail do kolejki (raz na zgloszenie i adres). Zwraca false, gdy juz byl w kolejce.
function queueMail(PDO $p, string $id, ?string $ticketId, string $email, string $subject, string $body): bool {
    if (!valid_email($email)) throw new Exception('Niepoprawny adres e-mail: '.$email);
    $now = date('Y-m-d H:i:s');
    return insert_ignore($p, 'INSERT INTO mail_outbox(id,ticket_id,email,subject,body,status,created,attempts,next_try,detail) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$id, $ticketId, $email, mb_substr($subject, 0, 200), $body, 'OCZEKUJE', $now, 0, $now, '']);
}

// Wysyla e-maile z kolejki (odbiornik, po SMS-ach). $beat wywolywane po kazdej wiadomosci (heartbeat).
function sendQueuedMail(PDO $p, array $s, int $max, ?callable $beat = null): int {
    if (!smtp_ready($s)) return 0;
    $sent = 0;
    for ($i = 0; $i < $max; $i++) {
        $q = $p->prepare("SELECT * FROM mail_outbox WHERE status='OCZEKUJE' AND next_try<=? ORDER BY created,id".sql_page($p, 1));
        $q->execute([date('Y-m-d H:i:s')]); $r = $q->fetch(); $q->closeCursor();
        if (!$r) break;
        $lock = $p->prepare("UPDATE mail_outbox SET status='W_TRAKCIE' WHERE id=? AND status='OCZEKUJE'");
        $lock->execute([$r['id']]);
        if (!$lock->rowCount()) continue;
        $attempts = (int)$r['attempts'] + 1;
        try {
            smtp_send($s, $r['email'], $r['subject'], $r['body']);
            $p->prepare("UPDATE mail_outbox SET status='WYSLANO',attempts=?,sent=?,detail=? WHERE id=?")->execute([$attempts, date('Y-m-d H:i:s'), 'Serwer SMTP przyjął wiadomość.', $r['id']]);
            $sent++;
        } catch (Throwable $e) {
            $retry = MAIL_RETRY_MINUTES[$attempts - 1] ?? null;
            $status = $retry === null ? 'BLAD' : 'OCZEKUJE';
            $detail = mb_substr($e->getMessage(), 0, 400).($retry !== null ? ' (ponowienie za '.$retry.' min)' : '');
            $p->prepare('UPDATE mail_outbox SET status=?,attempts=?,next_try=?,detail=? WHERE id=?')->execute([$status, $attempts, date('Y-m-d H:i:s', time() + 60 * (int)$retry), $detail, $r['id']]);
            echo date('c').' [MAIL] '.$r['email'].': '.$e->getMessage().PHP_EOL;
            // Serwer niedostepny - nie probuj kolejnych w tym cyklu.
            if ($e instanceof SmtpError && str_starts_with($e->getMessage(), 'Brak połączenia')) break;
        }
        if ($beat) $beat();
    }
    return $sent;
}

// Tresc e-maila o nowym zgloszeniu (polskie znaki zostaja - e-mail to nie SMS).
function ticketMail(array $s, array $group, string $number, string $code, string $description, string $sender, string $status): array {
    $subject = 'Nowe zgłoszenie '.$number.': '.$group['name'].' '.$code;
    $lines = [
        'Nowe zgłoszenie '.$number,
        '',
        'Grupa:        '.$group['name'],
        'Obiekt:       '.$code,
        'Opis:         '.$description,
        'Zgłaszający:  '.$sender,
        'Przyjęto:     '.date('d.m.Y H:i'),
        'Status:       '.(['NOWE' => 'Nowe', 'WERYFIKACJA' => 'Weryfikacja (nieznany obiekt)'][$status] ?? $status),
    ];
    $url = trim((string)$s['panel_url']);
    if ($url !== '') {
        $lines[] = '';
        $lines[] = 'Panel: '.rtrim($url, '/').'/?'.http_build_query(['p' => 'tickets', 'group' => $group['id'], 'q' => $code]);
    }
    $lines[] = '';
    $lines[] = '-- ';
    $lines[] = 'Wiadomość wysłana automatycznie przez SMS-Awarie. Nie odpowiadaj na nią.';
    return [$subject, implode("\n", $lines)];
}
