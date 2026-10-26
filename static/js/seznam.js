/* popravimvse.si – »Sestavite svoj seznam popravil«: šteje izbrane stvari; obrazec (GET) jih pošlje na /povprasevanje/, kjer se vpišejo v opis. */
(function () {
  'use strict';
  var f = document.getElementById('ds-form'), out = document.getElementById('ds-count');
  if (!f || !out) return;
  function upd() {
    var n = f.querySelectorAll('input[name="s"]:checked').length;
    var extra = (f.elements.drugo.value || '').split('\n').filter(function (x) { return x.trim(); }).length;
    var t = n + extra;
    out.textContent = t === 0 ? 'Izberite stvari s seznama.'
      : 'Na seznamu: ' + t + (t === 1 ? ' stvar' : t === 2 ? ' stvari' : t < 5 ? ' stvari' : ' stvari') + '. Uredim jih v enem obisku.';
    f.classList.toggle('ds-has', t > 0);
  }
  f.addEventListener('change', upd);
  f.addEventListener('input', upd);
  upd();
})();
