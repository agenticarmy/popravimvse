<?php
/* Sebastjanov ogled povpraševanja: UI predlog, urejanje odgovora in terminov, potrditev z enim klikom. ?id=…&k=… */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_obvestila.php';

$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
$k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
$r = load_req($id);
if (!$r || !hash_equals(admin_key($id), $k)) {
    http_response_code(404);
    page_start('Ni najdeno');
    echo '<h1>Povpraševanja ni mogoče najti</h1><p>Povezava je napačna ali pa je bilo povpraševanje že izbrisano.</p>';
    page_end();
}
$tz = new DateTimeZone('Europe/Ljubljana');
$cfg = config();
$action = (string)($_POST['akcija'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($action === 'status') {
        set_status($r, (string)($_POST['status'] ?? ''));
    } elseif ($action === 'analiziraj' && ui_ready($cfg)) {
        @set_time_limit(240);
        $r = ui_analyze($r, $cfg);
    } elseif ($action === 'poslji') {
        $body = trim(str_replace("\r\n", "\n", (string)($_POST['besedilo'] ?? '')));
        $termini = [];
        foreach ((array)($_POST['termin'] ?? []) as $v) {
            $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string)$v, $tz);
            if ($d && $d->getTimestamp() > time()) $termini[] = $d->getTimestamp();
        }
        $termini = array_values(array_unique($termini));
        sort($termini);
        if ($body !== '') {
            $msg = compose_message($body, $termini, status_url($r));
            $r['predlog'] = ['jedro' => $body, 'besedilo' => $msg, 'termini' => $termini, 'cas' => date('c'), 'potrjen' => null];
            set_status($r, 'odgovorjeno');
            save_req($r);
            $mailed = $r['email'] !== '' ? mail_proposal($r) : false;
            $r['predlog']['poslano_email'] = $mailed;
            save_req($r);
            // stran »Poslano«: SMS z besedilom se odpre v aplikaciji Sporočila, Sebastjan pritisne Pošlji
            $sms = 'sms:' . $r['telefon_e164'] . '?&body=' . rawurlencode($msg);
            page_start('Odgovor pripravljen | Popravim vse');
            echo '<div class="pv-done"><h1>Odgovor je potrjen</h1><p class="lead">Stranka ga vidi na svoji strani'
                . ($mailed ? ' in ga je dobila po e-pošti' : '') . '.' . ($r['odgovor'] === 'sms' || !$mailed ? ' Pošljite ji še SMS:' : '') . '</p></div>'
                . '<div class="pv-actions"><a class="call" id="pv-sms" href="' . h($sms) . '">Odpri SMS z besedilom</a>'
                . '<a class="btn-plain" href="tel:' . h($r['telefon_e164']) . '">Pokliči</a>'
                . '<button type="button" class="btn-plain" id="pv-copy">Kopiraj besedilo</button></div>'
                . '<p class="pv-quote" id="pv-msg">' . nl2br(h($msg)) . '</p>'
                . '<p><a href="' . h(admin_url($r)) . '">← Nazaj na povpraševanje</a></p>'
                . '<script>document.getElementById("pv-copy").addEventListener("click",function(){navigator.clipboard.writeText(' . json_encode($msg, JSON_UNESCAPED_UNICODE)
                . ').then(()=>{this.textContent="Kopirano ✓"})});'
                . ($r['odgovor'] === 'sms' ? 'if(/Android|iPhone|iPad/.test(navigator.userAgent))setTimeout(function(){location.href=document.getElementById("pv-sms").href},400);' : '')
                . '</script>';
            page_end();
        }
    }
    header('Location: ' . admin_url($r) . '#odgovor', true, 303);
    exit;
}
if ($r['status'] === 'prejeto') set_status($r, 'pregledujem');

$q = 'id=' . rawurlencode($id) . '&k=' . rawurlencode($k);
$ui = $r['ui'] ?? null;
$u = ($ui['stanje'] ?? '') === 'ok' ? $ui['rezultat'] : null;
$running = ($ui['stanje'] ?? '') === 'v_teku' && strtotime($ui['zacetek'] ?? 'now') > time() - 240;

// osnutek in termini za obrazec
if (!empty($r['predlog'])) {
    $body = $r['predlog']['jedro'];
    $slots = $r['predlog']['termini'];
} else {
    $body = $u ? ($ui['besedilo'] ?? compose_body($r)) : 'Pozdravljeni' . ($r['ime'] ? ' ' . $r['ime'] : '') . ",\n\n";
    $slots = $u ? $ui['termini'] : ($u === null && ($u['ali_je_zame'] ?? '') !== 'ne' ? free_slots(120, 3, $cfg)['termini'] : []);
}
$calWarn = $ui['koledar_opozorilo'] ?? ($cfg['ical'] === '' ? 'Koledar ni povezan – termini niso preverjeni.' : null);
$cal = gcal_link('Popravim vse: ' . ($r['kraj'] ?: 'stranka') . ' – ' . ($r['ime'] ?: $r['telefon']),
    trim(mb_substr($r['opis'], 0, 600) . "\n\nTelefon: " . $r['telefon'] . "\nPovpraševanje: " . admin_url($r)), $r['kraj']);
$dtv = fn(?int $ts) => $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d\TH:i') : '';

page_start('Povpraševanje ' . $r['id'] . ' | Popravim vse', $running ? '<meta http-equiv="refresh" content="8">' : '');
?>
<p class="pv-small"><a href="/api/seznam.php?k=<?= h(list_key()) ?>">← Vsa povpraševanja</a></p>
<h1><?= h($r['kraj'] ?: 'Kraj ni naveden') ?> <span class="pv-badge"><?= h(STATUS[$r['status']]) ?></span></h1>
<ul class="pv-facts">
  <li><b>Telefon:</b> <a href="tel:<?= h($r['telefon_e164']) ?>"><?= h($r['telefon']) ?></a></li>
  <li><b>Želi odgovor:</b> <?= h(REPLY[$r['odgovor']]) ?><?= $r['email'] ? ' · <a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' : '' ?></li>
  <?php if ($r['ime']): ?><li><b>Ime:</b> <?= h($r['ime']) ?></li><?php endif; ?>
  <li><b>Prejeto:</b> <?= h(sl_time($r['ustvarjeno'])) ?><?= $r['vir'] === 'agent' ? ' · poslal agent UI' . ($r['agent'] ? ' (' . h($r['agent']) . ')' : '') : '' ?></li>
</ul>

<?php if (!empty($r['predlog']['potrjen']) || isset($r['predlog']['ne_ustreza'])): ?>
<div class="pv-done">
  <?php if (!empty($r['predlog']['potrjen'])): $ts = (int)$r['predlog']['potrjen']; ?>
    <h2>Stranka je potrdila termin: <?= h(sl_slot($ts)) ?></h2>
    <p><a class="call" href="<?= h(gcal_link('Popravim vse: ' . ($r['kraj'] ?: 'stranka') . ' – ' . ($r['ime'] ?: $r['telefon']), trim(mb_substr($r['opis'], 0, 500) . "\n\nTelefon: {$r['telefon']}\nPovpraševanje: " . admin_url($r)), $r['kraj'], $ts, $ts + (int)ceil(((float)($u['ocena_ur_max'] ?? 2)) * 2) * 1800)) ?>" target="_blank" rel="noopener">Dodaj v Google Koledar</a></p>
  <?php else: ?>
    <h2>Stranki noben termin ne ustreza</h2>
  <?php endif; ?>
  <?php if (!empty($r['predlog']['opomba_stranke'])): ?><p class="pv-quote"><?= nl2br(h($r['predlog']['opomba_stranke'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($r['opis'] !== ''): ?><p class="pv-quote"><?= nl2br(h($r['opis'])) ?></p><?php endif; ?>

<?php
$media = array_filter($r['datoteke'], fn($f) => $f['vrsta'] !== 'frame');
if ($media): ?>
<div class="pv-media">
<?php foreach ($r['datoteke'] as $n => $f):
    if ($f['vrsta'] === 'frame') continue;
    $src = '/api/datoteka.php?' . $q . '&f=' . $n;
    $pr = null;
    foreach ($ui['prepisi'] ?? [] as $p) if ($p['datoteka'] === $f['datoteka']) $pr = $p; ?>
  <figure>
    <?php if ($f['vrsta'] === 'image' && !in_array($f['tip'], ['image/heic', 'image/heif'], true)): ?>
      <a href="<?= h($src) ?>" target="_blank" rel="noopener"><img src="<?= h($src) ?>" alt="Fotografija <?= $n + 1 ?>" loading="lazy"></a>
    <?php elseif ($f['vrsta'] === 'video'): ?>
      <video src="<?= h($src) ?>" controls playsinline preload="metadata"></video>
    <?php elseif ($f['vrsta'] === 'audio'): ?>
      <audio src="<?= h($src) ?>" controls preload="metadata"></audio>
    <?php else: ?>
      <a class="btn-plain" href="<?= h($src) ?>">Odpri <?= h($f['datoteka']) ?></a>
    <?php endif; ?>
    <?php if ($pr): ?><blockquote class="pv-transcript"><?= $pr['besedilo'] !== null ? '»' . h($pr['besedilo']) . '«' : 'Prepis: ' . h($pr['napaka']) ?></blockquote><?php endif; ?>
    <figcaption><?= h($f['datoteka']) ?> · <?= h(bytes((int)$f['velikost'])) ?> · <a href="<?= h($src) ?>&amp;dl=1">prenesi</a></figcaption>
  </figure>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($running): ?>
  <p class="pv-card pv-ai-wait">UI pripravlja oceno … stran se osveži sama.</p>
<?php elseif ($u): ?>
<section class="pv-card pv-ai">
  <h2>Predlog UI <small>(preverite)</small></h2>
  <p><?= h($u['povzetek']) ?></p>
  <ul class="pv-facts">
    <li><b>Je delo zame:</b> <?= h(['da' => 'Da', 'ne' => 'Ne', 'morda' => 'Morda'][$u['ali_je_zame']]) ?><?= $u['razlog'] ? ' – ' . h($u['razlog']) : '' ?></li>
    <?php if ($u['ali_je_zame'] !== 'ne'): ?>
    <li><b>Ocena:</b> <?= h(str_replace('.', ',', (string)$u['ocena_ur_min'])) ?>–<?= h(ure((float)$u['ocena_ur_max'])) ?>, <?= cena((float)$u['ocena_ur_min']) ?>–<?= cena((float)$u['ocena_ur_max']) ?> € · zaupanje <?= h($u['zaupanje']) ?></li>
    <li><b>Zakaj:</b> <?= h($u['ocena_razlaga']) ?></li>
    <?php endif; ?>
  </ul>
  <?php if ($u['opozorila']): ?><ul class="pv-warn"><?php foreach ($u['opozorila'] as $o): ?><li><?= h($o) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($u['preveriti_na_kraju']): ?><p><b>Preveri:</b></p><ul><?php foreach ($u['preveriti_na_kraju'] as $o): ?><li><?= h($o) ?></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php elseif (($ui['stanje'] ?? '') === 'napaka'): ?>
  <p class="pv-err pv-err-main">UI ocena ni uspela: <?= h($ui['napaka']) ?></p>
<?php endif; ?>

<form method="post" class="pv-card pv-reply" id="odgovor">
  <h2><?= !empty($r['predlog']) ? 'Poslani odgovor (lahko ga popravite in pošljete znova)' : 'Odgovor stranki' ?></h2>
  <input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="k" value="<?= h($k) ?>">
  <label class="pv-label" for="pv-body">Besedilo <small>(uredite po želji)</small></label>
  <textarea id="pv-body" name="besedilo" rows="12"><?= h($body) ?></textarea>
  <?php if (($u['ali_je_zame'] ?? '') !== 'ne'): ?>
  <div class="pv-label">Predlagani termini <small>(prazno polje se ne pošlje)</small></div>
  <?php for ($i = 0; $i < 3; $i++): ?>
    <input class="pv-slot" type="datetime-local" name="termin[]" step="1800" value="<?= h($dtv($slots[$i] ?? null)) ?>" aria-label="Termin <?= $i + 1 ?>">
  <?php endfor; ?>
  <?php if ($calWarn): ?><p class="pv-small">⚠ <?= h($calWarn) ?></p><?php endif; ?>
  <?php endif; ?>
  <details class="pv-preview"><summary>Predogled sporočila</summary><p class="pv-quote" id="pv-prev"></p></details>
  <button class="call pv-send" name="akcija" value="poslji">Potrdi in pošlji</button>
  <p class="pv-small">Stranka odgovor vidi na svoji strani<?= $r['email'] ? ' in ga dobi po e-pošti' : '' ?>. SMS se odpre v vašem telefonu z vpisanim besedilom.</p>
  <?php if (ui_ready($cfg)): ?><button class="btn-plain" name="akcija" value="analiziraj"><?= $u ? 'Ponovno oceni z UI' : 'Oceni z UI' ?></button><?php endif; ?>
</form>

<div class="pv-actions">
  <a class="btn-plain" href="tel:<?= h($r['telefon_e164']) ?>">Pokliči</a>
  <a class="btn-plain" href="<?= h($cal) ?>" target="_blank" rel="noopener">Dodaj v koledar</a>
</div>

<form method="post" class="pv-card" id="stanje">
  <h2>Stanje za stranko</h2>
  <p>Stranka na svoji povezavi vidi: <b><?= h(STATUS[$r['status']]) ?></b>.</p>
  <input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="k" value="<?= h($k) ?>"><input type="hidden" name="akcija" value="status">
  <div class="pv-actions">
    <button class="btn-plain" name="status" value="odgovorjeno">Odgovoril sem</button>
    <button class="btn-plain" name="status" value="zakljuceno">Zaključeno</button>
  </div>
  <p class="pv-small">Stranka: <a href="<?= h(status_url($r)) ?>" target="_blank" rel="noopener">njena stran s stanjem</a>. Izbris samodejno po <?= RETENTION_DAYS ?> dneh.</p>
</form>
<script>
(function () {
  var DNI = ['nedelja', 'ponedeljek', 'torek', 'sreda', 'četrtek', 'petek', 'sobota'];
  var body = document.getElementById('pv-body'), prev = document.getElementById('pv-prev');
  var link = <?= json_encode(status_url($r), JSON_UNESCAPED_SLASHES) ?>, sig = <?= json_encode("\n\nSebastjan, Popravim vse, " . BIZ_PHONE, JSON_UNESCAPED_UNICODE) ?>;
  function fmt(v) { var d = new Date(v); return DNI[d.getDay()] + ', ' + d.getDate() + '. ' + (d.getMonth() + 1) + '. ob ' + d.getHours() + '.' + ('0' + d.getMinutes()).slice(-2); }
  function upd() {
    var s = Array.prototype.map.call(document.querySelectorAll('.pv-slot'), function (i) { return i.value; }).filter(Boolean).sort();
    var t = body.value.trim();
    t += s.length ? '\n\nPredlagam termin:' + s.map(function (v, i) { return '\n' + (i + 1) + ') ' + fmt(v); }).join('') + '\nTermin potrdite tukaj: ' + link : '\n\nVse podrobnosti: ' + link;
    prev.textContent = t + sig;
  }
  document.querySelectorAll('.pv-slot, #pv-body').forEach(function (e) { e.addEventListener('input', upd); });
  upd();
})();
</script>
<?php page_end();
