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
    $err = null;
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) $err = 'Nazwa: 2–60 znaków.';
    elseif (!preg_match('/^[A-Z][A-Z0-9_-]{1,15}$/D', $prefix)) $err = 'Prefiks: 2–16 znaków, zaczyna się literą (np. MAS).';
    foreach ([$senderTpl, $recipientTpl] as $t) if (!$err && ($t === '' || mb_strlen($t) > 150)) $err = 'Szablony: 1–150 znaków.';
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
    audit($p, $u, 'groups:save', $gid.' '.$prefix);
    $p->commit();
    flash("Zapisano grupę $name.");
    return $gid;
}

// 4.6.0: odbiorcy powiadomien to uzytkownicy (group_members) - formularz na stronie grupy, nie tutaj.
function group_form(array $g, string $action): void { ?>
  <form method="post" action="<?= h($action) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($g['id']) ?>">
    <div class="grid2">
      <label>Nazwa<input name="name" value="<?= h($g['name']) ?>" required maxlength="60"></label>
      <label>Prefiks SMS<input name="prefix" value="<?= h($g['prefix']) ?>" required<?= $g['id'] === 'vehicles' ? ' readonly' : '' ?> pattern="[A-Za-z][A-Za-z0-9_\-]{1,15}"></label>
    </div>
    <label>Odpowiedź do zgłaszającego<input name="sender_template" value="<?= h($g['sender_template']) ?>" required maxlength="150"></label>
    <label>Powiadomienie SMS dla odbiorców grupy<input name="recipient_template" value="<?= h($g['recipient_template']) ?>" required maxlength="150"></label>
    <p class="muted"><?= h(TPL_HELP) ?></p>
    <label class="check"><input type="checkbox" name="notify_sender" value="1"<?= (int)$g['notify_sender'] ? ' checked' : '' ?>> Wysyłaj potwierdzenie do zgłaszającego</label>
    <?php if ($g['id'] !== 'vehicles'): ?><label class="check"><input type="checkbox" name="active" value="1"<?= (int)$g['active'] ? ' checked' : '' ?>> Grupa aktywna</label><?php else: ?><input type="hidden" name="active" value="1"><?php endif; ?>
    <button class="btn">Zapisz</button>
  </form>
<?php }

// Liczba odbiorcow powiadomien grup: [id grupy => liczba] (czlonkowie z SMS lub e-mail + dodatkowe numery).
function group_recipient_counts(PDO $p): array {
    $out = [];
    foreach (rows($p, 'SELECT m.group_id,COUNT(*) AS n FROM group_members m JOIN web_users u ON u.id=m.user_id AND u.active=1 WHERE m.notify_sms=1 OR m.notify_email=1 GROUP BY m.group_id') as $r) $out[$r['group_id']] = (int)$r['n'];
    foreach (rows($p, 'SELECT group_id,COUNT(*) AS n FROM group_recipients WHERE active=1 GROUP BY group_id') as $r) $out[$r['group_id']] = ($out[$r['group_id']] ?? 0) + (int)$r['n'];
    return $out;
}

// Zapis odbiorcow grupy (tylko administrator): uzytkownicy z SMS/e-mail i ewentualne dodatkowe numery sprzed 4.6.0.
function group_members_save(PDO $p, array $u, string $gid): void {
    $sms = (array)($_POST['sms'] ?? []); $mail = (array)($_POST['mail'] ?? []);
    $extra = array_key_exists('extra', $_POST) ? preg_split('/[\s,;]+/', (string)$_POST['extra'], -1, PREG_SPLIT_NO_EMPTY) : null;
    if ($extra !== null) {
        if (count($extra) > 50) { flash('Maksymalnie 50 dodatkowych numerów.', 'err'); redirect('group', ['id' => $gid]); }
        foreach ($extra as $ph) if (!preg_match('/^\+?[0-9]{9,15}$/D', $ph)) { flash("Niepoprawny numer: $ph", 'err'); redirect('group', ['id' => $gid]); }
    }
    $p->beginTransaction();
    $p->prepare('DELETE FROM group_members WHERE group_id=?')->execute([$gid]);
    $ins = $p->prepare('INSERT INTO group_members(group_id,user_id,notify_sms,notify_email) VALUES(?,?,?,?)');
    $n = 0;
    // SMS tylko z telefonem, e-mail tylko z adresem w koncie (jak w formularzu).
    foreach (rows($p, 'SELECT id,phone,email FROM web_users') as $usr) {
        $s = !empty($sms[$usr['id']]) && (string)$usr['phone'] !== '' ? 1 : 0; $m = !empty($mail[$usr['id']]) && (string)$usr['email'] !== '' ? 1 : 0;
        if ($s || $m) { $ins->execute([$gid, $usr['id'], $s, $m]); $n++; }
    }
    if ($extra !== null) {
        $p->prepare('DELETE FROM group_recipients WHERE group_id=?')->execute([$gid]);
        $ri = $p->prepare('INSERT INTO group_recipients(id,group_id,phone,active) VALUES(?,?,?,1)');
        foreach (array_unique($extra) as $ph) $ri->execute([uid(), $gid, $ph]);
    }
    $removed = group_dedupe_recipients($p, $gid);
    audit($p, $u, 'groups:members', $gid.' uzytkownicy:'.$n.($extra !== null ? ' numery:'.count(array_unique($extra)) : ''));
    $p->commit();
    flash('Zapisano odbiorców powiadomień ('.$n.' '.($n === 1 ? 'użytkownik' : 'użytkowników').').'.($removed ? ' Usunięto dodatkowe numery należące już do użytkowników: '.$removed.'.' : ''));
}

// Formularz odbiorcow na stronie grupy.
function group_members_form(PDO $p, array $g): void {
    $gid = $g['id'];
    $people = rows($p, 'SELECT w.id,w.login,w.role,w.groups_all,w.email,w.phone,w.login_enabled,a.level,m.notify_sms,m.notify_email FROM web_users w
        LEFT JOIN user_group_access a ON a.user_id=w.id AND a.group_id=? LEFT JOIN group_members m ON m.user_id=w.id AND m.group_id=? WHERE w.active=1 ORDER BY CASE WHEN m.user_id IS NULL THEN 1 ELSE 0 END, w.login', [$gid, $gid]);
    $extra = array_column(rows($p, 'SELECT phone FROM group_recipients WHERE group_id=? AND active=1 ORDER BY phone', [$gid]), 'phone');
    $smtpOn = smtp_ready(smtp_settings());
    ?>
  <form method="post" action="<?= h(url('group', ['id' => $gid])) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="members">
    <p class="muted hint">Po każdym nowym zgłoszeniu zaznaczeni użytkownicy dostają SMS na telefon i e-mail na adres zapisany w ich koncie (Użytkownicy). Telefon i adres zmieniasz w koncie – grupa korzysta zawsze z aktualnych danych.<?= $smtpOn ? '' : ' <strong>Wysyłka e-maili jest wyłączona</strong> (Ustawienia › Poczta e-mail).' ?></p>
    <?php if (!$people): ?><p class="muted">Brak aktywnych kont.</p><?php else: ?>
    <div class="table-wrap members-table"><table><thead><tr><th>Użytkownik</th><th>Telefon</th><th>E-mail</th><th>Dostęp w panelu</th><th class="c">SMS</th><th class="c">E-mail</th></tr></thead><tbody>
    <?php foreach ($people as $r):
        $hasPhone = (string)$r['phone'] !== ''; $hasMail = (string)$r['email'] !== '';
        if ($r['role'] === 'admin') $acc = 'pełny';
        elseif (!(int)$r['login_enabled']) $acc = '–';
        else { $lv = level_cap($r, (string)$r['groups_all']) ?: level_cap($r, (string)$r['level']); $acc = $lv ? GROUP_LEVELS[array_search($lv, LEVEL_RANK, true)] : ''; } ?>
      <tr><td><strong><?= h($r['login']) ?></strong> <small class="muted"><?= h((int)$r['login_enabled'] ? (ROLES[$r['role']] ?? $r['role']) : 'tylko powiadomienia') ?></small></td>
      <td><?= $hasPhone ? h($r['phone']) : '<span class="muted">brak</span>' ?></td>
      <td><?= $hasMail ? h($r['email']) : '<span class="muted">brak</span>' ?></td>
      <td><?= $acc !== '' ? h($acc) : '<span class="badge b-amber" title="Dostanie powiadomienia, ale nie zobaczy zgłoszeń tej grupy w panelu">brak dostępu</span>' ?></td>
      <td class="c"><label class="tick"><input type="checkbox" name="sms[<?= h($r['id']) ?>]" value="1"<?= (int)$r['notify_sms'] ? ' checked' : '' ?><?= $hasPhone ? '' : ' disabled' ?> aria-label="SMS do <?= h($r['login']) ?>"></label></td>
      <td class="c"><label class="tick"><input type="checkbox" name="mail[<?= h($r['id']) ?>]" value="1"<?= (int)$r['notify_email'] ? ' checked' : '' ?><?= $hasMail ? '' : ' disabled' ?> aria-label="E-mail do <?= h($r['login']) ?>"></label></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php if ($extra): ?>
    <div class="extra-numbers">
      <strong>Dodatkowe numery spoza kont</strong> <small class="muted">z wersji 4.5 i starszych – dalej dostają SMS</small>
      <p class="muted hint">Zamień numer na konto (np. bez logowania, tylko do powiadomień), aby ta osoba mogła dostawać także e-maile. Numer należący już do zaznaczonego użytkownika zniknie z tej listy sam.</p>
      <ul class="numbers"><?php foreach ($extra as $ph): ?><li><span><?= h($ph) ?></span> <a class="btn btn-o" href="<?= h(url('users', ['new_phone' => $ph, 'new_group' => $gid])) ?>">Zamień na konto</a></li><?php endforeach; ?></ul>
      <label>Edycja listy (jeden numer w linii; usuń numer, aby przestał dostawać SMS)<textarea name="extra" rows="3"><?= h(implode("\n", $extra)) ?></textarea></label>
    </div>
    <?php endif; ?>
    <button class="btn">Zapisz odbiorców</button>
  </form>
<?php }
