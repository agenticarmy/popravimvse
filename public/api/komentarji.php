<?php
/* Sebastjanov dnevni pregled predlogov za komentarje na YouTubu. ?k=… [&pogled=kanali|objave] [&korak=1]
 * Komentar se objavi SAMO, ko Sebastjan klikne »Objavi«. Besedilo lahko pred tem popravi.
 */
declare(strict_types=1);
require __DIR__ . '/_komentarji.php';

$k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
if (!hash_equals(list_key(), $k)) { http_response_code(404); exit; }
$cfg = config();
$base = '/api/komentarji.php?k=' . rawurlencode($k);
$go = function (string $q = '', string $anchor = '') use ($base): never {
    header('Location: ' . $base . $q . ($anchor !== '' ? '#' . $anchor : ''), true, 303);
    exit;
};
$msgq = fn(bool $ok, string $m) => '&' . ($ok ? 'ok' : 'napaka') . '=' . rawurlencode($m);

/* ---------- dejanja ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $a = (string)($_POST['akcija'] ?? '');
    $vid = (string)($_POST['v'] ?? '');
    $ch = (string)($_POST['kanal'] ?? '');
    $view = in_array($_POST['pogled'] ?? '', ['kanali', 'objave'], true) ? '&pogled=' . $_POST['pogled'] : '';
    switch ($a) {
        case 'objavi':
            [$ok, $m] = kom_post($cfg, $vid, (string)($_POST['besedilo'] ?? ''));
            $go($msgq($ok, $m), 'v-' . $vid);
        case 'odgovori':
            [$ok, $m] = kom_post($cfg, $vid, (string)($_POST['besedilo'] ?? ''), (string)($_POST['c'] ?? ''));
            $go($msgq($ok, $m), 'v-' . $vid);
        case 'preskoci':
            if ($v = kom_video($vid)) {
                if ($v['stanje'] === 'predlog') $v['stanje'] = 'preskoceno';
                $v['zakljuceno'] = date('c');
                kom_save_video($v);
            }
            $go('', 'predlogi');
        case 'znova':
            if (($v = kom_video($vid)) && in_array($v['stanje'], ['napaka', 'neprimerno'], true)) {
                $v['stanje'] = 'cakam';
                $v['predlog_dan'] = date('Y-m-d');
                kom_save_video($v);
                $go('&korak=1');
            }
            $go();
        case 'blokiraj':
        case 'spremljaj':
        case 'odstrani':
            if (kom_valid_ch($ch) && $ch !== YT_KANAL) {
                $kan = kom_kanali();
                $c = $kan[$ch] ?? ['handle' => '', 'dodan' => date('c'), 'vir' => 'rocno'];
                $name = (string)($c['ime'] ?? '');
                if ($name === '' && ($v = kom_video($vid))) $name = $v['kanal'];
                $c['ime'] = $name;
                $c['stanje'] = $a === 'spremljaj' ? 'spremljam' : 'blokiran';
                $c['osvezeno'] = date('c');
                $kan[$ch] = $c;
                if ($a === 'odstrani') unset($kan[$ch]); // ne spremljaj več (iskanje ga lahko znova predlaga)
                kom_save_kanali($kan);
                if ($a === 'blokiraj') { // odstrani še odprte predloge s tega kanala
                    foreach (kom_videos(['predlog', 'primeren', 'cakam', 'nov']) as $x) {
                        if ($x['kanal_id'] === $ch) { $x['stanje'] = 'izlocen'; $x['razlog'] = 'kanal ste izločili'; kom_save_video($x); }
                    }
                }
                $txt = ['spremljaj' => 'Kanal »' . $name . '« zdaj spremljam.', 'blokiraj' => 'S kanala »' . $name . '« ne bom več predlagal videov.',
                    'odstrani' => 'Kanala »' . $name . '« ne spremljam več.'][$a];
                $go($msgq(true, $txt) . $view, $a === 'blokiraj' ? 'predlogi' : 'kanali');
            }
            $go($view);
        case 'dodaj_kanal':
            [$id, $name, $err, $handle] = kom_resolve_channel($cfg, (string)($_POST['vnos'] ?? ''));
            if (!$id) $go($msgq(false, 'Kanala ni bilo mogoče dodati: ' . $err . '.') . '&pogled=kanali', 'kanali');
            if ($id === YT_KANAL) $go($msgq(false, 'To je vaš kanal.') . '&pogled=kanali', 'kanali');
            $kan = kom_kanali();
            $kan[$id] = ['ime' => $name, 'handle' => $handle, 'stanje' => 'spremljam', 'dodan' => date('c'), 'vir' => 'rocno', 'osvezeno' => date('c')];
            kom_save_kanali($kan);
            $go($msgq(true, 'Kanal »' . $name . '« je dodan. Novi videi s tega kanala pridejo v predloge ob naslednjem iskanju.') . '&pogled=kanali', 'kanali');
        case 'izbrisi':
            [$ok, $m] = kom_delete($cfg, (string)($_POST['c'] ?? ''));
            $go($msgq($ok, $m) . $view, $vid !== '' ? 'v-' . $vid : '');
        case 'odklopi':
            yt_disconnect($cfg);
            $go($msgq(true, 'YouTube je odklopljen. Dovoljenje je preklicano.'));
        case 'zazeni':
            if ($miss = kom_missing($cfg)) $go($msgq(false, 'Najprej uredite: ' . implode('; ', $miss) . '.'));
            if (!kom_budget_left($cfg)) $go($msgq(false, 'Mesečni proračun je porabljen. Povečate ga lahko v Nastavitvah.'));
            $lock = kom_lock();
            if (!$lock) $go($msgq(false, 'Iskanje že teče. Počakajte minuto in osvežite stran.'));
            @set_time_limit(300);
            ignore_user_abort(true);
            $log = [];
            try {
                kom_housekeeping();
                kom_collect($cfg, function ($m) use (&$log) { $log[] = $m; });
                kom_triage($cfg, function ($m) use (&$log) { $log[] = $m; }, 2); // ostale razvrsti obdelava po korakih
                kom_select($cfg);
            } finally {
                kom_unlock($lock);
            }
            $errs = array_filter($log, fn($m) => !preg_match('/^(novih videov|razvrščenih):/u', $m));
            $go('&korak=1' . ($errs ? $msgq(false, implode(' · ', array_slice($errs, 0, 4))) : ''));
    }
    $go();
}

/* ---------- obdelava po korakih (en video na zahtevo, stran se sama osvežuje) ---------- */
if (isset($_GET['korak'])) {
    $lock = kom_lock();
    $done = null;
    if ($lock) {
        @set_time_limit(300);
        ignore_user_abort(true);
        try {
            $tri = kom_videos(['nov']) ? kom_triage($cfg, fn($m) => null, 2) : 0; // prvi zagon: veliko videov za razvrščanje
            kom_select($cfg);
            $done = $tri ? 'razvrščanje' : kom_process_one($cfg, fn($m) => null);
        } finally {
            kom_unlock($lock);
        }
    }
    $left = count(kom_videos(['cakam'])) + count(kom_videos(['nov']));
    $n = (int)($_GET['n'] ?? 0) + ($done && $done !== 'razvrščanje' ? 1 : 0);
    if ($left === 0 || ($lock && !$done)) {
        $t = $left && !kom_budget_left($cfg) ? 'Mesečni proračun je porabljen, preostali videi počakajo.' : 'Iskanje je končano.';
        $go('&ok=' . rawurlencode($t . ($n ? ' Obdelanih videov: ' . $n . '.' : ' Novih predlogov ni.')), 'predlogi');
    }
    $next = $base . '&korak=1&n=' . $n . (isset($_GET['napaka']) ? '&napaka=' . rawurlencode((string)$_GET['napaka']) : '');
    page_start('Pripravljam predloge | Popravim vse', '<meta http-equiv="refresh" content="1;url=' . h($next) . '">');
    echo '<div class="pv-card pv-ai-wait"><h1>Pripravljam predloge …</h1><p>Gemini si ogleda video, Claude napiše povzetek in predlog komentarja. En video traja približno minuto.</p>'
        . '<p>Obdelanih: ' . $n . ' · še ' . $left . '</p>' . (isset($_GET['napaka']) ? '<p class="pv-small">' . h((string)$_GET['napaka']) . '</p>' : '')
        . '<p class="pv-small">Stran se osvežuje sama. Lahko jo zaprete – preostale videe obdela jutranji zagon.</p></div>';
    page_end();
}

/* ---------- prikaz ---------- */
$view = in_array($_GET['pogled'] ?? '', ['kanali', 'objave'], true) ? $_GET['pogled'] : 'predlogi';
$kan = kom_kanali();
$all = kom_videos();
// odprti predlogi + objavljeni, dokler ni vse objavljeno ali Sebastjan ne klikne »Končano« (objavljeni komentar ostane viden še 12 ur)
$open = array_values(array_filter($all, fn($v) => $v['stanje'] === 'predlog'
    || ($v['stanje'] === 'objavljeno' && empty($v['zakljuceno']) && (array_filter($v['odgovori'] ?? [], fn($o) => empty($o['objava']))
        || strtotime((string)($v['objava']['cas'] ?? '')) > time() - 12 * 3600))));
usort($open, fn($a, $b) => [$b['predlog_dan'] ?? '', $b['triaza']['ocena'] ?? 0] <=> [$a['predlog_dan'] ?? '', $a['triaza']['ocena'] ?? 0]);
$waiting = count(array_filter($all, fn($v) => $v['stanje'] === 'cakam'));
$failed = array_values(array_filter($all, fn($v) => $v['stanje'] === 'napaka'));
$sugg = array_filter($kan, fn($c) => ($c['stanje'] ?? '') === 'predlog');
$posts = kom_read(kom_dir() . '/objave.json', []);
$u = kom_usage();
$st = kom_state();
$oa = yt_oauth();
$miss = kom_missing($cfg);

$extra = '<link rel="stylesheet" href="/static/css/komentarji.css?v=' . @filemtime(dirname(__DIR__) . '/static/css/komentarji.css') . '">';
page_start('Komentarji na YouTubu | Popravim vse', $extra);
echo '<p class="pv-small"><a href="/api/seznam.php?k=' . h($k) . '">← Povpraševanja</a> · <a href="/api/nastavitve.php?k=' . h($k) . '#komentarji">Nastavitve</a></p>';
echo '<h1>Komentarji na YouTubu</h1>';
if (isset($_GET['ok'])) echo '<p class="pv-okmsg" role="status">' . h((string)$_GET['ok']) . '</p>';
if (isset($_GET['napaka'])) echo '<p class="pv-err pv-err-main" role="alert">' . h((string)$_GET['napaka']) . '</p>';

// stanje
echo '<section class="pv-card kom-status"><ul class="pv-facts">';
if (yt_connected()) {
    echo '<li>✓ Povezano s kanalom <b>' . h($oa['kanal_ime'] ?? YT_KANAL_IME) . '</b></li>';
} elseif (!empty($oa['napaka'])) {
    echo '<li class="kom-bad">' . h($oa['napaka']) . '</li>';
}
foreach ($miss as $m) echo '<li class="kom-bad">Manjka: ' . h($m) . '</li>';
if ($cfg['gemini_kljuc'] === '') echo '<li class="kom-bad">Ključ Gemini ni nastavljen: povzetki bodo samo iz naslova in opisa.</li>';
if (empty($cfg['kom_vklop'])) echo '<li class="kom-bad">Jutranje iskanje ni vključeno (Nastavitve). Iščete lahko ročno.</li>';
echo '<li>Ta mesec: ' . number_format(kom_spent(), 2, ',', '') . ' $ od ' . (int)$cfg['kom_proracun'] . ' $ · ' . (int)$u['videi'] . ' videov · ' . (int)$u['objave'] . ' objav</li>';
echo '<li>Danes objavljenih: ' . kom_posts_today() . ' od največ ' . (int)$cfg['kom_objav_na_dan'] . '</li>';
echo '<li>Zadnje iskanje: ' . (!empty($st['zadnji_zagon']) ? h(sl_time($st['zadnji_zagon'])) : 'še nikoli') . '</li></ul>';
echo '<div class="pv-actions">';
if (!yt_connected() && $cfg['yt_client_id'] !== '') echo '<a class="call" href="/api/yt-oauth.php?k=' . h($k) . '&amp;zacni=1">Poveži YouTube</a>';
echo '<form method="post"><input type="hidden" name="k" value="' . h($k) . '"><button class="btn-plain" name="akcija" value="zazeni">Poišči nove videe zdaj</button></form>';
if ($waiting) echo '<a class="btn-plain" href="' . h($base . '&korak=1') . '">Obdelaj čakajoče (' . $waiting . ')</a>';
echo '</div></section>';

// zavihki
$tabs = ['predlogi' => 'Predlogi (' . count($open) . ')', 'kanali' => 'Kanali' . ($sugg ? ' (' . count($sugg) . ' novih)' : ''), 'objave' => 'Objavljeno'];
echo '<nav class="kom-tabs" aria-label="Pogledi">';
foreach ($tabs as $t => $l) {
    echo '<a href="' . h($base . ($t === 'predlogi' ? '' : '&pogled=' . $t)) . '"' . ($t === $view ? ' aria-current="page"' : '') . '>' . h($l) . '</a>';
}
echo '</nav>';

$hid = fn(array $f) => implode('', array_map(fn($n, $v) => '<input type="hidden" name="' . h($n) . '" value="' . h((string)$v) . '">', array_keys($f), $f));
$ytv = fn(string $id, string $lc = '') => 'https://www.youtube.com/watch?v=' . rawurlencode($id) . ($lc !== '' ? '&lc=' . rawurlencode($lc) : '');
$done = function (?array $o, string $vid, string $what) use ($hid, $k, $ytv): string {
    if (!$o) return '';
    if (!empty($o['izbrisan'])) return '<p class="pv-small">' . $what . ' izbrisan ' . h(sl_time($o['izbrisan'])) . '.</p>';
    return '<p class="pv-okmsg kom-posted">' . $what . ' objavljen ' . h(sl_time($o['cas'])) . ' · <a href="' . h($ytv($vid, $o['id'])) . '" target="_blank" rel="noopener">Odpri na YouTubu</a></p>'
        . '<p class="pv-quote">' . nl2br(h($o['besedilo'])) . '</p>'
        . '<details class="kom-del"><summary>Izbriši</summary><form method="post">' . $hid(['k' => $k, 'akcija' => 'izbrisi', 'v' => $vid, 'c' => $o['id']])
        . '<button class="btn-plain">Res izbriši z YouTuba</button></form></details>';
};

if ($view === 'predlogi') {
    echo '<section id="predlogi">';
    if (!$open) echo '<p class="lead">Ni odprtih predlogov.' . ($waiting ? ' ' . $waiting . ' videov čaka na obdelavo.' : ' Novi pridejo z jutranjim iskanjem.') . '</p>';
    foreach ($open as $v) {
        $ui = $v['ui'] ?? [];
        $g = $v['gemini'] ?? null;
        $id = $v['id'];
        echo '<article class="pv-card kom-card" id="v-' . h($id) . '">';
        echo '<div class="kom-head"><a class="kom-thumb" href="' . h($ytv($id)) . '" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/' . h($id) . '/mqdefault.jpg" width="320" height="180" alt="" loading="lazy">'
            . '<span>' . h(kom_dur((int)$v['trajanje'])) . '</span></a><div>'
            . '<p class="kom-meta">' . h($v['kanal']) . ' · ' . h($v['objavljen'] ? date('j. n. Y', strtotime($v['objavljen'])) : '') . ' · ' . kom_num((int)$v['ogledi']) . ' ogledov · ' . max(0, (int)$v['komentarjev']) . ' komentarjev</p>'
            . '<h2><a href="' . h($ytv($id)) . '" target="_blank" rel="noopener">' . h($v['naslov']) . '</a></h2>'
            . '<p class="kom-tags"><span class="pv-badge">' . (int)($v['triaza']['ocena'] ?? 0) . '/10</span> ' . h($ui['tema'] ?? ($v['triaza']['tema'] ?? ''))
            . (!empty($v['spremljan']) ? ' · spremljan kanal' : ($v['vir'] === 'iskanje' ? ' · iz iskanja' : '')) . '</p></div></div>';
        if (!empty($ui['povzetek'])) echo '<h3>O čem govori</h3><ul class="kom-sum">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $ui['povzetek'])) . '</ul>';
        if (!empty($ui['zakaj'])) echo '<p><b>Zakaj za vas:</b> ' . h($ui['zakaj']) . '</p>';
        if (!empty($ui['opozorila'])) echo '<ul class="pv-warn">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $ui['opozorila'])) . '</ul>';
        if (!empty($v['gemini_napaka'])) echo '<p class="pv-small">Ogled videa: ' . h($v['gemini_napaka']) . '</p>';
        if ($g) {
            echo '<details class="kom-more"><summary>Kaj se vidi in prepis govora</summary>';
            if ($g['kaj_se_vidi'] !== '') echo '<p>' . h($g['kaj_se_vidi']) . '</p>';
            foreach (['koraki' => 'Koraki', 'material_in_orodje' => 'Material in orodje', 'nasveti_avtorja' => 'Nasveti avtorja', 'tveganja' => 'Tveganja'] as $f => $l) {
                if (!empty($g[$f])) echo '<p class="pv-small"><b>' . $l . ':</b> ' . h(implode(' · ', $g[$f])) . '</p>';
            }
            if ($g['prepis'] !== '') echo '<p class="pv-transcript">' . nl2br(h($g['prepis'])) . '</p>';
            echo '</details>';
        }

        // komentar pod video
        if (!empty($v['objava'])) {
            echo $done($v['objava'], $id, 'Komentar');
        } else {
            $len = mb_strlen($ui['komentar'] ?? '');
            echo '<form method="post" class="pv-reply kom-form">' . $hid(['k' => $k, 'akcija' => 'objavi', 'v' => $id])
                . '<label class="pv-label" for="t-' . h($id) . '">Komentar pod video</label>'
                . '<textarea id="t-' . h($id) . '" name="besedilo" rows="5" maxlength="' . KOM_MAX_ZNAKOV . '" required>' . h($ui['komentar'] ?? '') . '</textarea>'
                . '<p class="pv-small kom-count" aria-live="polite">' . $len . ' znakov</p>';
            if (!empty($ui['alternativa'])) {
                echo '<details class="kom-alt"><summary>Druga različica</summary><p class="pv-quote">' . nl2br(h($ui['alternativa'])) . '</p>'
                    . '<button type="button" class="btn-plain kom-use" data-t="' . h($ui['alternativa']) . '">Uporabi to različico</button></details>';
            }
            echo '<div class="pv-actions"><button class="call kom-send">Objavi komentar</button>'
                . '<a class="btn-plain" href="' . h($ytv($id)) . '" target="_blank" rel="noopener">Odpri video</a></div></form>';
        }

        // odgovori na vprašanja gledalcev
        foreach ($v['odgovori'] ?? [] as $n => $o) {
            echo '<div class="kom-reply"><p class="pv-small">Vprašanje gledalca <b>' . h($o['avtor']) . '</b>:</p><blockquote>' . nl2br(h($o['vprasanje'])) . '</blockquote>';
            if (!empty($o['opozorila'])) echo '<ul class="pv-warn">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $o['opozorila'])) . '</ul>';
            if (!empty($o['objava'])) {
                echo $done($o['objava'], $id, 'Odgovor');
            } else {
                echo '<form method="post" class="pv-reply kom-form">' . $hid(['k' => $k, 'akcija' => 'odgovori', 'v' => $id, 'c' => $o['komentar_id']])
                    . '<label class="pv-label" for="o-' . h($id) . '-' . $n . '">Vaš odgovor</label>'
                    . '<textarea id="o-' . h($id) . '-' . $n . '" name="besedilo" rows="3" maxlength="' . KOM_MAX_ZNAKOV . '" required>' . h($o['predlog']) . '</textarea>'
                    . '<p class="pv-small kom-count" aria-live="polite">' . mb_strlen($o['predlog']) . ' znakov</p>'
                    . '<div class="pv-actions"><button class="call kom-send">Objavi odgovor</button>'
                    . '<a class="btn-plain" href="' . h($ytv($id, $o['komentar_id'])) . '" target="_blank" rel="noopener">Odpri vprašanje</a></div></form>';
            }
            echo '</div>';
        }

        echo '<form method="post" class="kom-skip">' . $hid(['k' => $k, 'v' => $id, 'kanal' => $v['kanal_id']])
            . '<button class="btn-plain" name="akcija" value="preskoci">' . ($v['stanje'] === 'objavljeno' ? 'Končano' : 'Preskoči') . '</button>'
            . (($kan[$v['kanal_id']]['stanje'] ?? '') === 'spremljam' ? '' : '<button class="btn-plain" name="akcija" value="spremljaj">Spremljaj ta kanal</button>')
            . '<button class="btn-plain kom-quiet" name="akcija" value="blokiraj">Ne predlagaj več s tega kanala</button></form>';
        echo '</article>';
    }
    if ($failed) {
        echo '<h2>Ni uspelo</h2><ul class="pv-list">';
        foreach ($failed as $v) {
            echo '<li><form method="post" class="kom-fail">' . $hid(['k' => $k, 'akcija' => 'znova', 'v' => $v['id']]) . '<b>' . h($v['naslov']) . '</b><span>' . h($v['napaka'] ?? '') . '</span>'
                . '<button class="btn-plain">Poskusi znova</button></form></li>';
        }
        echo '</ul>';
    }
    echo '</section>';
}

if ($view === 'kanali') {
    echo '<section id="kanali">';
    if ($sugg) {
        echo '<h2>Predlagani kanali</h2><p class="pv-small">Našlo jih je iskanje. Spremljani kanali imajo pri predlogih prednost.</p><ul class="kom-chs">';
        foreach ($sugg as $id => $c) {
            echo '<li><div><a href="https://www.youtube.com/channel/' . h($id) . '" target="_blank" rel="noopener"><b>' . h($c['ime']) . '</b></a>'
                . (!empty($c['primer']) ? '<span class="pv-small">npr. »' . h($c['primer']) . '«</span>' : '') . '</div>'
                . '<form method="post">' . $hid(['k' => $k, 'kanal' => $id, 'pogled' => 'kanali'])
                . '<button class="call small" name="akcija" value="spremljaj">Spremljaj</button><button class="btn-plain" name="akcija" value="blokiraj">Ne</button></form></li>';
        }
        echo '</ul>';
    }
    $follow = array_filter($kan, fn($c) => ($c['stanje'] ?? '') === 'spremljam');
    echo '<h2>Spremljani kanali (' . count($follow) . ')</h2>';
    if (!$follow) echo '<p>Še ne spremljate nobenega kanala. Dodajte ga spodaj ali potrdite predloge iz iskanja.</p>';
    echo '<ul class="kom-chs">';
    foreach ($follow as $id => $c) {
        echo '<li><div><a href="https://www.youtube.com/channel/' . h($id) . '" target="_blank" rel="noopener"><b>' . h($c['ime'] ?: $id) . '</b></a>'
            . (!empty($c['handle']) ? '<span class="pv-small">' . h($c['handle']) . '</span>' : '') . '</div>'
            . '<form method="post">' . $hid(['k' => $k, 'kanal' => $id, 'pogled' => 'kanali'])
            . '<button class="btn-plain" name="akcija" value="odstrani">Ne spremljaj</button></form></li>';
    }
    echo '</ul>';
    echo '<form method="post" class="pv-reply kom-add">' . $hid(['k' => $k, 'akcija' => 'dodaj_kanal', 'pogled' => 'kanali'])
        . '<label class="pv-label" for="vnos">Dodaj kanal</label><input id="vnos" name="vnos" type="text" placeholder="@imekanala ali povezava na kanal ali video" required spellcheck="false" autocomplete="off">'
        . '<button class="call">Dodaj in spremljaj</button></form>';
    $blocked = array_filter($kan, fn($c) => ($c['stanje'] ?? '') === 'blokiran');
    if ($blocked) {
        echo '<details class="kom-more"><summary>Izločeni kanali (' . count($blocked) . ')</summary><ul class="kom-chs">';
        foreach ($blocked as $id => $c) {
            echo '<li><div>' . h($c['ime'] ?: $id) . '</div><form method="post">' . $hid(['k' => $k, 'kanal' => $id, 'pogled' => 'kanali'])
                . '<button class="btn-plain" name="akcija" value="spremljaj">Vseeno spremljaj</button></form></li>';
        }
        echo '</ul></details>';
    }
    echo '</section>';
}

if ($view === 'objave') {
    echo '<section id="objave"><h2>Objavljeni komentarji</h2>';
    if (!$posts) echo '<p>Še niste objavili nobenega komentarja.</p>';
    echo '<ul class="kom-posts">';
    foreach (array_slice($posts, 0, 100) as $p) {
        echo '<li><p class="pv-small">' . h(sl_time($p['cas'])) . ' · ' . ($p['vrsta'] === 'odgovor' ? 'odgovor gledalcu' : 'komentar') . (!empty($p['kanal']) ? ' · ' . h($p['kanal']) : '') . '</p>'
            . (!empty($p['naslov']) ? '<p><b>' . h($p['naslov']) . '</b></p>' : '')
            . '<p class="pv-quote">' . nl2br(h($p['besedilo'])) . '</p>';
        if (!empty($p['izbrisan'])) {
            echo '<p class="pv-small">Izbrisan ' . h(sl_time($p['izbrisan'])) . '.</p>';
        } else {
            echo '<div class="pv-actions"><a class="btn-plain" href="' . h($ytv($p['video_id'], $p['komentar_id'])) . '" target="_blank" rel="noopener">Odpri na YouTubu</a>'
                . '<details class="kom-del"><summary>Izbriši</summary><form method="post">' . $hid(['k' => $k, 'akcija' => 'izbrisi', 'c' => $p['komentar_id'], 'pogled' => 'objave'])
                . '<button class="btn-plain">Res izbriši z YouTuba</button></form></details></div>';
        }
        echo '</li>';
    }
    echo '</ul><p class="pv-small">Naslovi tujih videov se po 30 dneh izbrišejo, vaše besedilo ostane.</p></section>';
}

echo '<p class="pv-small kom-foot">Komentar se objavi samo, ko kliknete »Objavi«. Podatki tujih videov se izbrišejo po ' . KOM_HRAMBA_DNI . ' dneh.</p>';
?>
<script>
document.querySelectorAll('.kom-form').forEach(function (f) {
  var t = f.querySelector('textarea'), c = f.querySelector('.kom-count');
  function upd() { if (c) c.textContent = t.value.length + ' znakov' + (/https?:\/\/|www\.|\.si\b|\.com\b/i.test(t.value) ? ' · brez povezav, prosim' : ''); }
  function grow() { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 4) + 'px'; }
  t.addEventListener('input', function () { upd(); grow(); });
  grow();
  f.addEventListener('submit', function () { var b = f.querySelector('.kom-send'); if (b) { b.disabled = true; b.textContent = 'Objavljam …'; } });
  var u = f.querySelector('.kom-use');
  if (u) u.addEventListener('click', function () { t.value = u.getAttribute('data-t'); upd(); grow(); t.focus(); });
});
</script>
<?php page_end();
