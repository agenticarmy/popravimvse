<?php
/* Stran s stanjem povpraševanja za stranko (osebna povezava, brez prijave). ?id=…&t=…
 * &format=json za agente; POST potrdi=<indeks termina> ali ne_ustreza=1 (+ opomba) za potrditev termina; &ics=1 za koledar.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_obvestila.php';

$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$isJson = str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
$in = $isJson ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
if ($isJson) { $id = (string)($in['id'] ?? $id); $t = (string)($in['t'] ?? $t); }

$r = load_req($id);
if (!$r || !hash_equals($r['token'], $t)) {
    if ($isJson || ($_GET['format'] ?? '') === 'json') json_out(['ok' => false, 'napaka' => 'Povpraševanja ni mogoče najti.'], 404);
    http_response_code(404);
    page_start('Povpraševanja ni mogoče najti | Popravim vse');
    echo '<h1>Povpraševanja ni mogoče najti</h1><p class="lead">Povezava je napačna ali pa je bilo povpraševanje po ' . RETENTION_DAYS
        . ' dneh izbrisano.</p><p><a class="call" href="/povprasevanje/">Pošljite novo povpraševanje</a></p>';
    page_end();
}
$p = $r['predlog'] ?? null;

/** Izbira stranke o snemanju med delom (obe kljukici neobvezni in neodvisni). */
function snemanje_iz(array $in): array
{
    return ['zascita' => !empty($in['snemanje_zascita']), 'youtube' => !empty($in['snemanje_youtube']), 'cas' => date('c')];
}

// sprememba izbire o snemanju (tudi po potrditvi termina)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($in['snemanje_shrani'])) {
    $old = $r['snemanje'] ?? null;
    $r['snemanje'] = snemanje_iz($in);
    save_req($r);
    if (!$old || $old['zascita'] !== $r['snemanje']['zascita'] || $old['youtube'] !== $r['snemanje']['youtube']) {
        $t = 'Snemanje: zaščitni posnetek ' . ($r['snemanje']['zascita'] ? 'DA' : 'ne') . ', YouTube ' . ($r['snemanje']['youtube'] ? 'DA' : 'ne');
        send_mail(NOTIFY_TO, 'Sprememba snemanja – ' . ($r['kraj'] ?: $r['telefon']), "Stranka je spremenila izbiro o snemanju.\n$t\n\nOdpri: " . admin_url($r) . "\n",
            mail_wrap('<h2 style="margin:0 0 12px">Stranka je spremenila izbiro o snemanju</h2><p><b>' . h($t) . '</b></p><p>' . mail_btn(admin_url($r), 'Odpri povpraševanje') . '</p>'));
    }
    if ($isJson) json_out(['ok' => true, 'snemanje' => $r['snemanje']]);
    header('Location: ' . status_url($r) . '#snemanje', true, 303);
    exit;
}

// potrditev termina (stranka ali njen agent)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $p && empty($p['potrjen'])) {
    $note = clean((string)($in['opomba'] ?? ''), 1000);
    if (isset($in['potrdi']) && isset($p['termini'][(int)$in['potrdi']]) && $p['termini'][(int)$in['potrdi']] > time()) {
        $ts = $p['termini'][(int)$in['potrdi']];
        $r['predlog']['potrjen'] = $ts;
        $r['predlog']['potrjeno_cas'] = date('c');
        $r['predlog']['opomba_stranke'] = $note;
        unset($r['predlog']['ne_ustreza']);
        if (isset($in['snemanje_izbira']) || isset($in['snemanje_zascita']) || isset($in['snemanje_youtube'])) $r['snemanje'] = snemanje_iz($in);
        save_req($r);
        set_status($r, 'termin');
        notify_slot($r, $ts, $note);
    } elseif (!empty($in['ne_ustreza'])) {
        $r['predlog']['ne_ustreza'] = date('c');
        $r['predlog']['opomba_stranke'] = $note;
        save_req($r);
        notify_slot($r, null, $note);
    }
    if ($isJson) json_out(['ok' => true, 'stanje' => $r['status'], 'termin' => isset($r['predlog']['potrjen']) ? date('c', $r['predlog']['potrjen']) : null]);
    header('Location: ' . status_url($r) . '#termini', true, 303);
    exit;
}
if ($isJson) json_out(['ok' => false, 'napaka' => 'Ni predloga terminov ali je termin že potrjen.'], 409);

if (isset($_GET['ics']) && !empty($p['potrjen'])) {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="popravimvse-termin.ics"');
    echo ics_for_customer($r, (int)$p['potrjen']);
    exit;
}

if (($_GET['format'] ?? '') === 'json') {
    json_out(['id' => $r['id'], 'stanje' => $r['status'], 'opis_stanja' => STATUS[$r['status']], 'prejeto' => $r['ustvarjeno'],
        'zgodovina' => $r['zgodovina'], 'odgovor_po' => REPLY[$r['odgovor']], 'priloge' => counts($r['datoteke']),
        'odgovor' => $p ? $p['besedilo'] : null,
        'termini' => $p ? array_map(fn($ts) => date('c', $ts), $p['termini']) : [],
        'potrjen_termin' => !empty($p['potrjen']) ? date('c', $p['potrjen']) : null,
        'snemanje' => $r['snemanje'] ?? null,
        'kako_potrditi' => $p && empty($p['potrjen']) && $p['termini']
            ? 'POST JSON na ' . SITE . '/api/stanje.php z {"id","t","potrdi": indeks termina (0, 1, 2)} ali {"ne_ustreza": true, "opomba": "…"}; samo s soglasjem stranke.' : null]);
}

function snemanje_polja(array $r): void
{
    $s = $r['snemanje'] ?? ['zascita' => false, 'youtube' => false]; ?>
  <div class="pv-rec-choice">
    <input type="hidden" name="snemanje_izbira" value="1">
    <label class="pv-check"><input type="checkbox" name="snemanje_zascita" value="1" <?= $s['zascita'] ? 'checked' : '' ?>> <span><b>Med delom ves čas snemajte</b> z akcijsko kamero na prsih (zaščita za oba). Posnetek hranite 30 dni, nato ga izbrišete; na zahtevo dobim kopijo.</span></label>
    <label class="pv-check"><input type="checkbox" name="snemanje_youtube" value="1" <?= $s['youtube'] ? 'checked' : '' ?>> <span><b>Del posnetka smete uporabiti za YouTube:</b> samo kader dela, brez mene, drugih ljudi in drugih prostorov. Pred objavo mi ga pokažete in lahko rečem ne.</span></label>
    <p class="pv-small">Oboje je neobvezno, neodvisno eno od drugega in ga lahko kadar koli spremenite. Ob prihodu vas še enkrat vprašam. <a href="/zasebnost/#snemanje">Več o snemanju</a></p>
  </div>
<?php }

$order = array_keys(STATUS);
$cur = array_search($r['status'], $order, true);
$when = [];
foreach ($r['zgodovina'] as $z) $when[$z['status']] = $z['cas'];
$steps = [
    'prejeto' => ['Prejeto', 'Vaše povpraševanje je shranjeno in Sebastjan je dobil obvestilo.'],
    'pregledujem' => ['Pregledujem', 'Sebastjan si ogleduje vaš opis, fotografije in posnetke.'],
    'odgovorjeno' => ['Odgovoril sem', $p ? 'Odgovor in predlog termina sta spodaj.' : 'Odgovor dobite ' . REPLY_PO[$r['odgovor']] . ', kot ste izbrali.'],
    'termin' => ['Termin potrjen', !empty($p['potrjen']) ? sl_slot((int)$p['potrjen']) : 'Ko izberete termin, ga Sebastjan dobi takoj.'],
];
$isNew = isset($_GET['novo']);
$what = counts($r['datoteke']);

page_start('Stanje povpraševanja | Popravim vse');
?>
<?php if ($isNew): ?>
<div class="pv-done" role="status">
  <h1>Hvala, prejel sem vaše povpraševanje</h1>
  <p class="lead">Pregledal ga bom sam in vam odgovoril <?= h(REPLY_PO[$r['odgovor']]) ?>, ko končam delo pri stranki. Med delom je moj telefon tiho, zato prosim za malo potrpljenja.</p>
</div>
<?php elseif (!empty($p['potrjen'])): ?>
<div class="pv-done" role="status">
  <h1>Termin je potrjen</h1>
  <p class="lead">Pridem <b><?= h(sl_slot((int)$p['potrjen'])) ?></b>. Če se kaj spremeni, me pokličite na <a href="tel:<?= BIZ_PHONE_E164 ?>"><?= BIZ_PHONE ?></a>.</p>
  <p class="pv-actions"><a class="call" href="<?= h(status_url($r)) ?>&amp;ics=1">Dodaj v moj koledar</a><a class="btn-plain" href="/pred-obiskom/">Kako se pripraviti</a></p>
</div>
<?php else: ?>
<h1>Stanje vašega povpraševanja</h1>
<?php endif; ?>

<?php if ($p): ?>
<section class="pv-card pv-answer" id="termini">
  <h2>Sebastjanov odgovor</h2>
  <p class="pv-quote"><?= nl2br(h($p['jedro'])) ?><br><br>Sebastjan, Popravim vse, <?= BIZ_PHONE ?></p>
  <?php if ($p['termini'] && empty($p['potrjen'])): ?>
    <form method="post" class="pv-pickslot">
      <input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="t" value="<?= h($r['token']) ?>">
      <h3>Snemanje med delom <small>(neobvezno)</small></h3>
      <?php snemanje_polja($r); ?>
      <h3>Izberite termin</h3>
      <?php foreach ($p['termini'] as $i => $ts): if ($ts <= time()) continue; ?>
        <button class="<?= $i === 0 ? 'call' : 'btn-plain' ?> pv-slotbtn" name="potrdi" value="<?= $i ?>"><?= h(sl_slot($ts)) ?></button>
      <?php endforeach; ?>
      <label class="pv-label" for="pv-note">Sporočilo Sebastjanu <small>(neobvezno, npr. naslov ali kje parkirati)</small></label>
      <textarea id="pv-note" name="opomba" rows="2" maxlength="1000"></textarea>
      <button class="btn-plain pv-none" name="ne_ustreza" value="1">Noben termin mi ne ustreza</button>
      <?php if (!empty($p['ne_ustreza'])): ?><p class="pv-small">Sporočili ste, da vam noben termin ne ustreza. Sebastjan se vam oglasi.</p><?php endif; ?>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($p['potrjen'])): ?>
<section class="pv-card" id="snemanje">
  <h2>Snemanje med delom</h2>
  <form method="post">
    <input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="t" value="<?= h($r['token']) ?>">
    <?php snemanje_polja($r); ?>
    <button class="btn-plain" name="snemanje_shrani" value="1">Shrani izbiro</button>
  </form>
</section>
<?php endif; ?>

<ol class="pv-steps">
<?php foreach ($steps as $k => [$tt, $d]):
    $i = array_search($k, $order, true);
    $cls = $i < $cur || $r['status'] === 'zakljuceno' ? 'done' : ($i === $cur ? 'now' : 'todo'); ?>
  <li class="<?= $cls ?>"><b><?= h($tt) ?></b><?php if (isset($when[$k])): ?> <time datetime="<?= h($when[$k]) ?>"><?= h(sl_time($when[$k])) ?></time><?php endif; ?><span><?= h($d) ?></span></li>
<?php endforeach; ?>
</ol>

<section class="pv-card">
  <h2>Kaj ste poslali</h2>
  <ul class="pv-facts">
    <li><b>Prejeto:</b> <?= h(sl_time($r['ustvarjeno'])) ?></li>
    <li><b>Priloge:</b> <?= h($what ?: 'brez prilog') ?></li>
    <?php if ($r['kraj']): ?><li><b>Kraj:</b> <?= h($r['kraj']) ?></li><?php endif; ?>
    <li><b>Odgovor po:</b> <?= h(REPLY[$r['odgovor']]) ?>, <?= h($r['odgovor'] === 'email' ? $r['email'] : $r['telefon']) ?></li>
  </ul>
  <?php if ($r['opis'] !== ''): ?><p class="pv-quote"><?= nl2br(h($r['opis'])) ?></p><?php endif; ?>
</section>

<?php if (!$p): ?>
<section class="pv-card">
  <h2>Kaj sledi</h2>
  <ul class="checks">
    <li>Povem vam, ali je delo zame in koliko ur okvirno pričakujem.</li>
    <li>Če je treba kaj kupiti, vam napišem, kaj in koliko. Material kupite sami, po ceni iz trgovine.</li>
    <li>Predlagam termin. Termin velja šele, ko ga oba potrdiva.</li>
    <li>Cena je 35 € na uro, prevoz je vključen. Če okvare ne morem odpraviti, ne plačate nič (<a href="/cenik/#jamstvo">kdaj to velja</a>).</li>
    <li>Če med delom vidim, da bo trajalo dlje od ocene, se ustavim in vas vprašam. Brez vašega soglasja ne nadaljujem.</li>
  </ul>
</section>
<?php endif; ?>

<section class="pv-card">
  <p>To stran si lahko shranite in jo kadar koli znova odprete.</p>
  <p class="pv-actions">
    <button type="button" class="btn-plain" id="pv-share">Shrani povezavo</button>
    <a class="btn-plain" href="/povprasevanje/">Pošlji še kaj</a>
  </p>
  <p>Nujno? Pokličite <a href="tel:<?= BIZ_PHONE_E164 ?>"><?= BIZ_PHONE ?></a>. Če ne dvignem, sem pri delu – pošljite SMS, pa vas pokličem, ko končam.</p>
</section>
<p class="pv-small">Vaše povpraševanje in posnetki se samodejno izbrišejo po <?= RETENTION_DAYS ?> dneh. <a href="/zasebnost/">Zasebnost</a></p>
<script>
(function () {
  var b = document.getElementById('pv-share'); if (!b) return;
  b.addEventListener('click', function () {
    var u = location.href.replace(/&novo=1/, '').replace(/#.*$/, '');
    if (navigator.share) { navigator.share({ title: 'Moje povpraševanje – Popravim vse', url: u }).catch(function () {}); }
    else if (navigator.clipboard) { navigator.clipboard.writeText(u).then(function () { b.textContent = 'Povezava kopirana ✓'; }); }
  });
  if (location.search.indexOf('novo=1') > -1 && history.replaceState) history.replaceState(null, '', location.href.replace(/&novo=1/, ''));
})();
</script>
<?php page_end();
