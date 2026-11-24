<?php
require __DIR__.'/_layout.php';

$c = config();
if ($post) {
    $addr = rtrim(trim((string)($_POST['modem'] ?? '')), '/');
    if (!preg_match('~^http://(\d{1,3}\.){3}\d{1,3}$~D', $addr)) { flash('Adres modemu w postaci http://192.168.8.1', 'err'); redirect('modem'); }
    $c['modem'] = $addr;
    if (($_POST['modempass'] ?? '') !== '') $c['modempass'] = (string)$_POST['modempass'];
    $c['delete_after_import'] = !empty($_POST['delete_after_import']);
    $c['send_per_cycle'] = max(1, min(20, (int)($_POST['send_per_cycle'] ?? 5)));
    saveconfig($c);
    audit($p, $u, 'settings:modem', $addr);
    flash('Zapisano ustawienia modemu. Odbiornik wczyta je po restarcie (Watchdog lub ręcznie).');
    redirect('modem');
}
$hb = heartbeat();
$modem = $hb['raw']['modem'] ?? null;

layout_start($title, $u, $page);
?>
<div class="grid2">
<section class="card">
  <h2>Modem Huawei</h2>
  <form method="post" action="<?= h(url('modem')) ?>">
    <?= csrf_field() ?>
    <label>Adres modemu<input name="modem" value="<?= h($c['modem']) ?>" required></label>
    <label>Hasło administratora modemu (puste = bez zmian)<input name="modempass" type="password" autocomplete="new-password"></label>
    <?php if (in_array(trim((string)$c['modempass']), ['', 'CHANGE_ME'], true)): ?><p class="flash flash-err">Hasło modemu nie jest ustawione – odbiornik nie wystartuje.</p><?php endif; ?>
    <label class="check"><input type="checkbox" name="delete_after_import" value="1"<?= !empty($c['delete_after_import']) ? ' checked' : '' ?>> Usuwaj odebrane SMS z modemu po zapisie w bazie (zalecane – pamięć modemu jest mała)</label>
    <label>SMS wysyłanych w jednym cyklu (1–20)<input name="send_per_cycle" type="number" min="1" max="20" value="<?= (int)$c['send_per_cycle'] ?>"></label>
    <button class="btn">Zapisz</button>
  </form>
</section>
<section class="card">
  <h2>Stan</h2>
  <p>Odbiornik: <?= badge($hb['state']) ?><?php if (($hb['raw']['phase'] ?? '') === 'paused'): ?> <span class="badge b-amber">wstrzymany</span><?php endif; ?></p>
  <?php if (is_array($modem) && (int)($modem['max'] ?? 0) > 0): ?><p>Pamięć SMS modemu: <strong><?= (int)$modem['inbox'] ?> / <?= (int)$modem['max'] ?></strong></p><?php endif; ?>
  <?php if (!empty($hb['raw']['lastError'])): ?><p class="flash flash-err">Ostatni błąd: <?= h($hb['raw']['lastError']) ?></p><?php endif; ?>
  <p class="muted">Testowy SMS wyślesz w Ustawienia › Autoodpowiedzi.</p>
</section>
</div>
<?php layout_end();
