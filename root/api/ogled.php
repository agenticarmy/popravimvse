<?php
/* Sebastjanov ogled enega povpraševanja (povezava iz e-pošte). ?id=…&k=… */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
$k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
$r = load_req($id);
if (!$r || !hash_equals(admin_key($id), $k)) {
    http_response_code(404);
    page_start('Ni najdeno');
    echo '<h1>Povpraševanja ni mogoče najti</h1><p>Povezava je napačna ali pa je bilo povpraševanje že izbrisano.</p>';
    page_end();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    set_status($r, (string)($_POST['status'] ?? ''));
    header('Location: ' . admin_url($r) . '#stanje', true, 303);
    exit;
}
if ($r['status'] === 'prejeto') set_status($r, 'pregledujem');

$q = 'id=' . rawurlencode($id) . '&k=' . rawurlencode($k);
$tel = $r['telefon_e164'];
$hello = 'Pozdravljeni' . ($r['ime'] ? ' ' . $r['ime'] : '') . ', tukaj Sebastjan (Popravim vse). ';
$cal = 'https://calendar.google.com/calendar/render?' . http_build_query([
    'action' => 'TEMPLATE',
    'text' => 'Popravim vse: ' . ($r['kraj'] ?: 'stranka') . ' – ' . ($r['ime'] ?: $r['telefon']),
    'details' => trim(mb_substr($r['opis'], 0, 600) . "\n\nTelefon: " . $r['telefon'] . "\nPovpraševanje: " . admin_url($r)),
    'location' => $r['kraj'],
]);

page_start('Povpraševanje ' . $r['id'] . ' | Popravim vse');
?>
<p class="pv-small"><a href="/api/seznam.php?k=<?= h(list_key()) ?>">← Vsa povpraševanja</a></p>
<h1><?= h($r['kraj'] ?: 'Kraj ni naveden') ?> <span class="pv-badge"><?= h(STATUS[$r['status']]) ?></span></h1>
<ul class="pv-facts">
  <li><b>Telefon:</b> <a href="tel:<?= h($tel) ?>"><?= h($r['telefon']) ?></a></li>
  <li><b>Želi odgovor:</b> <?= h(REPLY[$r['odgovor']]) ?><?= $r['email'] ? ' · <a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' : '' ?></li>
  <?php if ($r['ime']): ?><li><b>Ime:</b> <?= h($r['ime']) ?></li><?php endif; ?>
  <li><b>Prejeto:</b> <?= h(sl_time($r['ustvarjeno'])) ?><?= $r['vir'] === 'agent' ? ' · poslal agent UI' . ($r['agent'] ? ' (' . h($r['agent']) . ')' : '') : '' ?></li>
</ul>

<div class="pv-actions pv-sticky">
  <a class="call" href="tel:<?= h($tel) ?>">Pokliči</a>
  <a class="btn-plain" href="sms:<?= h($tel) ?>?&amp;body=<?= rawurlencode($hello) ?>">SMS</a>
  <?php if ($r['email']): ?><a class="btn-plain" href="mailto:<?= h($r['email']) ?>?subject=<?= rawurlencode('Vaše povpraševanje – Popravim vse') ?>&amp;body=<?= rawurlencode($hello) ?>">E-pošta</a><?php endif; ?>
  <a class="btn-plain" href="<?= h($cal) ?>" target="_blank" rel="noopener">Dodaj v koledar</a>
</div>

<?php if ($r['opis'] !== ''): ?><p class="pv-quote"><?= nl2br(h($r['opis'])) ?></p><?php endif; ?>

<?php if ($r['datoteke']): ?>
<div class="pv-media">
<?php foreach ($r['datoteke'] as $n => $f):
    $src = '/api/datoteka.php?' . $q . '&f=' . $n; ?>
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
    <figcaption><?= h($f['datoteka']) ?> · <?= h(bytes((int)$f['velikost'])) ?> · <a href="<?= h($src) ?>&amp;dl=1">prenesi</a></figcaption>
  </figure>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" class="pv-card" id="stanje">
  <h2>Stanje za stranko</h2>
  <p>Stranka na svoji povezavi vidi: <b><?= h(STATUS[$r['status']]) ?></b>.</p>
  <input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="k" value="<?= h($k) ?>">
  <div class="pv-actions">
    <button class="btn-plain" name="status" value="odgovorjeno">Odgovoril sem</button>
    <button class="btn-plain" name="status" value="zakljuceno">Zaključeno</button>
  </div>
  <p class="pv-small">Stranka: <a href="<?= h(status_url($r)) ?>" target="_blank" rel="noopener">njena stran s stanjem</a>. Izbris samodejno po <?= RETENTION_DAYS ?> dneh.</p>
</form>
<?php page_end();
