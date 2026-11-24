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
});
