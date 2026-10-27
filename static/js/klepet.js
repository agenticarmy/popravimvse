/* Pomočnik »Poskusite sami« na vsaki strani popravimvse.si.
 * Pogovor hrani brskalnik (sessionStorage, samo ta zavihek); strežnik ga shrani le, če obiskovalec to dovoli. */
(function () {
  'use strict';
  var API = '/api/klepet.php';
  var KEY = 'pv-klepet';
  var FORM = '/povprasevanje/';
  if (location.pathname.indexOf(FORM) === 0) return; // na obrazcu je stik že tu
  var me = document.currentScript;
  var cssHref = me && me.getAttribute('data-css');

  var st = load();
  var maxLen = 600, panel, log, ta, sendBtn, consent, busy = false, opener = null;

  function load() {
    try { var s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (s && s.hist) return s; } catch (e) {}
    return { id: '', t: '', hist: [], soglasje: false, predaja: '' };
  }
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  var ICON = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/><path d="M8.5 10.5h7M8.5 13.5h4.5"/></svg>';

  if (cssHref) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = cssHref; document.head.appendChild(l); }
  var fab = el('button', 'kl-fab');
  fab.type = 'button';
  fab.innerHTML = ICON + '<span>Poskusite sami</span>';
  fab.setAttribute('aria-haspopup', 'dialog');
  fab.setAttribute('aria-label', 'Poskusite sami: vprašajte pomočnika, kako popraviti sami');
  fab.addEventListener('click', open);
  document.body.appendChild(fab);

  /* ---------- prikaz besedila odgovora (brez innerHTML) ---------- */
  var SITE_RE = /(https:\/\/popravimvse\.si\/[^\s)<>"',;]*[^\s)<>"',;.!?:]|070 755 355|\*\*[^*\n]+\*\*)/g;
  function inline(parent, text) {
    var last = 0, m;
    SITE_RE.lastIndex = 0;
    while ((m = SITE_RE.exec(text))) {
      if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
      var t = m[0], a;
      if (t.indexOf('**') === 0) parent.appendChild(el('strong', '', t.slice(2, -2)));
      else if (t === '070 755 355') { a = el('a', '', t); a.href = 'tel:+38670755355'; parent.appendChild(a); }
      else {
        var path = t.replace('https://popravimvse.si', '') || '/';
        a = el('a', '', path === '/' ? 'popravimvse.si' : path.replace(/\/$/, '').replace(/^\//, ''));
        a.href = path; parent.appendChild(a);
      }
      last = m.index + t.length;
    }
    if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
  }
  function render(box, text) {
    var list = null;
    text.split(/\n/).forEach(function (line) {
      var s = line.trim(), mo = s.match(/^(\d+)[.)]\s+(.*)$/), mu = s.match(/^[-•*]\s+(.*)$/);
      if (!s) { list = null; return; }
      if (mo || mu) {
        var tag = mo ? 'OL' : 'UL';
        if (!list || list.tagName !== tag) {
          list = el(tag.toLowerCase());
          if (mo && mo[1] !== '1') list.start = +mo[1];
          box.appendChild(list);
        }
        var li = el('li'); inline(li, mo ? mo[2] : mu[1]); list.appendChild(li);
      } else {
        list = null;
        var p = el('p'); inline(p, s.replace(/^#+\s*/, '')); box.appendChild(p);
      }
    });
  }

  /* ---------- okno ---------- */
  function build() {
    panel = el('div', 'kl-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'kl-title');
    panel.hidden = true;
    var head = el('div', 'kl-head');
    var h = el('h2'); h.id = 'kl-title'; h.textContent = 'Poskusite sami';
    h.appendChild(el('small', '', 'Pomočnik z umetno inteligenco'));
    var nov = el('button', '', 'Nov pogovor'); nov.type = 'button'; nov.addEventListener('click', reset);
    var x = el('button', 'kl-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Zapri'); x.addEventListener('click', close);
    head.appendChild(h); head.appendChild(nov); head.appendChild(x);

    log = el('div', 'kl-log');
    log.setAttribute('aria-live', 'polite');

    var foot = el('div', 'kl-foot');
    var f1 = el('span', '', 'UI lahko zgreši. Za izvedbo odgovarjate sami.');
    var f2 = el('a', '', 'Pošljite video Sebastjanu'); f2.href = FORM;
    f2.addEventListener('click', function () { handoff(st.predaja); });
    foot.appendChild(f1); foot.appendChild(f2);

    var form = el('form', 'kl-form');
    ta = el('textarea');
    ta.rows = 1; ta.maxLength = maxLen; ta.placeholder = 'Opišite, kaj je narobe';
    ta.setAttribute('aria-label', 'Vaše vprašanje');
    ta.addEventListener('input', grow);
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.matchMedia('(min-width: 641px)').matches) { e.preventDefault(); send(); }
    });
    sendBtn = el('button', 'kl-btn', 'Pošlji'); sendBtn.type = 'submit';
    form.appendChild(ta); form.appendChild(sendBtn);
    form.addEventListener('submit', function (e) { e.preventDefault(); send(); });

    panel.appendChild(head); panel.appendChild(log); panel.appendChild(foot); panel.appendChild(form);
    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab') return;
      var f = panel.querySelectorAll('button, a[href], textarea, input');
      var first = f[0], lastEl = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); lastEl.focus(); }
      else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); first.focus(); }
    });
    document.body.appendChild(panel);
    draw();
  }

  function intro() {
    var box = el('div', 'kl-intro');
    var q = el('p', 'kl-quote', '»Najprej poskusiva, ali lahko popravite sami. Če ne gre, sem tu jaz.« – Sebastjan');
    var p = el('p', '', 'Opišite, kaj je narobe in kakšno orodje imate. Pomočnik vam svetuje najcenejšo pot, da težavo odpravite sami, in vam odkrito pove, kdaj je bolje poklicati mojstra.');
    var s = el('p', 'kl-small', 'Odgovarja umetna inteligenca (Claude), ne Sebastjan osebno. Pri elektriki, vodi pod tlakom in plinu vedno pokličite usposobljenega obrtnika. Ne pišite osebnih podatkov. ');
    var a = el('a', '', 'Zasebnost'); a.href = '/zasebnost/#pomocnik'; s.appendChild(a);
    var lab = el('label', 'kl-consent');
    consent = el('input'); consent.type = 'checkbox'; consent.checked = !!st.soglasje;
    consent.addEventListener('change', function () { st.soglasje = consent.checked; save(); });
    lab.appendChild(consent);
    lab.appendChild(el('span', '', 'Dovolim, da se pogovor (brez imena in naslova) shrani za 90 dni, da Sebastjan vidi, kaj ljudi zanima, in izboljša nasvete. Neobvezno.'));
    box.appendChild(q); box.appendChild(p); box.appendChild(s); box.appendChild(lab);
    return box;
  }

  function draw() {
    log.textContent = '';
    log.appendChild(intro());
    st.hist.forEach(function (m) { bubble(m.vloga, m.besedilo); });
    if (st.predaja) handCard(st.predaja);
    log.scrollTop = log.scrollHeight;
  }

  function bubble(who, text, cls) {
    var b = el('div', 'kl-msg ' + (cls || (who === 'obiskovalec' ? 'kl-me' : 'kl-bot')));
    if (who === 'obiskovalec' || cls) b.textContent = text; else render(b, text);
    log.appendChild(b);
    log.scrollTop = log.scrollHeight;
    return b;
  }

  function handCard(summary) {
    var old = log.querySelector('.kl-hand'); if (old) old.remove();
    var c = el('div', 'kl-msg kl-hand');
    c.appendChild(el('p', '', 'To je bolje prepustiti Sebastjanu. Pošljite mu kratek video, ocena je brezplačna.'));
    var a = el('a', 'kl-btn', 'Pošljite video Sebastjanu'); a.href = FORM;
    a.addEventListener('click', function () { handoff(summary); });
    c.appendChild(a);
    log.appendChild(c);
    log.scrollTop = log.scrollHeight;
  }

  function handoff(summary) {
    try { if (summary) sessionStorage.setItem('pv-klepet-povzetek', summary); } catch (e) {}
  }

  function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 4, 128) + 'px'; }

  function open() {
    if (!panel) build();
    opener = document.activeElement;
    panel.hidden = false; fab.hidden = true;
    document.body.classList.add('kl-open');
    setTimeout(function () { ta.focus(); }, 30);
    if (!st.hist.length && !open.checked) {
      open.checked = true;
      fetch(API, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (i) {
        if (i.najvec_znakov) { maxLen = i.najvec_znakov; ta.maxLength = maxLen; }
        if (!i.deluje) unavailable('Pomočnik je ta trenutek nedosegljiv.');
      }).catch(function () {});
    }
  }
  function close() {
    panel.hidden = true; fab.hidden = false;
    document.body.classList.remove('kl-open');
    (opener && opener.focus ? opener : fab).focus();
  }
  function reset() {
    st = { id: '', t: '', hist: [], soglasje: consent ? consent.checked : false, predaja: '' };
    save(); draw(); ta.value = ''; ta.disabled = false; sendBtn.disabled = false; ta.focus();
  }
  function unavailable(msg) {
    var b = bubble('pomocnik', msg + ' Pošljite video Sebastjanu ali ga pokličite na 070 755 355.', 'kl-err');
    b.textContent = ''; render(b, msg + ' Pošljite video Sebastjanu ali ga pokličite na 070 755 355.');
    var a = el('a', 'kl-btn plain', 'Pošljite video'); a.href = FORM; a.style.marginTop = '.5rem'; b.appendChild(a);
  }

  function send() {
    var text = ta.value.trim();
    if (!text || busy) return;
    if (text.length > maxLen) { bubble('', 'Sporočilo je predolgo (največ ' + maxLen + ' znakov).', 'kl-err'); return; }
    busy = true; sendBtn.disabled = true;
    bubble('obiskovalec', text);
    ta.value = ''; grow();
    var typing = bubble('', 'Pomočnik piše …', 'kl-typing');
    fetch(API, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ id: st.id, t: st.t, zgodovina: st.hist, sporocilo: text, soglasje: !!st.soglasje, stran: location.pathname })
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); }).then(function (j) {
      typing.remove();
      if (!j.ok) {
        if (j.nov) { st = { id: '', t: '', hist: [], soglasje: st.soglasje, predaja: '' }; save(); }
        unavailable(j.napaka || 'Pomočnik ni odgovoril.');
        if (!j.nov && !j.konec) ta.value = text;
        return;
      }
      st.id = j.id; st.t = j.t;
      st.hist.push({ vloga: 'obiskovalec', besedilo: text });
      st.hist.push({ vloga: 'pomocnik', besedilo: j.odgovor, podpis: j.podpis });
      if (j.predaja) st.predaja = j.predaja;
      save();
      bubble('pomocnik', j.odgovor);
      if (j.predaja) handCard(j.predaja);
      if (j.ostalo <= 2) bubble('', j.ostalo > 0 ? 'Še ' + j.ostalo + (j.ostalo === 1 ? ' vprašanje' : ' vprašanji') + ' v tem pogovoru.' : 'To je bilo zadnje vprašanje v tem pogovoru.', 'kl-typing');
    }).catch(function () {
      typing.remove();
      unavailable('Povezava ni uspela. Preverite internet in poskusite znova.');
      ta.value = text;
    }).then(function () { busy = false; sendBtn.disabled = false; });
  }
})();
