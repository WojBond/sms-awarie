<?php
// Ustawienia > Poczta e-mail (4.6.0): konto SMTP do powiadomien, testowy e-mail, kolejka e-maili.
require __DIR__.'/_layout.php';

$c = config();
$s = smtp_settings($c);

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $n = $s;
        $n['enabled'] = !empty($_POST['enabled']);
        $n['host'] = trim((string)($_POST['host'] ?? ''));
        $n['port'] = (int)($_POST['port'] ?? 0);
        $n['security'] = (string)($_POST['security'] ?? 'tls');
        $n['user'] = trim((string)($_POST['user'] ?? ''));
        if (!empty($_POST['clear_pass'])) $n['pass'] = '';
        elseif ((string)($_POST['pass'] ?? '') !== '') $n['pass'] = (string)$_POST['pass'];
        $n['from_email'] = trim((string)($_POST['from_email'] ?? ''));
        $n['from_name'] = trim((string)($_POST['from_name'] ?? ''));
        $n['verify_cert'] = !empty($_POST['verify_cert']);
        $n['panel_url'] = trim((string)($_POST['panel_url'] ?? ''));
        $err = null;
        if ($n['host'] !== '' && !preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $n['host'])) $err = 'Serwer SMTP: nazwa lub adres IP, np. smtp.firma.pl.';
        elseif ($n['port'] < 1 || $n['port'] > 65535) $err = 'Port 1–65535.';
        elseif (!isset(SMTP_SECURITY[$n['security']])) $err = 'Wybierz szyfrowanie.';
        elseif ($n['from_email'] !== '' && !valid_email($n['from_email'])) $err = 'Niepoprawny adres nadawcy.';
        elseif (mb_strlen($n['from_name']) > 80) $err = 'Nazwa nadawcy maks. 80 znaków.';
        elseif ($n['panel_url'] !== '' && !preg_match('~^https?://[^\s"<>]+$~D', $n['panel_url'])) $err = 'Adres panelu w postaci http://serwer/sms-awarie/';
        elseif ($n['enabled'] && !smtp_ready($n)) $err = 'Aby włączyć wysyłkę, podaj serwer SMTP i adres nadawcy.';
        if ($err) { flash($err, 'err'); $_SESSION['mail_form'] = $_POST; redirect('mail'); }
        $c['smtp'] = $n;
        saveconfig($c);
        audit($p, $u, 'settings:mail', ($n['enabled'] ? 'wl ' : 'wyl ').$n['host'].':'.$n['port']);
        flash($n['enabled'] ? 'Zapisano ustawienia poczty. Powiadomienia e-mail są włączone.' : 'Zapisano ustawienia poczty. Wysyłka e-maili jest wyłączona.');
    } elseif ($act === 'test') {
        $to = trim((string)($_POST['to'] ?? ''));
        $_SESSION['test_mail'] = $to;
        if (time() - ($_SESSION['last_mail'] ?? 0) < 5) flash('Odczekaj kilka sekund przed kolejnym testem.', 'err');
        elseif (!valid_email($to)) flash('Podaj poprawny adres odbiorcy testu.', 'err');
        else {
            $_SESSION['last_mail'] = time();
            $log = [];
            try {
                // Test od razu z panelu (bez kolejki), zeby widac bylo odpowiedz serwera.
                smtp_send($s, $to, 'Test powiadomień SMS-Awarie', "To jest testowa wiadomość z panelu SMS-Awarie ".APP_VERSION.".\n\nJeśli ją widzisz, konto pocztowe jest ustawione poprawnie i powiadomienia o zgłoszeniach będą dochodzić na adresy użytkowników.\n\nWysłano: ".date('d.m.Y H:i')."\nPrzez: ".$s['host'].':'.$s['port'].' ('.$s['security'].')', $log);
                audit($p, $u, 'settings:test-mail', $to.' OK');
                flash('Serwer SMTP przyjął testowy e-mail na '.$to.'. Sprawdź skrzynkę (także folder Spam).');
            } catch (Throwable $e) {
                audit($p, $u, 'settings:test-mail', $to.' BLAD');
                flash('Test nie powiódł się: '.$e->getMessage(), 'err');
            }
            $_SESSION['mail_log'] = array_slice($log, -40);
        }
    } elseif ($act === 'retry') {
        $n = $p->prepare("UPDATE mail_outbox SET status='OCZEKUJE',attempts=0,next_try=?,detail='' WHERE status='BLAD'");
        $n->execute([date('Y-m-d H:i:s')]);
        audit($p, $u, 'mail:retry', (string)$n->rowCount());
        flash('Ponowiono e-maile z błędem: '.$n->rowCount().'.');
    } elseif ($act === 'cancel') {
        $n = $p->prepare("UPDATE mail_outbox SET status='ANULOWANY' WHERE status IN ('OCZEKUJE','BLAD')");
        $n->execute();
        audit($p, $u, 'mail:cancel', (string)$n->rowCount());
        flash('Anulowano e-maile oczekujące i z błędem: '.$n->rowCount().'.');
    }
    redirect('mail');
}

// Po bledzie walidacji formularz pokazuje wpisane wartosci (bez hasla).
if (!empty($_SESSION['mail_form'])) {
    $f = $_SESSION['mail_form']; unset($_SESSION['mail_form']);
    foreach (['host', 'port', 'security', 'user', 'from_email', 'from_name', 'panel_url'] as $k) if (isset($f[$k])) $s[$k] = (string)$f[$k];
    $s['enabled'] = !empty($f['enabled']); $s['verify_cert'] = !empty($f['verify_cert']);
}
$log = $_SESSION['mail_log'] ?? null; unset($_SESSION['mail_log']);
$counts = [];
foreach (rows($p, 'SELECT status,COUNT(*) AS n FROM mail_outbox GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
$recent = rows($p, 'SELECT m.email,m.subject,m.status,m.created,m.sent,m.attempts,m.detail FROM mail_outbox m ORDER BY m.created DESC, m.id'.sql_page($p, 20));
$withEmail = (int)one($p, "SELECT COUNT(*) FROM web_users WHERE active=1 AND email<>''");
$hb = heartbeat();
$defaultUrl = 'http://'.($_SERVER['HTTP_HOST'] ?? 'serwer').rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/sms-awarie/index.php'), '/\\').'/';

layout_start($title, $u, $page);
?>
<p><?= smtp_ready($s) ? '<span class="badge b-green">Wysyłka e-maili włączona</span>' : '<span class="badge b-grey">Wysyłka e-maili wyłączona</span>' ?>
  <small class="muted">aktywne konta z adresem e-mail: <?= $withEmail ?> · e-maile do grup ustawisz na stronie grupy (Odbiorcy powiadomień)</small></p>
<div class="grid2">
<section class="card">
  <h2>Konto pocztowe (SMTP)</h2>
  <form method="post" action="<?= h(url('mail')) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($s['enabled']) ? ' checked' : '' ?>> Wysyłaj powiadomienia e-mail</label>
    <div class="grid2 tight">
      <label>Serwer SMTP<input name="host" value="<?= h($s['host']) ?>" placeholder="smtp.firma.pl" maxlength="253"></label>
      <label>Port<input name="port" type="number" min="1" max="65535" value="<?= (int)$s['port'] ?>" inputmode="numeric"></label>
    </div>
    <label>Szyfrowanie<select name="security"><?php foreach (SMTP_SECURITY as $k => $v): ?><option value="<?= h($k) ?>"<?= $s['security'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
    <div class="grid2 tight">
      <label>Login (puste = bez logowania)<input name="user" value="<?= h($s['user']) ?>" autocomplete="off" placeholder="awarie@firma.pl"></label>
      <label>Hasło<?= $s['pass'] !== '' ? ' (zapisane – puste = bez zmian)' : '' ?><input name="pass" type="password" autocomplete="new-password"></label>
    </div>
    <?php if ($s['pass'] !== ''): ?><label class="check"><input type="checkbox" name="clear_pass" value="1"> Usuń zapisane hasło</label><?php endif; ?>
    <div class="grid2 tight">
      <label>Adres nadawcy<input name="from_email" type="email" value="<?= h($s['from_email']) ?>" placeholder="awarie@firma.pl" maxlength="254"></label>
      <label>Nazwa nadawcy<input name="from_name" value="<?= h($s['from_name']) ?>" maxlength="80" placeholder="SMS-Awarie"></label>
    </div>
    <label class="check"><input type="checkbox" name="verify_cert" value="1"<?= !empty($s['verify_cert']) ? ' checked' : '' ?>> Sprawdzaj certyfikat serwera (wyłącz tylko dla serwera z certyfikatem wystawionym wewnętrznie)</label>
    <label>Adres panelu w e-mailach (opcjonalnie)<input name="panel_url" value="<?= h($s['panel_url']) ?>" placeholder="<?= h($defaultUrl) ?>"></label>
    <p class="muted">Z adresem panelu e-mail zawiera link do zgłoszenia. Hasło jest zapisane w <code>data\config.json</code> (katalog chroniony przed dostępem z przeglądarki). Microsoft 365 i Gmail wymagają zwykle STARTTLS na porcie 587 i hasła aplikacji.</p>
    <button class="btn">Zapisz</button>
  </form>
</section>
<section class="card">
  <h2>Testowy e-mail</h2>
  <form method="post" action="<?= h(url('mail')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="test">
    <label>Adres odbiorcy<input name="to" type="email" required value="<?= h($_SESSION['test_mail'] ?? ($u['email'] ?? '')) ?>" placeholder="jan.kowalski@firma.pl"></label>
    <p class="muted">Test wysyła wiadomość od razu z zapisanymi ustawieniami (najpierw kliknij Zapisz) i pokazuje odpowiedź serwera. Działa także przy wyłączonej wysyłce powiadomień.</p>
    <button class="btn"<?= trim((string)$s['host']) === '' ? ' disabled' : '' ?>>Wyślij testowy e-mail</button>
  </form>
  <?php if ($log): ?>
  <details class="mt" open><summary>Przebieg połączenia z serwerem</summary><pre class="log"><?= h(implode("\n", $log)) ?></pre></details>
  <?php endif; ?>
</section>
</div>
<section class="card">
  <div class="card-head"><h2>Ostatnie e-maile</h2>
  <span class="muted">oczekuje: <?= ($counts['OCZEKUJE'] ?? 0) + ($counts['W_TRAKCIE'] ?? 0) ?> · wysłano: <?= $counts['WYSLANO'] ?? 0 ?> · błąd: <?= $counts['BLAD'] ?? 0 ?></span></div>
  <?php if (($counts['OCZEKUJE'] ?? 0) > 0 && $hb['state'] !== 'RUNNING'): ?><p class="flash flash-err">Odbiornik nie działa (<?= h(STATUS_LABELS[$hb['state']] ?? $hb['state']) ?>) – e-maile wysyła odbiornik, po SMS-ach w każdym cyklu.</p><?php endif; ?>
  <?php if (!$recent): ?><p class="muted">Brak e-maili. Powiadomienia trafiają tu, gdy w grupie zgłoszeń są użytkownicy z adresem e-mail i zaznaczonym „E-mail”.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Utworzono</th><th>Adres</th><th>Temat</th><th>Status</th><th>Szczegóły</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?><tr><td><?= h(fmt_dt($r['created'])) ?></td><td><?= h($r['email']) ?></td><td><?= h($r['subject']) ?></td><td><?= badge($r['status']) ?></td><td><?= h($r['detail'] ?: ($r['sent'] ? 'wysłano '.fmt_dt($r['sent']) : '')) ?><?= (int)$r['attempts'] > 1 ? ' <small class="muted">prób: '.(int)$r['attempts'].'</small>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if (($counts['BLAD'] ?? 0) + ($counts['OCZEKUJE'] ?? 0) > 0): ?>
  <div class="actions">
    <?php if (($counts['BLAD'] ?? 0) > 0): ?><form method="post" action="<?= h(url('mail')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><button class="btn">Ponów e-maile z błędem</button></form><?php endif; ?>
    <form method="post" action="<?= h(url('mail')) ?>" class="inline" data-confirm="Anulować wszystkie oczekujące i nieudane e-maile?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-o">Anuluj oczekujące</button></form>
  </div>
  <?php endif; ?>
  <p class="muted">Nieudana wysyłka jest ponawiana po 1, 5, 15 i 60 minutach, potem dostaje status Błąd. „Wysłano” oznacza przyjęcie przez serwer SMTP.</p>
</section>
<?php layout_end();
