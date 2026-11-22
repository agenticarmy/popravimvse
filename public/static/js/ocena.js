/* popravimvse.si – /ocena/: pomoč pri pisanju ocene na Googlu.
   Stranka izbere delo (pokažejo se vprašanja), oceno napiše ali pove na glas SAMA,
   gumb jo kopira in odpre Googlov obrazec. Besedilo ostane v brskalniku, na strežnik se ne pošlje. */
(function () {
  'use strict';
  var root = document.getElementById('oc');
  if (!root) return;
  var boxes = root.querySelectorAll('input[data-q]');
  var list = document.getElementById('oc-vprasanja');
  var ta = document.getElementById('oc-besedilo');
  var mic = document.getElementById('oc-mic');
  var stanje = document.getElementById('oc-stanje');
  var go = document.getElementById('oc-pojdi');
  var ok = document.getElementById('oc-ok');
  var items = list ? list.querySelectorAll('li') : [];
  var privzeto = items.length ? items[0].textContent : '';
  var splosna = [];
  for (var i = 1; i < items.length; i++) splosna.push(items[i].textContent);

  function vprasanja() {
    var qs = [];
    for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) qs.push(boxes[i].getAttribute('data-q'));
    if (!qs.length) qs.push(privzeto);
    list.innerHTML = '';
    qs.concat(splosna).forEach(function (q) {
      var li = document.createElement('li');
      li.textContent = q;
      list.appendChild(li);
    });
  }
  if (list) root.addEventListener('change', function (e) { if (e.target && e.target.hasAttribute('data-q')) vprasanja(); });

  /* narek: samo, če ga brskalnik podpira (Chrome, Safari); sicer ostane namig za mikrofon na tipkovnici */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SR && mic && ta) {
    var rec = null, base = '', on = false;
    var label = mic.querySelector('span');
    var napis = label.textContent;
    mic.hidden = false;
    stanje.textContent = 'Tapnite »Povejte na glas« in govorite. Ko utihnete, se zapisovanje ustavi. Za nadaljevanje tapnite znova.';
    var stik = function (a, b) { return a && b ? a.replace(/\s+$/, '') + ' ' + b.replace(/^\s+/, '') : a + b; };
    var veliko = function (t) { return t ? t.charAt(0).toUpperCase() + t.slice(1) : t; };
    var konec = function () {
      on = false;
      mic.classList.remove('oc-on');
      label.textContent = napis;
      mic.setAttribute('aria-pressed', 'false');
    };
    mic.setAttribute('aria-pressed', 'false');
    mic.addEventListener('click', function () {
      if (on && rec) { rec.stop(); return; }
      try {
        rec = new SR();
        rec.lang = 'sl-SI';
        rec.continuous = false; /* Chrome na Androidu pri continuous podvaja besede */
        rec.interimResults = true;
      } catch (e) { mic.hidden = true; return; }
      base = ta.value;
      rec.onresult = function (ev) {
        var t = '';
        for (var i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript;
        t = t.trim();
        ta.value = stik(base, base.trim() ? t : veliko(t));
      };
      rec.onerror = function (ev) {
        var err = ev && ev.error;
        if (err === 'not-allowed' || err === 'service-not-allowed') stanje.textContent = 'Mikrofon ni dovoljen. Dovolite ga v brskalniku ali na tipkovnici tapnite ikono mikrofona.';
        else if (err === 'language-not-supported') { stanje.textContent = 'Narek v slovenščini tu ne deluje. Na tipkovnici tapnite ikono mikrofona in govorite.'; mic.hidden = true; }
        else if (err === 'no-speech') stanje.textContent = 'Nič nisem slišal. Tapnite znova in govorite.';
        else stanje.textContent = 'Narek se je ustavil. Tapnite znova ali pišite.';
      };
      rec.onend = function () { konec(); base = ta.value; if (stanje.textContent.indexOf('Poslušam') === 0) stanje.textContent = 'Za nadaljevanje tapnite »Povejte na glas« znova.'; };
      try { rec.start(); } catch (e) { konec(); return; }
      on = true;
      mic.classList.add('oc-on');
      label.textContent = 'Ustavi';
      mic.setAttribute('aria-pressed', 'true');
      stanje.textContent = 'Poslušam … Govorite. Besedilo lahko nato popravite.';
    });
  }

  /* kopiraj in odpri: kopiranje mora steči takoj ob dotiku, povezava se nato odpre sama (target=_blank) */
  if (go && ta) go.addEventListener('click', function () {
    var t = ta.value.trim();
    if (!t) {
      ok.textContent = 'Odpiram Google. Oceno napišite ali povejte tam.';
      ok.hidden = false;
      return;
    }
    var kopirano = false;
    try {
      ta.focus();
      ta.select();
      ta.setSelectionRange(0, ta.value.length);
      kopirano = document.execCommand('copy');
    } catch (e) { kopirano = false; }
    try { ta.blur(); if (window.getSelection) window.getSelection().removeAllRanges(); } catch (e) {}
    if (navigator.clipboard && navigator.clipboard.writeText) {
      try { navigator.clipboard.writeText(t).catch(function () {}); kopirano = true; } catch (e) {}
    }
    ok.textContent = kopirano
      ? 'Besedilo je kopirano. V Googlu izberite zvezdice, tapnite v polje za besedilo, pridržite prst in izberite »Prilepi«.'
      : 'Kopiranje ni uspelo. Besedilo zgoraj označite, pridržite prst in izberite »Kopiraj«.';
    ok.hidden = false;
  });
})();
