/* popravimvse.si – obrazec »Pokažite mi težavo«
 * Fotografije se pred pošiljanjem pomanjšajo (in izgubijo podatke o lokaciji), videi in glas se nalagajo po kosih že med
 * izpolnjevanjem, z nadaljevanjem po prekinitvi. Brez JavaScripta obrazec deluje kot navaden obrazec.
 */
(function () {
  'use strict';
  var form = document.getElementById('pv-form');
  if (!form || !window.FormData || !window.XMLHttpRequest || !window.Promise) return;

  var API = '/api/nalozi.php';
  var CHUNK = 2 * 1024 * 1024, MAX_FILE = 250 * 1024 * 1024, MAX_FILES = 12;
  var MAX_REC = 180;
  var $ = function (id) { return document.getElementById(id); };
  var thumbs = $('pv-thumbs'), input = $('pv-media'), err = $('pv-err'), sendBtn = $('pv-send');
  var items = [], nextIdx = 0, chain = Promise.resolve(), submitting = false;

  function rid() {
    var a = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  var uid = rid();
  form.elements.js.value = '1';
  form.elements.upload_id.value = uid;
  form.classList.add('pv-js');
  form.noValidate = true; // lastna, prijaznejša sporočila namesto brskalnikovih

  fetch(API + '?info=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (i) {
    if (i.chunk) CHUNK = i.chunk;
    if (i.max_file) MAX_FILE = i.max_file;
    if (i.max_files) MAX_FILES = i.max_files;
  }).catch(function () {});

  function mb(b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' kB'; }
  function showErr(el, msg) { el.textContent = msg; el.hidden = !msg; }
  function active() { return items.filter(function (it) { return !it.removed; }); }
  function visible() { return active().filter(function (it) { return it.kind !== 'frame'; }); }

  /* ---------- fotografije: pomanjšaj na 2000 px, JPEG (odstrani EXIF in GPS) ---------- */
  function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 700 * 1024) return Promise.resolve(file);
    var load = window.createImageBitmap
      ? createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return createImageBitmap(file); })
      : new Promise(function (ok, no) { var im = new Image(); im.onload = function () { ok(im); }; im.onerror = no; im.src = URL.createObjectURL(file); });
    return load.then(function (img) {
      var w = img.width, h = img.height, s = Math.min(1, 2000 / Math.max(w, h));
      var c = document.createElement('canvas');
      c.width = Math.round(w * s); c.height = Math.round(h * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      return new Promise(function (ok) {
        c.toBlob(function (b) {
          if (!b || b.size >= file.size) return ok(file);
          ok(new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.82);
      });
    }).catch(function () { return file; });
  }

  /* ---------- predogled ---------- */
  function addItem(file, kind) {
    if (kind !== 'frame' && visible().length >= MAX_FILES) { showErr(err, 'Dodate lahko največ ' + MAX_FILES + ' datotek.'); return; }
    if (file.size > MAX_FILE) { showErr(err, '»' + file.name + '« je prevelik (' + mb(file.size) + '). Posnemite krajši video, do ' + mb(MAX_FILE) + '.'); return; }
    var it = { idx: nextIdx++, file: file, kind: kind, sent: 0, done: false, error: '', removed: false, frames: [] };
    items.push(it);
    if (kind === 'frame') { // sličica iz videa: samo za oceno, brez predogleda
      it.promise = chain = chain.then(function () { return upload(it); });
      return it;
    }
    showErr(err, '');
    var li = document.createElement('li');
    li.className = 'pv-thumb pv-' + kind;
    var url = URL.createObjectURL(file), media;
    if (kind === 'image') { media = document.createElement('img'); media.src = url; media.alt = 'Izbrana fotografija'; }
    else if (kind === 'video') { media = document.createElement('video'); media.src = url + '#t=0.1'; media.muted = true; media.playsInline = true; media.preload = 'metadata'; media.setAttribute('aria-label', 'Izbran video'); }
    else { media = document.createElement('audio'); media.src = url; media.controls = true; media.preload = 'metadata'; }
    li.appendChild(media);
    var tag = document.createElement('span'); tag.className = 'pv-tag';
    tag.textContent = kind === 'video' ? 'Video · ' + mb(file.size) : kind === 'audio' ? 'Glasovno sporočilo' : 'Fotografija';
    li.appendChild(tag);
    var bar = document.createElement('span'); bar.className = 'pv-mini'; bar.innerHTML = '<i></i>';
    li.appendChild(bar);
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'pv-rm'; rm.setAttribute('aria-label', 'Odstrani'); rm.textContent = '×';
    rm.addEventListener('click', function () { it.removed = true; it.frames.forEach(function (f) { f.removed = true; }); li.remove(); URL.revokeObjectURL(url); syncRemoved(); });
    li.appendChild(rm);
    thumbs.appendChild(li);
    it.li = li; it.bar = bar.firstChild;
    it.promise = chain = chain.then(function () { return upload(it); });
    return it;
  }
  function syncRemoved() {
    form.elements.odstrani.value = items.filter(function (i) { return i.removed; }).map(function (i) { return i.idx; }).join(',');
  }

  /* ---------- nalaganje po kosih, z nadaljevanjem ---------- */
  function sendChunk(it, offset) {
    return new Promise(function (ok, no) {
      var end = Math.min(offset + CHUNK, it.file.size);
      var fd = new FormData();
      fd.append('upload_id', uid); fd.append('idx', it.idx); fd.append('offset', offset);
      fd.append('size', it.file.size); fd.append('name', it.file.name); fd.append('type', it.file.type);
      fd.append('chunk', it.file.slice(offset, end), 'kos');
      var x = new XMLHttpRequest();
      x.open('POST', API);
      x.timeout = 120000;
      x.upload.onprogress = function (e) { if (e.lengthComputable) progress(it, offset + e.loaded); };
      x.onload = function () {
        var j = {}; try { j = JSON.parse(x.responseText); } catch (e) {}
        if (x.status === 200) return ok(j.received);
        if (x.status === 409 && typeof j.received === 'number') return ok(j.received);
        var e2 = new Error(j.napaka || 'Napaka pri nalaganju.'); e2.fatal = x.status >= 400 && x.status < 500 && x.status !== 429; no(e2);
      };
      x.onerror = x.ontimeout = function () { no(new Error('Povezava se je prekinila.')); };
      x.send(fd);
    });
  }
  function upload(it) {
    if (it.removed) return Promise.resolve();
    var tries = 0;
    function step(offset) {
      if (it.removed) return Promise.resolve();
      if (offset >= it.file.size) { it.done = true; if (it.li) it.li.classList.add('ok'); progress(it, it.file.size); return Promise.resolve(); }
      return sendChunk(it, offset).then(function (rec) { tries = 0; it.sent = rec; return step(rec); }, function (e) {
        if (e.fatal || ++tries > 6) { it.error = e.message; if (it.li) it.li.classList.add('bad'); throw e; }
        return new Promise(function (r) { setTimeout(r, Math.min(15000, 1000 * Math.pow(2, tries))); }).then(function () { return step(it.sent); });
      });
    }
    return step(0).catch(function (e) { if (it.kind !== 'frame') showErr(err, e.message); });
  }
  function progress(it, sent) {
    it.sent = Math.max(it.sent, Math.min(sent, it.file.size));
    if (it.bar) it.bar.style.width = Math.round(100 * it.sent / it.file.size) + '%';
    if (submitting) overall();
  }
  function overall() {
    var a = active(), tot = 0, sent = 0;
    a.forEach(function (i) { tot += i.file.size; sent += i.done ? i.file.size : i.sent; });
    var p = tot ? Math.round(100 * sent / tot) : 100;
    $('pv-bar').style.width = p + '%';
    $('pv-progress-text').textContent = p < 100 ? 'Nalagam fotografije in posnetke … ' + p + ' %. Ne zapirajte strani.' : 'Pošiljam …';
  }

  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files || []);
    input.value = '';
    files.forEach(function (f) {
      var kind = /^video\//.test(f.type) ? 'video' : /^image\//.test(f.type) ? 'image' : null;
      if (!kind) { showErr(err, '»' + f.name + '« ni fotografija ali video.'); return; }
      if (kind === 'image') shrink(f).then(function (s) { addItem(s, 'image'); });
      else { var v = addItem(f, kind); if (v) frames(f, v); }
    });
  });
  /* ---------- 4 sličice iz videa (za oceno, kadar Sebastjan videa ne more pogledati takoj) ---------- */
  function frames(file, owner) {
    var v = document.createElement('video'), url = URL.createObjectURL(file), n = 0, times = [];
    v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
    var done = function () { URL.revokeObjectURL(url); v.removeAttribute('src'); };
    var timer = setTimeout(done, 20000);
    v.addEventListener('loadedmetadata', function () {
      var d = v.duration;
      if (!isFinite(d) || d <= 0) { clearTimeout(timer); return done(); }
      times = [0.12, 0.38, 0.63, 0.88].map(function (x) { return x * d; });
      v.currentTime = times[0];
    });
    v.addEventListener('seeked', function () {
      if (owner.removed || submitting) { clearTimeout(timer); return done(); }
      var s = Math.min(1, 1024 / Math.max(v.videoWidth, v.videoHeight || 1)), c = document.createElement('canvas');
      c.width = Math.round(v.videoWidth * s); c.height = Math.round(v.videoHeight * s);
      if (!c.width || !c.height) { clearTimeout(timer); return done(); }
      c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
      var i = n;
      c.toBlob(function (b) {
        if (b && !owner.removed && !submitting) owner.frames.push(addItem(new File([b], 'slicica-' + owner.idx + '-' + (i + 1) + '.jpg', { type: 'image/jpeg' }), 'frame'));
      }, 'image/jpeg', 0.75);
      n++;
      if (n < times.length) v.currentTime = times[n]; else { clearTimeout(timer); done(); }
    });
    v.addEventListener('error', function () { clearTimeout(timer); done(); });
  }

  // brez JS ostane polje media[] v obrazcu; z JS datoteke pošiljamo po kosih
  input.removeAttribute('name');

  /* ---------- glasovno sporočilo ---------- */
  var voice = $('pv-voice'), recBtn = $('pv-rec'), rec = null, recT = 0, timer = null, chunks = [], stream = null;
  if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder) {
    voice.hidden = false;
    recBtn.addEventListener('click', function () { rec && rec.state === 'recording' ? rec.stop() : startRec(); });
  }
  function pickType() {
    var t = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/webm', 'audio/ogg;codecs=opus'];
    for (var i = 0; i < t.length; i++) if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t[i])) return t[i];
    return '';
  }
  function setRec(label, sub, on) {
    $('pv-rec-label').textContent = label; $('pv-rec-sub').textContent = sub;
    recBtn.classList.toggle('on', !!on);
    recBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
  }
  function startRec() {
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
      stream = s; chunks = [];
      var type = pickType();
      rec = type ? new MediaRecorder(s, { mimeType: type }) : new MediaRecorder(s);
      rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      rec.onstop = function () {
        clearInterval(timer);
        stream.getTracks().forEach(function (t) { t.stop(); });
        var mt = (rec.mimeType || type || 'audio/webm').split(';')[0];
        var blob = new Blob(chunks, { type: mt });
        if (blob.size > 0) addItem(new File([blob], 'glasovno-sporocilo.' + (mt.indexOf('mp4') > -1 ? 'm4a' : mt.indexOf('ogg') > -1 ? 'ogg' : 'webm'), { type: mt }), 'audio');
        setRec('Dodaj še eno glasovno sporočilo', 'Prejšnje je dodano zgoraj', false);
      };
      rec.start(1000);
      recT = 0;
      setRec('Ustavi snemanje', '0:00 – govorite, ustavite, ko končate', true);
      timer = setInterval(function () {
        recT++;
        $('pv-rec-sub').textContent = Math.floor(recT / 60) + ':' + ('0' + recT % 60).slice(-2) + ' – govorite, ustavite, ko končate';
        if (recT >= MAX_REC) rec.stop();
      }, 1000);
    }).catch(function () {
      showErr(err, 'Mikrofon ni dovoljen. Dovolite ga v nastavitvah brskalnika ali težavo opišite z besedilom.');
    });
  }

  /* ---------- način odgovora ---------- */
  var emailWrap = $('pv-email-wrap'), email = $('pv-email');
  function syncReply() {
    var v = (form.querySelector('input[name=odgovor]:checked') || {}).value;
    email.required = v === 'email';
    emailWrap.classList.toggle('need', v === 'email');
    emailWrap.querySelector('small').textContent = v === 'email' ? '(na ta naslov vam odgovorim)' : '(neobvezno, pošljem vam potrditev)';
  }
  Array.prototype.forEach.call(form.querySelectorAll('input[name=odgovor]'), function (r) { r.addEventListener('change', syncReply); });
  syncReply();

  /* ---------- oddaja ---------- */
  var tel = $('pv-tel'), telErr = $('pv-tel-err');
  tel.addEventListener('input', function () { showErr(telErr, ''); tel.removeAttribute('aria-invalid'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitting) return;
    showErr(err, '');
    var digits = tel.value.replace(/\D/g, '');
    if (digits.length < 8) {
      showErr(telErr, 'Vpišite telefonsko številko, na primer 041 123 456.');
      tel.setAttribute('aria-invalid', 'true'); tel.focus(); return;
    }
    if (email.required && !email.value.trim()) { showErr(err, 'Vpišite e-pošto ali izberite SMS oziroma klic.'); email.focus(); return; }
    if (!form.elements.opis.value.trim() && !visible().length) {
      showErr(err, 'Dodajte fotografijo, video ali glasovno sporočilo ali na kratko opišite težavo.');
      form.elements.opis.focus(); return;
    }
    if (rec && rec.state === 'recording') rec.stop();
    submitting = true;
    sendBtn.disabled = true; sendBtn.textContent = 'Pošiljam …';
    $('pv-progress').hidden = false; overall();

    setTimeout(function () { // počakaj, da se doda morebitni ravnokar ustavljen posnetek
      Promise.all(items.map(function (i) { return i.promise; })).then(function () {
        var failed = visible().filter(function (i) { return !i.done; });
        items.forEach(function (i) { if (i.kind === 'frame' && !i.done) i.removed = true; }); syncRemoved();
        if (failed.length) throw new Error((failed[0].error || 'Nekaterih datotek ni bilo mogoče naložiti.') + ' Odstranite jih ali poskusite znova.');
        overall();
        var fd = new FormData(form);
        return fetch(form.action, { method: 'POST', body: fd, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
      }).then(function (j) {
        if (!j || !j.ok) throw new Error((j && j.napaka) || 'Pošiljanje ni uspelo.');
        window.removeEventListener('beforeunload', guard);
        location.href = j.stanje + '&novo=1';
      }).catch(function (e2) {
        submitting = false;
        sendBtn.disabled = false; sendBtn.textContent = 'Poskusi znova';
        $('pv-progress').hidden = true;
        showErr(err, e2.message + (/pokličite/.test(e2.message) ? '' : ' Če ne gre, me pokličite na 070 755 355.'));
      });
    }, rec && rec.state !== 'inactive' ? 400 : 0);
  });

  function guard(e) {
    var busy = visible().some(function (i) { return !i.done; }) || (form.elements.opis.value.trim() && !submitting);
    if (busy) { e.preventDefault(); e.returnValue = ''; }
  }
  window.addEventListener('beforeunload', guard);
})();
