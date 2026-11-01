/* Privolitev za piškotke in Google Analytics 4 (Consent Mode v2, osnovni način).
 * Google Analytics se naloži ŠELE, ko obiskovalec klikne »Dovolim«. Do takrat stran Googlu ne pošlje ničesar.
 * Oglaševalski signali (ad_storage, ad_user_data, ad_personalization) so vedno zavrnjeni – stran ne oglašuje.
 * Izbira se hrani v brskalniku (localStorage »pv-privolitev«) 12 mesecev, nato vprašamo znova.
 * ID merjenja: atribut data-ga na <script>. Brez ID se ne zgodi nič. */
(function () {
  var me = document.currentScript;
  var GA = me && me.getAttribute('data-ga');
  if (!GA) return;
  var KEY = 'pv-privolitev', VER = 1, MAX = 365 * 864e5;
  var loaded = false, bar = null;

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = window.gtag || gtag;
  gtag('consent', 'default', {
    analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
    functionality_storage: 'granted', security_storage: 'granted'
  });

  function read() {
    try {
      var s = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (s && s.v === VER && Date.now() - new Date(s.cas).getTime() < MAX) return s;
    } catch (e) {}
    return null;
  }
  function save(ok) {
    try { localStorage.setItem(KEY, JSON.stringify({ v: VER, analitika: !!ok, cas: new Date().toISOString() })); } catch (e) {}
  }

  function load() {
    if (loaded) return;
    loaded = true;
    gtag('consent', 'update', { analytics_storage: 'granted' });
    gtag('js', new Date());
    gtag('config', GA, {
      allow_google_signals: false, allow_ad_personalization_signals: false,
      cookie_expires: 395 * 86400, cookie_flags: 'SameSite=Lax;Secure'
    });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA);
    document.head.appendChild(s);
  }

  function dropCookies() {
    var host = location.hostname, parts = host.split('.');
    var doms = ['', host, '.' + host];
    if (parts.length > 2) doms.push('.' + parts.slice(-2).join('.'));
    document.cookie.split(';').forEach(function (c) {
      var n = c.split('=')[0].trim();
      if (n === '_ga' || n.indexOf('_ga_') === 0 || n === '_gid' || n === '_gat') {
        doms.forEach(function (d) {
          document.cookie = n + '=; Max-Age=0; path=/' + (d ? '; domain=' + d : '');
        });
      }
    });
  }

  function choose(ok) {
    var was = (read() || {}).analitika;
    save(ok);
    hide();
    if (ok) return load();
    gtag('consent', 'update', { analytics_storage: 'denied' });
    window['ga-disable-' + GA] = true;
    dropCookies();
    if (was && loaded) location.reload();
  }

  function hide() { if (bar) { bar.remove(); bar = null; } }

  function show() {
    if (bar) return;
    bar = document.createElement('section');
    bar.className = 'cookie-bar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Piškotki');
    bar.innerHTML =
      '<div class="cookie-in"><p><strong>Ali smem meriti obiske?</strong> Z Google Analytics bi videl, katere strani ' +
      'ljudje berejo in kaj iščejo, da stran izboljšam. Brez oglasov in brez prodaje podatkov. ' +
      '<a href="/piskotki/">Več o piškotkih</a></p>' +
      '<div class="cookie-btns"><button type="button" class="cookie-no">Ne dovolim</button>' +
      '<button type="button" class="cookie-yes">Dovolim</button></div></div>';
    bar.querySelector('.cookie-no').addEventListener('click', function () { choose(false); });
    bar.querySelector('.cookie-yes').addEventListener('click', function () { choose(true); });
    document.body.appendChild(bar);
  }

  /* Dogodki: šteje se samo, če je obiskovalec dovolil merjenje. cb se vedno pokliče (največ po 800 ms). */
  window.pvDogodek = function (name, params, cb) {
    var done = false, fin = function () { if (!done) { done = true; if (cb) cb(); } };
    if (!loaded) return fin();
    var p = params || {};
    p.transport_type = 'beacon';
    p.event_callback = fin;
    gtag('event', name, p);
    setTimeout(fin, 800);
  };
  window.pvPrivolitev = function () { hide(); show(); };

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('a, button') : null;
    if (!t) return;
    if (t.hasAttribute('data-privolitev')) { e.preventDefault(); return window.pvPrivolitev(); }
    if (!loaded) return;
    var h = t.getAttribute('href') || '';
    if (h.indexOf('tel:') === 0) window.pvDogodek('klic', { mesto: t.closest('.callbar') ? 'spodnja_vrstica' : 'stran' });
    else if (h.indexOf('sms:') === 0) window.pvDogodek('sms');
    else if (h.indexOf('mailto:') === 0) window.pvDogodek('email');
    else if (t.classList.contains('kl-fab')) window.pvDogodek('odpri_pomocnika');
  });

  function init() {
    var s = read();
    if (s && s.analitika) load();
    else if (!s) show();
    var stat = document.getElementById('privolitev-stanje');
    if (stat) stat.textContent = !s ? 'še niste izbrali' : s.analitika ? 'merjenje obiskov ste dovolili' : 'merjenje obiskov ste zavrnili';
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
