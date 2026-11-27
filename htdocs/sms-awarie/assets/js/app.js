// SMS-Awarie - drobne zachowania panelu (bez bibliotek).
// Tabele list na telefonie wyswietlane jako karty: etykieta kolumny w data-label kazdej komorki.
function stackTables(root) {
  root.querySelectorAll('table').forEach(function (t) {
    var th = t.querySelectorAll('thead th');
    if (!th.length) return;
    t.classList.add('stack');
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) {
        if (th[i] && !td.hasAttribute('colspan')) td.setAttribute('data-label', th[i].textContent.trim());
        if (!td.textContent.trim() && !td.querySelector('input, button, select, a')) td.classList.add('empty');
      });
    });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  stackTables(document);

  // Potwierdzenie dla formularzy z atrybutem data-confirm.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Odswiezanie Dashboardu co 10 s (tylko gdy karta jest widoczna).
  var live = document.getElementById('live');
  if (live && live.dataset.refresh) {
    setInterval(function () {
      if (document.hidden) return;
      fetch(live.dataset.refresh, { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { if (!r.ok || r.redirected) throw new Error(r.status); return r.text(); })
        .then(function (html) { live.innerHTML = html; stackTables(live); })
        .catch(function () { /* brak sieci - sprobuj w kolejnym cyklu */ });
    }, 10000);
  }

  // Kontrola ochrony katalogu data (wymaga AllowOverride All w Apache).
  var ex = document.getElementById('exposure');
  if (ex && ex.dataset.check) {
    fetch(ex.dataset.check, { method: 'HEAD', cache: 'no-store' })
      .then(function (r) { if (r.ok) ex.hidden = false; })
      .catch(function () {});
  }

  // Menu boczne na telefonie: zamknij po wyborze strony.
  var navt = document.getElementById('navt');
  document.querySelectorAll('.side nav a').forEach(function (a) {
    a.addEventListener('click', function () { if (navt) navt.checked = false; });
  });
  // Klawisz Esc zamyka wysuniete menu na telefonie.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && navt && navt.checked) navt.checked = false;
  });

  // 4.7.0/4.8.0: menu boczne na PC. Tryby (zapamietane w przegladarce):
  //   ''     - stale rozwiniete,  'min' - stale zwiniete do paska ikon (przycisk obok nazwy),
  //   'auto' - pasek ikon, ktory rozwija sie nad trescia po najechaniu kursorem i chowa po zjechaniu.
  var root = document.documentElement, side = document.querySelector('.side');
  var toggle = document.querySelector('.side-toggle'), autoBox = document.getElementById('side-auto');
  var wide = window.matchMedia('(min-width: 1000px)');
  var mode = root.classList.contains('side-auto') ? 'auto' : (root.classList.contains('side-min') ? 'min' : '');
  var peekTimer = null;
  function railTips(on) {
    document.querySelectorAll('.side nav a, .side .sub > summary').forEach(function (el) {
      if (on) el.setAttribute('title', el.textContent.trim()); else el.removeAttribute('title');
    });
  }
  function peek(on, delay) {
    clearTimeout(peekTimer);
    if (mode !== 'auto') return;
    peekTimer = setTimeout(function () { root.classList.toggle('side-peek', on); }, delay || 0);
  }
  function setMode(m) {
    mode = m;
    clearTimeout(peekTimer);
    root.classList.toggle('side-auto', m === 'auto');
    root.classList.toggle('side-min', m !== '');
    root.classList.toggle('side-peek', m === 'auto' && !!side && side.matches(':hover'));
    if (toggle) {
      var t = m === 'min' ? 'Rozwiń menu' : 'Zwiń menu';
      toggle.setAttribute('aria-expanded', m === 'min' ? 'false' : 'true');
      toggle.setAttribute('aria-label', t);
      toggle.title = t;
    }
    if (autoBox) autoBox.checked = m === 'auto';
    railTips(m === 'min');
    try { localStorage.setItem('sms-awarie-side', m); } catch (e) { /* tryb prywatny */ }
  }
  if (side) {
    setMode(mode);
    if (toggle) toggle.addEventListener('click', function () { setMode(mode === 'min' ? '' : 'min'); });
    // Przelacznik: wlaczony = tryb automatyczny, wylaczony = tryb staly (menu zostaje rozwiniete).
    if (autoBox) autoBox.addEventListener('change', function () { setMode(autoBox.checked ? 'auto' : ''); });
    // Tryb automatyczny: krotkie opoznienia, zeby menu nie migalo przy przejezdzaniu kursorem.
    side.addEventListener('mouseenter', function () { if (wide.matches) peek(true, 120); });
    side.addEventListener('mouseleave', function () {
      if (side.querySelector('.version[open]')) return; // otwarta lista zmian - nie chowaj
      peek(false, 350);
    });
    side.addEventListener('focusin', function () { if (wide.matches) peek(true); });
    side.addEventListener('focusout', function (e) { if (!side.contains(e.relatedTarget) && !side.matches(':hover')) peek(false, 150); });
  }
  // W pasku ikon klikniecie podmenu (Grupy, Ustawienia) rozwija menu i to podmenu (tez na ekranach dotykowych).
  document.querySelectorAll('.side .sub > summary').forEach(function (s) {
    s.addEventListener('click', function (e) {
      if (!wide.matches || !root.classList.contains('side-min') || root.classList.contains('side-peek')) return;
      e.preventDefault();
      s.parentNode.open = true;
      if (mode === 'auto') peek(true); else setMode('');
    });
  });
});
