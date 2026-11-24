<?php
// Wspolne dla stron Grupy zgloszen (lista) i grupy (szczegoly): zapis i formularz grupy.

const TPL_HELP = 'Zmienne: {number} {group} {object} {description} {status}. Polskie litery zostaną zamienione na łacińskie.';

// Zapis grupy z formularza (tylko administrator). Zwraca id grupy; przy bledzie flash + redirect.
function group_save(PDO $p, array $u, string $back, array $backParams): string {
    $gid = (string)($_POST['id'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $prefix = strtoupper(trim((string)($_POST['prefix'] ?? '')));
    // Szablony transliteruje notificationText() przy wysylce.
    $senderTpl = trim((string)($_POST['sender_template'] ?? ''));
    $recipientTpl = trim((string)($_POST['recipient_template'] ?? ''));
    $phones = preg_split('/[\s,;]+/', (string)($_POST['recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $err = null;
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) $err = 'Nazwa: 2–60 znaków.';
    elseif (!preg_match('/^[A-Z][A-Z0-9_-]{1,15}$/D', $prefix)) $err = 'Prefiks: 2–16 znaków, zaczyna się literą (np. MAS).';
    foreach ([$senderTpl, $recipientTpl] as $t) if (!$err && ($t === '' || mb_strlen($t) > 150)) $err = 'Szablony: 1–150 znaków.';
    if (!$err && count($phones) > 50) $err = 'Maksymalnie 50 odbiorców.';
    foreach ($phones as $ph) if (!$err && !preg_match('/^\+?[0-9]{9,15}$/D', $ph)) $err = "Niepoprawny numer: $ph";
    if (!$err && one($p, 'SELECT id FROM incident_groups WHERE prefix=? AND id<>?', [$prefix, $gid])) $err = "Prefiks $prefix jest już używany.";
    if ($err) { flash($err, 'err'); redirect($back, $backParams); }

    $active = !empty($_POST['active']) ? 1 : 0;
    $notify = !empty($_POST['notify_sender']) ? 1 : 0;
    $p->beginTransaction();
    if ($gid === '') {
        $gid = uid();
        $p->prepare('INSERT INTO incident_groups(id,name,prefix,active,legacy_vehicle,notify_sender,sender_template,recipient_template) VALUES(?,?,?,?,0,?,?,?)')->execute([$gid, $name, $prefix, $active, $notify, $senderTpl, $recipientTpl]);
    } else {
        if (!one($p, 'SELECT id FROM incident_groups WHERE id=?', [$gid])) { $p->rollBack(); flash('Nie znaleziono grupy.', 'err'); redirect('groups'); }
        if ($gid === 'vehicles') { $active = 1; $prefix = 'POJ'; }
        $p->prepare('UPDATE incident_groups SET name=?,prefix=?,active=?,notify_sender=?,sender_template=?,recipient_template=? WHERE id=?')->execute([$name, $prefix, $active, $notify, $senderTpl, $recipientTpl, $gid]);
    }
    $p->prepare('DELETE FROM group_recipients WHERE group_id=?')->execute([$gid]);
    $ins = $p->prepare('INSERT INTO group_recipients(id,group_id,phone,active) VALUES(?,?,?,1)');
    foreach (array_unique($phones) as $ph) $ins->execute([uid(), $gid, $ph]);
    audit($p, $u, 'groups:save', $gid.' '.$prefix);
    $p->commit();
    flash("Zapisano grupę $name.");
    return $gid;
}

function group_form(array $g, array $phones, string $action): void { ?>
  <form method="post" action="<?= h($action) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($g['id']) ?>">
    <div class="grid2">
      <label>Nazwa<input name="name" value="<?= h($g['name']) ?>" required maxlength="60"></label>
      <label>Prefiks SMS<input name="prefix" value="<?= h($g['prefix']) ?>" required<?= $g['id'] === 'vehicles' ? ' readonly' : '' ?> pattern="[A-Za-z][A-Za-z0-9_\-]{1,15}"></label>
    </div>
    <label>Odpowiedź do zgłaszającego<input name="sender_template" value="<?= h($g['sender_template']) ?>" required maxlength="150"></label>
    <label>Powiadomienie dla odbiorców grupy<input name="recipient_template" value="<?= h($g['recipient_template']) ?>" required maxlength="150"></label>
    <p class="muted"><?= h(TPL_HELP) ?></p>
    <label>Odbiorcy powiadomień (jeden numer w linii)<textarea name="recipients" rows="3"><?= h(implode("\n", $phones)) ?></textarea></label>
    <label class="check"><input type="checkbox" name="notify_sender" value="1"<?= (int)$g['notify_sender'] ? ' checked' : '' ?>> Wysyłaj potwierdzenie do zgłaszającego</label>
    <?php if ($g['id'] !== 'vehicles'): ?><label class="check"><input type="checkbox" name="active" value="1"<?= (int)$g['active'] ? ' checked' : '' ?>> Grupa aktywna</label><?php else: ?><input type="hidden" name="active" value="1"><?php endif; ?>
    <button class="btn">Zapisz</button>
  </form>
<?php }
