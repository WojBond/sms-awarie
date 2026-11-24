<?php
// Ustawienia > Autoodpowiedzi: potwierdzenie dla grupy Pojazdy i testowy SMS.
require __DIR__.'/_layout.php';

$c = config();
$vehiclesTpl = (string)(one($p, "SELECT sender_template FROM incident_groups WHERE id='vehicles'") ?: 'Przyjeto zgloszenie {number} dla {object}.');
$sample = notificationText($vehiclesTpl, ['{number}' => 'AW/'.date('Y').'/00001', '{group}' => 'Pojazdy', '{object}' => 'PZ12345', '{plate}' => 'PZ12345', '{description}' => 'test', '{status}' => 'NOWE']);

if ($post) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'ack') {
        $c['ack'] = !empty($_POST['ack']);
        $c['own_phone'] = trim((string)($_POST['own_phone'] ?? ''));
        $c['ack_template'] = trim((string)($_POST['ack_template'] ?? ''));
        if ($c['own_phone'] !== '' && !preg_match('/^\+?[0-9]{9,15}$/D', $c['own_phone'])) { flash('Niepoprawny numer SIM.', 'err'); redirect('settings'); }
        if (mb_strlen($c['ack_template']) > 150) { flash('Szablon maks. 150 znaków.', 'err'); redirect('settings'); }
        saveconfig($c);
        // Odbiornik uzywa ustawien grupy Pojazdy.
        $tpl = $c['ack_template'] !== '' ? str_replace('{plate}', '{object}', $c['ack_template']) : 'Przyjeto zgloszenie {number} dla {object}.';
        $p->prepare("UPDATE incident_groups SET notify_sender=?,sender_template=? WHERE id='vehicles'")->execute([$c['ack'] ? 1 : 0, $tpl]);
        audit($p, $u, 'settings:ack', $c['ack'] ? 'wl' : 'wyl');
        flash('Zapisano autoodpowiedzi. Zmiana działa od razu.');
    } elseif ($act === 'test') {
        $phone = trim((string)($_POST['phone'] ?? ''));
        $text = trim((string)($_POST['content'] ?? ''));
        if ($text === '') $text = $sample;
        if (time() - ($_SESSION['last_send'] ?? 0) < 10) {
            flash('Odczekaj 10 sekund przed kolejnym SMS.', 'err');
        } else {
            try {
                $id = 'test-'.uid();
                queueSms($p, $id, $phone, $text);
                $_SESSION['last_send'] = time();
                $_SESSION['test_phone'] = $phone;
                audit($p, $u, 'settings:test-sms', $phone);
                flash('Testowy SMS na '.$phone.' czeka w kolejce. Odbiornik wyśle go w ciągu ok. 15–30 s – status widać niżej.');
            } catch (Exception $e) { flash($e->getMessage(), 'err'); }
        }
    }
    redirect('settings');
}

$hb = heartbeat();
$paused = ($hb['raw']['phase'] ?? '') === 'paused';
$tests = rows($p, "SELECT phone,content,status,created,detail FROM outbox WHERE id LIKE 'test-%' ORDER BY created DESC".sql_page($p, 5));

layout_start($title, $u, $page);
?>
<div class="grid2">
<section class="card">
  <h2>Autoodpowiedzi dla pojazdów</h2>
  <form method="post" action="<?= h(url('settings')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="ack">
    <label class="check"><input type="checkbox" name="ack" value="1"<?= !empty($c['ack']) ? ' checked' : '' ?>> Wysyłaj potwierdzenie przyjęcia zgłoszenia</label>
    <label>Szablon<input name="ack_template" value="<?= h($c['ack_template']) ?>" maxlength="150" placeholder="Przyjeto zgloszenie {number} dla {plate}."></label>
    <label>Numer karty SIM modemu (informacyjnie)<input name="own_phone" value="<?= h($c['own_phone']) ?>" inputmode="tel"></label>
    <p class="muted">Zmienne: {number} {plate} {status}. Dla pozostałych grup potwierdzenia ustawisz na stronie grupy (Grupy zgłoszeń).</p>
    <button class="btn">Zapisz</button>
  </form>
</section>
<section class="card">
  <h2>Testowy SMS</h2>
  <form method="post" action="<?= h(url('settings')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="test">
    <label>Numer telefonu<input name="phone" required pattern="\+?[0-9]{9,15}" inputmode="tel" placeholder="+48600100200" value="<?= h($_SESSION['test_phone'] ?? '') ?>"></label>
    <label>Treść (puste = autoodpowiedź z przykładowymi danymi)<textarea name="content" rows="2" maxlength="150" placeholder="<?= h($sample) ?>"></textarea></label>
    <p class="muted">Podgląd autoodpowiedzi: <code><?= h($sample) ?></code></p>
    <?php if ($hb['state'] !== 'RUNNING'): ?><p class="flash flash-err">Odbiornik nie działa (<?= h($hb['state']) ?>) – SMS poczeka w kolejce, aż odbiornik wystartuje.</p><?php elseif ($paused): ?><p class="flash flash-err">Odbiornik jest wstrzymany (przełączanie bazy).</p><?php endif; ?>
    <button class="btn">Wyślij testowy SMS</button>
  </form>
  <?php if ($tests): ?>
  <h2 class="mt">Ostatnie testy</h2>
  <div class="table-wrap"><table><thead><tr><th>Utworzono</th><th>Telefon</th><th>Status</th><th>Szczegóły</th></tr></thead><tbody>
  <?php foreach ($tests as $r): ?><tr><td><?= h(fmt_dt($r['created'])) ?></td><td><?= h($r['phone']) ?></td><td><?= badge($r['status']) ?></td><td><?= h($r['detail'] ?: $r['content']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="muted">PRZYJETY = modem przyjął SMS do wysyłki (to nie jest potwierdzenie doręczenia).</p>
  <?php endif; ?>
</section>
</div>
<?php layout_end();
