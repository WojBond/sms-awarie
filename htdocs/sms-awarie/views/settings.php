<?php
require __DIR__.'/_layout.php';

$c = config();
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
    } elseif ($act === 'modem') {
        $addr = rtrim(trim((string)($_POST['modem'] ?? '')), '/');
        if (!preg_match('~^http://(\d{1,3}\.){3}\d{1,3}$~D', $addr)) { flash('Adres modemu w postaci http://192.168.8.1', 'err'); redirect('settings'); }
        $c['modem'] = $addr;
        if (($_POST['modempass'] ?? '') !== '') $c['modempass'] = (string)$_POST['modempass'];
        $c['delete_after_import'] = !empty($_POST['delete_after_import']);
        $c['send_per_cycle'] = max(1, min(20, (int)($_POST['send_per_cycle'] ?? 5)));
        saveconfig($c);
        audit($p, $u, 'settings:modem', $addr);
        flash('Zapisano ustawienia modemu. Odbiornik wczyta je po restarcie (Watchdog lub ręcznie).');
    }
    redirect('settings');
}

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
    <p class="muted">Zmienne: {number} {plate} {status}. Dla pozostałych grup potwierdzenia ustawisz w zakładce Grupy zgłoszeń.</p>
    <button class="btn">Zapisz</button>
  </form>
</section>
<section class="card">
  <h2>Modem Huawei</h2>
  <form method="post" action="<?= h(url('settings')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="modem">
    <label>Adres modemu<input name="modem" value="<?= h($c['modem']) ?>" required></label>
    <label>Hasło administratora modemu (puste = bez zmian)<input name="modempass" type="password" autocomplete="new-password"></label>
    <?php if (in_array(trim((string)$c['modempass']), ['', 'CHANGE_ME'], true)): ?><p class="flash flash-err">Hasło modemu nie jest ustawione – odbiornik nie wystartuje.</p><?php endif; ?>
    <label class="check"><input type="checkbox" name="delete_after_import" value="1"<?= !empty($c['delete_after_import']) ? ' checked' : '' ?>> Usuwaj odebrane SMS z modemu po zapisie w bazie (zalecane – pamięć modemu jest mała)</label>
    <label>SMS wysyłanych w jednym cyklu (1–20)<input name="send_per_cycle" type="number" min="1" max="20" value="<?= (int)$c['send_per_cycle'] ?>"></label>
    <button class="btn">Zapisz</button>
  </form>
</section>
</div>
<?php layout_end();
