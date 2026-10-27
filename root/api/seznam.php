<?php
/* Sebastjanov seznam vseh povpraševanj (povezava je v vsakem obvestilu). ?k=… */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

if (!hash_equals(list_key(), (string)($_GET['k'] ?? ''))) { http_response_code(404); exit; }

$rows = [];
foreach (glob(storage('povprasevanja') . '/*/meta.json') ?: [] as $mf) {
    $r = json_decode((string)file_get_contents($mf), true);
    if ($r) $rows[] = $r;
}
usort($rows, fn($a, $b) => strcmp($b['ustvarjeno'], $a['ustvarjeno']));

page_start('Povpraševanja | Popravim vse');
echo '<h1>Povpraševanja</h1><p class="pv-small"><a href="/api/nastavitve.php?k=' . h(list_key()) . '">Nastavitve: UI pomočnik, koledar, delovni čas</a> · <a href="/api/klepeti.php?k=' . h(list_key()) . '">Pogovori s pomočnikom</a>' . (ui_ready() ? ' · UI je vključen' : ' · UI ni vključen') . '</p>';
if (!$rows) echo '<p class="lead">Še ni povpraševanj.</p>';
echo '<ul class="pv-list">';
foreach ($rows as $r) {
    echo '<li class="st-' . h($r['status']) . '"><a href="' . h(admin_url($r)) . '"><b>' . h($r['kraj'] ?: 'Kraj ni naveden') . '</b>'
        . '<span class="pv-badge">' . h(STATUS[$r['status']]) . '</span>'
        . '<span>' . h(sl_time($r['ustvarjeno'])) . ' · ' . h($r['telefon']) . ($r['datoteke'] ? ' · ' . h(counts($r['datoteke'])) : '') . '</span>'
        . ($r['opis'] !== '' ? '<span class="pv-snip">' . h(mb_strimwidth($r['opis'], 0, 140, '…')) . '</span>' : '') . '</a></li>';
}
echo '</ul><p class="pv-small">Povpraševanja se samodejno izbrišejo po ' . RETENTION_DAYS . ' dneh.</p>';
page_end();
