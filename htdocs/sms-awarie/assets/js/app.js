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

  // 4.7.0: zwijanie menu bocznego na PC do paska ikon; stan zapamietany w przegladarce.
  var root = document.documentElement, toggle = document.querySelector('.side-toggle');
  var wide = window.matchMedia('(min-width: 1000px)');
  function railTips(on) {
    document.querySelectorAll('.side nav a, .side .sub > summary').forEach(function (el) {
      if (on) el.setAttribute('title', el.textContent.trim()); else el.removeAttribute('title');
    });
  }
  function setMin(min) {
    root.classList.toggle('side-min', min);
    if (toggle) {
      var t = min ? 'Rozwiń menu' : 'Zwiń menu';
      toggle.setAttribute('aria-expanded', min ? 'false' : 'true');
      toggle.setAttribute('aria-label', t);
      toggle.title = t;
    }
    railTips(min);
    try { localStorage.setItem('sms-awarie-side', min ? 'min' : ''); } catch (e) { /* tryb prywatny */ }
  }
  if (toggle) {
    setMin(root.classList.contains('side-min'));
    toggle.addEventListener('click', function () { setMin(!root.classList.contains('side-min')); });
  }
  // W pasku ikon klikniecie podmenu (Grupy, Ustawienia) rozwija menu i to podmenu.
  document.querySelectorAll('.side .sub > summary').forEach(function (s) {
    s.addEventListener('click', function (e) {
      if (!root.classList.contains('side-min') || !wide.matches) return;
      e.preventDefault();
      s.parentNode.open = true;
      setMin(false);
    });
  });
});
