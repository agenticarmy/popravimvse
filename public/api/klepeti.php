<?php
/* Sebastjanov pregled pogovorov s pomočnikom (samo pogovori, kjer je obiskovalec dovolil shranjevanje). ?k=… [&id=…] */
declare(strict_types=1);
require __DIR__ . '/_klepet.php';

$k = (string)($_GET['k'] ?? '');
if (!hash_equals(list_key(), $k)) { http_response_code(404); exit; }
$cfg = config();
$u = kl_usage();
$id = (string)($_GET['id'] ?? '');

page_start('Pogovori s pomočnikom | Popravim vse');
echo '<p class="pv-small"><a href="/api/seznam.php?k=' . h($k) . '">← Povpraševanja</a> · <a href="/api/nastavitve.php?k=' . h($k) . '">Nastavitve</a></p>';

if ($id !== '' && kl_valid_id($id) && is_file(kl_file($id))) {
    $c = json_decode((string)file_get_contents(kl_file($id)), true) ?: [];
    echo '<h1>Pogovor</h1><p class="pv-small">' . h(sl_time($c['zacetek'])) . ' · stran ' . h($c['stran']) . '</p>';
    if (!empty($c['predaja'])) echo '<p class="pv-okmsg"><b>Predlagal video Sebastjanu:</b> ' . h($c['predaja']) . '</p>';
    echo '<div class="kl-log">';
    foreach ($c['sporocila'] ?? [] as $m) {
        echo '<div class="pv-card"><p class="pv-small"><b>' . ($m['vloga'] === 'obiskovalec' ? 'Obiskovalec' : 'Pomočnik') . '</b></p><p>' . nl2br(h($m['besedilo'])) . '</p></div>';
    }
    echo '</div>';
    page_end();
}

echo '<h1>Pogovori s pomočnikom</h1>';
echo '<p class="pv-small">Ta mesec: ' . kl_n((int)$u['pogovori'], KL_POG) . ', ' . kl_n((int)$u['odgovori'], KL_ODG) . ', poraba '
    . number_format((float)$u['usd'], 2, ',', '') . ' $ od ' . (int)$cfg['klepet_proracun'] . ' $. Pomočnik je ' . (kl_ready($cfg) ? 'vključen' : 'izključen') . '.</p>';
$rows = [];
foreach (glob(storage('klepet/pogovori') . '/*.json') ?: [] as $f) {
    $c = json_decode((string)file_get_contents($f), true);
    if ($c) $rows[] = $c;
}
usort($rows, fn($a, $b) => strcmp($b['zadnje'] ?? '', $a['zadnje'] ?? ''));
if (!$rows) echo '<p class="lead">Še ni shranjenih pogovorov. Shranijo se samo tisti, pri katerih obiskovalec to dovoli.</p>';
echo '<ul class="pv-list">';
foreach ($rows as $c) {
    $first = $c['sporocila'][0]['besedilo'] ?? '';
    echo '<li><a href="/api/klepeti.php?k=' . h($k) . '&amp;id=' . h($c['id']) . '"><b>' . h(mb_strimwidth($first, 0, 90, '…')) . '</b>'
        . (!empty($c['predaja']) ? '<span class="pv-badge">predlagan video</span>' : '')
        . '<span>' . h(sl_time($c['zadnje'] ?? $c['zacetek'])) . ' · ' . kl_n((int)(count($c['sporocila'] ?? []) / 2), KL_VPR) . ' · ' . h($c['stran']) . '</span></a></li>';
}
echo '</ul><p class="pv-small">Pogovori se samodejno izbrišejo po ' . RETENTION_DAYS . ' dneh.</p>';
page_end();
