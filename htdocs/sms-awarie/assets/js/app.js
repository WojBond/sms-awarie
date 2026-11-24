// SMS-Awarie - drobne zachowania panelu (bez bibliotek).
document.addEventListener('DOMContentLoaded', function () {
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
        .then(function (html) { live.innerHTML = html; })
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

  // Menu: na szerokim ekranie zawsze rozwiniete, na telefonie / Zebrze rozwijane przyciskiem.
  var menu = document.querySelector('details.menu');
  var wide = window.matchMedia('(min-width: 1000px)');
  function syncMenu() { if (menu) { if (wide.matches) menu.setAttribute('open', ''); else menu.removeAttribute('open'); } }
  syncMenu();
  if (wide.addEventListener) wide.addEventListener('change', syncMenu);
  document.addEventListener('click', function (e) {
    if (menu && !wide.matches && menu.open && !menu.contains(e.target)) menu.removeAttribute('open');
  });
});
