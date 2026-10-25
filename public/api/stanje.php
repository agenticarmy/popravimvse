<?php
/* Stran s stanjem povpraševanja za stranko (osebna povezava, brez prijave). ?id=…&t=…  (&format=json za agente) */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

$r = load_req((string)($_GET['id'] ?? ''));
if (!$r || !hash_equals($r['token'], (string)($_GET['t'] ?? ''))) {
    http_response_code(404);
    page_start('Povpraševanja ni mogoče najti | Popravim vse');
    echo '<h1>Povpraševanja ni mogoče najti</h1><p class="lead">Povezava je napačna ali pa je bilo povpraševanje po ' . RETENTION_DAYS
        . ' dneh izbrisano.</p><p><a class="call" href="/povprasevanje/">Pošljite novo povpraševanje</a></p>';
    page_end();
}

if (($_GET['format'] ?? '') === 'json') {
    json_out(['id' => $r['id'], 'stanje' => $r['status'], 'opis_stanja' => STATUS[$r['status']], 'prejeto' => $r['ustvarjeno'],
        'zgodovina' => $r['zgodovina'], 'odgovor_po' => REPLY[$r['odgovor']], 'priloge' => counts($r['datoteke'])]);
}

$order = array_keys(STATUS);
$cur = array_search($r['status'], $order, true);
$when = [];
foreach ($r['zgodovina'] as $z) $when[$z['status']] = $z['cas'];
$steps = [
    'prejeto' => ['Prejeto', 'Vaše povpraševanje je shranjeno in Sebastjan je dobil obvestilo.'],
    'pregledujem' => ['Pregledujem', 'Sebastjan si ogleduje vaš opis, fotografije in posnetke.'],
    'odgovorjeno' => ['Odgovoril sem', 'Odgovor dobite ' . REPLY_PO[$r['odgovor']] . ', kot ste izbrali.'],
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
<?php else: ?>
<h1>Stanje vašega povpraševanja</h1>
<?php endif; ?>

<ol class="pv-steps">
<?php foreach ($steps as $k => [$t, $d]):
    $i = array_search($k, $order, true);
    $cls = $i < $cur || $r['status'] === 'zakljuceno' ? 'done' : ($i === $cur ? 'now' : 'todo'); ?>
  <li class="<?= $cls ?>"><b><?= h($t) ?></b><?php if (isset($when[$k])): ?> <time datetime="<?= h($when[$k]) ?>"><?= h(sl_time($when[$k])) ?></time><?php endif; ?><span><?= h($d) ?></span></li>
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

<section class="pv-card">
  <h2>Kaj sledi</h2>
  <ul class="checks">
    <li>Povem vam, ali je delo zame in koliko ur okvirno pričakujem.</li>
    <li>Če je treba kaj kupiti, vam napišem, kaj in koliko. Material kupite sami, po ceni iz trgovine.</li>
    <li>Predlagam termin. Termin velja šele, ko ga oba potrdiva.</li>
    <li>Cena je 35 € na uro, prevoz je vključen. Če popravila ni mogoče opraviti, ne plačate nič.</li>
  </ul>
  <p>To stran si lahko shranite in jo kadar koli znova odprete.</p>
  <p class="pv-actions">
    <button type="button" class="btn-plain" id="pv-share">Shrani povezavo</button>
    <a class="btn-plain" href="/povprasevanje/">Pošlji še kaj</a>
  </p>
</section>

<section class="pv-card">
  <h2>Nujno?</h2>
  <p>Pokličite <a href="tel:<?= BIZ_PHONE_E164 ?>"><?= BIZ_PHONE ?></a>. Če ne dvignem, sem pri delu – pošljite SMS, pa vas pokličem, ko končam.</p>
</section>
<p class="pv-small">Vaše povpraševanje in posnetki se samodejno izbrišejo po <?= RETENTION_DAYS ?> dneh. <a href="/zasebnost/">Zasebnost</a></p>
<script>
(function () {
  var b = document.getElementById('pv-share'); if (!b) return;
  b.addEventListener('click', function () {
    var u = location.href.replace(/&novo=1/, '');
    if (navigator.share) { navigator.share({ title: 'Moje povpraševanje – Popravim vse', url: u }).catch(function () {}); }
    else if (navigator.clipboard) { navigator.clipboard.writeText(u).then(function () { b.textContent = 'Povezava kopirana ✓'; }); }
  });
  if (location.search.indexOf('novo=1') > -1 && history.replaceState) history.replaceState(null, '', location.href.replace(/&novo=1/, ''));
})();
</script>
<?php page_end();
