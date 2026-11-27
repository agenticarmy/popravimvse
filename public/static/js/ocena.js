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
  var box = document.getElementById('oc-pomoc');
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

  /* kopiranje mora steči takoj ob dotiku */
  function kopiraj(t) {
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
    return kopirano;
  }

  /* brskalnik: navodila za dovoljenje so v vsakem drugačna; spletna stran nastavitev telefona ne more odpreti */
  var ua = navigator.userAgent || '';
  var android = /Android/i.test(ua);
  var ios = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  var samsung = /SamsungBrowser/i.test(ua);
  var vgrajen = (android && /; wv\)/.test(ua)) || /FBAN|FBAV|FB_IAB|Instagram|Viber|Line\/|MicroMessenger|Snapchat|musical_ly|TikTok/i.test(ua) || (ios && !/Safari\//.test(ua));
  var chrome = android && !samsung && !vgrajen && /Chrome\//.test(ua) && !/EdgA|OPR|YaBrowser|Firefox|DuckDuckGo|MiuiBrowser|HuaweiBrowser|HeyTapBrowser|Vivaldi/i.test(ua);
  var safari = ios && !vgrajen && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
  var ime = vgrajen ? 'aplikacija' : chrome ? 'Chrome' : samsung ? 'Samsung Internet' : safari ? 'Safari' : 'brskalnik';
  /* Chrome odpre samo povezave, ki jih aplikacija dovoli iz brskalnika; Chrome sam to dovoli, nastavitve telefona ne */
  var vChrome = android && !chrome
    ? 'intent://' + location.host + location.pathname + '#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=' +
      encodeURIComponent('https://play.google.com/store/apps/details?id=com.android.chrome') + ';end'
    : '';

  function korakiDovoljenje() {
    if (chrome) return ['Zgoraj tapnite ikono levo od naslova <b>popravimvse.si</b>.', 'Tapnite <b>Dovoljenja</b> in vklopite <b>Mikrofon</b>.', 'Tapnite <b>Poskusi znova</b> spodaj.'];
    if (samsung) return ['Zgoraj tapnite ključavnico levo od naslova <b>popravimvse.si</b>.', 'Pri <b>Mikrofon</b> izberite <b>Dovoli</b>.', 'Tapnite <b>Poskusi znova</b> spodaj.'];
    if (safari) return ['V vrstici z naslovom tapnite ikono levo od naslova (<b>Aa</b>).', 'Tapnite <b>Nastavitve spletnega mesta</b>.', 'Pri <b>Mikrofon</b> izberite <b>Dovoli</b>, nato tapnite <b>Poskusi znova</b> spodaj.'];
    return ['V nastavitvah brskalnika dovolite mikrofon za stran <b>popravimvse.si</b>.', 'Tapnite <b>Poskusi znova</b> spodaj.'];
  }

  function skrij() {
    if (!box) return;
    box.hidden = true;
    box.removeAttribute('data-vrsta');
    if (stanje) stanje.hidden = false;
  }

  /* opazno obvestilo s koraki; vrsta: dovoljenje | mikrofon | omrezje | storitev | vgrajen */
  function pokazi(vrsta, koda, premakni) {
    if (!box) return;
    var naslov = '', opis = '', koraki = [], dodatno = '';
    var znova = mic && !mic.hidden;
    if (vrsta === 'dovoljenje') {
      naslov = 'Mikrofon za to stran ni dovoljen';
      koraki = korakiDovoljenje();
      if (android) dodatno = 'Če mikrofona tam ni ali narek še vedno ne dela: odprite <b>Nastavitve</b> telefona → <b>Aplikacije</b> → <b>' + (chrome || samsung ? ime : 'vaš brskalnik') + '</b> → <b>Dovoljenja</b> → <b>Mikrofon</b> → <b>Dovoli samo med uporabo aplikacije</b>.';
      else if (safari) dodatno = 'Če še vedno ne dela: <b>Nastavitve</b> telefona → <b>Safari</b> → <b>Mikrofon</b> → <b>Dovoli</b>.';
    } else if (vrsta === 'mikrofon') {
      naslov = 'Telefon ne more uporabiti mikrofona';
      opis = 'Morda ga uporablja druga aplikacija (klic, snemanje, brezžični mikrofon). Zaprite jo in tapnite <b>Poskusi znova</b>.';
    } else if (vrsta === 'omrezje') {
      naslov = 'Narek potrebuje internet';
      opis = 'Povezava se je prekinila. Preverite mobilne podatke ali Wi-Fi in tapnite <b>Poskusi znova</b>.';
    } else if (vrsta === 'storitev') {
      naslov = 'Narek v tem brskalniku ne deluje';
      opis = vChrome ? 'Odprite stran v Chromu, tam narek navadno deluje.'
        : ios && !safari ? 'Odprite stran v Safariju.'
        : ios ? 'Na iPhonu mora biti vklopljen narek: <b>Nastavitve</b> → <b>Splošno</b> → <b>Tipkovnica</b> → <b>Narekovanje</b>.'
        : 'Tapnite <b>Poskusi znova</b>. Če ne gre, uporabite mikrofon na tipkovnici.';
      if (koda === 'language-not-supported') znova = false;
    } else if (vrsta === 'vgrajen') {
      naslov = 'Narek tu ne deluje';
      opis = 'Stran je odprta znotraj aplikacije (na primer Viber, Messenger ali Gmail). ' +
        (android ? 'Odprite jo v Chromu.' : 'Tapnite gumb za brskalnik (⋯ ali kompas) in izberite <b>Odpri v Safariju</b>.');
      znova = false;
    }
    var h = '<p class="oc-pomoc-h"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>' + naslov + '</p>';
    if (opis) h += '<p>' + opis + '</p>';
    if (koraki.length) h += '<ol><li>' + koraki.join('</li><li>') + '</li></ol>';
    if (dodatno) h += '<p class="oc-pomoc-dod">' + dodatno + '</p>';
    var g = '';
    if (znova) g += '<button type="button" class="call oc-znova">Poskusi znova</button>';
    if (vChrome) g += '<a class="btn-plain oc-chrome" href="' + vChrome + '">Odpri v Chromu</a>';
    if (g) h += '<div class="oc-pomoc-g">' + g + '</div>';
    h += '<p class="oc-pomoc-alt">Ali pa: tapnite v polje <b>Vaša ocena</b> in na tipkovnici tapnite ikono mikrofona' +
      (android ? ' (pri tipkovnici Samsung je navadno v vrstici nad črkami)' : '') + '. Oceno lahko tudi napišete.</p>';
    h += '<p class="oc-pomoc-koda">Koda: ' + koda + ' · ' + ime + '</p>';
    box.innerHTML = h;
    box.setAttribute('data-vrsta', vrsta);
    box.hidden = false;
    if (stanje) stanje.hidden = true;
    if (premakni) {
      try { box.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { box.scrollIntoView(); }
      try { box.focus({ preventScroll: true }); } catch (e) {}
    }
  }

  if (box) box.addEventListener('click', function (e) {
    var t = e.target;
    if (t && t.closest && t.closest('.oc-znova') && mic) { skrij(); mic.click(); return; }
    if (t && t.closest && t.closest('.oc-chrome') && ta && ta.value.trim()) kopiraj(ta.value.trim());
  });

  /* narek: samo, če ga brskalnik podpira (Chrome, Samsung Internet, Safari); sicer ostane namig za mikrofon na tipkovnici */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (vgrajen) {
    pokazi('vgrajen', 'aplikacija', false);
  } else if (SR && mic && ta) {
    var rec = null, base = '', on = false, napaka = false;
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

    /* dovoljenje preveri že ob odprtju: če je mikrofon zavrnjen, so koraki vidni, preden stranka tapne */
    if (navigator.permissions && navigator.permissions.query) {
      try {
        navigator.permissions.query({ name: 'microphone' }).then(function (st) {
          var preveri = function () {
            if (st.state === 'denied') pokazi('dovoljenje', 'denied', false);
            else if (box && box.getAttribute('data-vrsta') === 'dovoljenje') {
              skrij();
              stanje.textContent = 'Mikrofon je dovoljen. Tapnite »Povejte na glas« in govorite.';
            }
          };
          preveri();
          st.onchange = preveri;
        }, function () {});
      } catch (e) {}
    }

    mic.addEventListener('click', function () {
      if (on && rec) { rec.stop(); return; }
      try {
        rec = new SR();
        rec.lang = 'sl-SI';
        rec.continuous = false; /* Chrome na Androidu pri continuous podvaja besede */
        rec.interimResults = true;
      } catch (e) { mic.hidden = true; pokazi('storitev', 'ni-podpore', true); return; }
      base = ta.value;
      napaka = false;
      rec.onstart = function () { skrij(); };
      rec.onresult = function (ev) {
        var t = '';
        for (var i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript;
        t = t.trim();
        ta.value = stik(base, base.trim() ? t : veliko(t));
      };
      rec.onerror = function (ev) {
        var err = (ev && ev.error) || 'neznano';
        napaka = true;
        if (err === 'no-speech') { stanje.textContent = 'Nič nisem slišal. Tapnite znova in govorite.'; return; }
        if (err === 'aborted') { stanje.textContent = 'Narek se je ustavil. Tapnite znova ali pišite.'; return; }
        if (err === 'not-allowed') pokazi('dovoljenje', err, true);
        else if (err === 'audio-capture') pokazi('mikrofon', err, true);
        else if (err === 'network') pokazi('omrezje', err, true);
        else {
          /* service-not-allowed, language-not-supported, …: storitev za govor ni na voljo, mikrofon ni kriv */
          if (err === 'language-not-supported') mic.hidden = true;
          pokazi('storitev', err, true);
        }
      };
      rec.onend = function () {
        konec();
        base = ta.value;
        if (!napaka) stanje.textContent = 'Za nadaljevanje tapnite »Povejte na glas« znova.';
      };
      try { rec.start(); } catch (e) { konec(); pokazi('storitev', 'start', true); return; }
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
    ok.textContent = kopiraj(t)
      ? 'Besedilo je kopirano. V Googlu izberite zvezdice, tapnite v polje za besedilo, pridržite prst in izberite »Prilepi«.'
      : 'Kopiranje ni uspelo. Besedilo zgoraj označite, pridržite prst in izberite »Kopiraj«.';
    ok.hidden = false;
  });
})();
