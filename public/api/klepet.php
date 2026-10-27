<?php
/* Pomočnik »Poskusite sami« (javni). GET ?stanje=1 → ali deluje. POST JSON:
 * {id?, t?, zgodovina: [{vloga: "obiskovalec"|"pomocnik", besedilo, podpis?}], sporocilo, soglasje: bool, stran: "/pot/"}
 * Zgodovino hrani brskalnik; strežnik jo preveri (podpisi odgovorov) in jo shrani samo, če je obiskovalec soglašal.
 */
declare(strict_types=1);
require __DIR__ . '/_klepet.php';

header('X-Robots-Tag: noindex, nofollow');
$cfg = config();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => true, 'deluje' => kl_ready($cfg) && kl_budget_left($cfg), 'najvec_znakov' => KL_MAX_MSG, 'najvec_sporocil' => KL_MAX_TURNS]);
}

$busy = 'Pomočnik je ta trenutek nedosegljiv. Pošljite video Sebastjanu ali ga pokličite na ' . BIZ_PHONE . '.';
if (!kl_ready($cfg)) json_out(['ok' => false, 'napaka' => $busy], 503);

// samo z lastne strani
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && $origin !== SITE) json_out(['ok' => false, 'napaka' => 'Napačen izvor.'], 403);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 200_000), true);
if (!is_array($in)) json_out(['ok' => false, 'napaka' => 'Napačna zahteva.'], 400);

$id = (string)($in['id'] ?? '');
$isNew = $id === '';
if ($isNew) $id = kl_new_id();
elseif (!kl_valid_id($id) || !hash_equals(kl_token($id), (string)($in['t'] ?? ''))) json_out(['ok' => false, 'napaka' => 'Pogovor ni veljaven. Začnite novega.', 'nov' => true], 400);

// zgodovina: preveri izmenično zaporedje, dolžine in podpise odgovorov
$hist = is_array($in['zgodovina'] ?? null) ? $in['zgodovina'] : [];
$msgs = [];
foreach (array_values($hist) as $i => $h) {
    $role = $i % 2 === 0 ? 'obiskovalec' : 'pomocnik';
    $txt = (string)($h['besedilo'] ?? '');
    if (($h['vloga'] ?? '') !== $role || $txt === '' || mb_strlen($txt) > ($role === 'obiskovalec' ? KL_MAX_MSG : KL_MAX_REPLY)
        || ($role === 'pomocnik' && !hash_equals(kl_sig($id, $i, $txt), (string)($h['podpis'] ?? '')))) {
        json_out(['ok' => false, 'napaka' => 'Pogovora ni bilo mogoče nadaljevati. Začnite novega.', 'nov' => true], 400);
    }
    $msgs[] = ['role' => $role === 'obiskovalec' ? 'user' : 'assistant', 'content' => $txt];
}
if (count($msgs) % 2 !== 0) json_out(['ok' => false, 'napaka' => 'Pogovora ni bilo mogoče nadaljevati. Začnite novega.', 'nov' => true], 400);

// samo sprememba soglasja (kljukica) za obstoječ pogovor: shrani ali izbriši, brez klica UI
if (($in['akcija'] ?? '') === 'soglasje') {
    if ($isNew || !$msgs) json_out(['ok' => true, 'shranjeno' => false]);
    if (!rate_ok('klepet-soglasje', 30, 3600)) json_out(['ok' => false, 'napaka' => 'Preveč sprememb.'], 429);
    if (!empty($in['soglasje'])) {
        $pred = clean((string)($in['predaja'] ?? ''), 600);
        kl_store($id, (string)($in['stran'] ?? '/'), $msgs, $pred !== '' ? $pred : null);
        json_out(['ok' => true, 'shranjeno' => true]);
    }
    if (is_file(kl_file($id))) @unlink(kl_file($id));
    json_out(['ok' => true, 'shranjeno' => false]);
}

$msg = clean((string)($in['sporocilo'] ?? ''), KL_MAX_MSG + 50);
if ($msg === '') json_out(['ok' => false, 'napaka' => 'Napišite vprašanje.'], 400);
if (mb_strlen($msg) > KL_MAX_MSG) json_out(['ok' => false, 'napaka' => 'Sporočilo je predolgo. Največ ' . KL_MAX_MSG . ' znakov.'], 400);
if (count($msgs) / 2 >= KL_MAX_TURNS) {
    json_out(['ok' => false, 'napaka' => 'Pogovor je dosegel omejitev. Če težave niste rešili, pošljite video Sebastjanu – ocena je brezplačna.', 'konec' => true], 429);
}

// omejitve proti zlorabi (po IP, ki se ne shrani v pogovor)
if (($isNew && !rate_ok('klepet-novo', 6, 86400)) || !rate_ok('klepet-min', 6, 60) || !rate_ok('klepet-dan', 60, 86400)) {
    json_out(['ok' => false, 'napaka' => 'Preveč sporočil v kratkem času. Poskusite znova čez nekaj minut ali pošljite video Sebastjanu.'], 429);
}
if (!rate_ok('klepet-vsi', 400, 86400, true)) json_out(['ok' => false, 'napaka' => $busy], 503);
if (!kl_budget_left($cfg)) json_out(['ok' => false, 'napaka' => $busy], 503);

$ctx = ui_context();
$path = (string)($in['stran'] ?? '/');
$known = array_column($ctx['strani'] ?? [], 'naslov', 'pot');
$pageNote = isset($known[$path]) ? "(Obiskovalec piše s strani: {$known[$path]}, " . SITE . "$path)\n\n" : '';
$msgs[] = ['role' => 'user', 'content' => ($isNew ? $pageNote : '') . $msg];

$model = $cfg['klepet_model'] ?: $cfg['claude_model'];
[$code, $j, $err] = http_json((getenv('PV_ANTHROPIC_URL') ?: 'https://api.anthropic.com') . '/v1/messages', [
    'x-api-key: ' . $cfg['claude_kljuc'], 'anthropic-version: 2023-06-01', 'content-type: application/json',
], json_encode([
    'model' => $model, 'max_tokens' => KL_MAX_TOKENS,
    'system' => [['type' => 'text', 'text' => kl_system($ctx, $path), 'cache_control' => ['type' => 'ephemeral']]],
    'messages' => $msgs,
], JSON_UNESCAPED_UNICODE), 60);

$text = '';
foreach ($j['content'] ?? [] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
if ($code !== 200 || trim($text) === '') {
    error_log('klepet: ' . ($err ?: "HTTP $code"));
    json_out(['ok' => false, 'napaka' => $busy], 502);
}
kl_add_usage(kl_cost($j['usage'] ?? [], $model), $isNew);

[$reply, $handoff] = kl_split(trim($text));
$reply = mb_substr($reply, 0, KL_MAX_REPLY);
$n = count($msgs); // indeks tega odgovora v zgodovini

// shrani samo s soglasjem (brez IP in brez podatkov brskalnika)
if (!empty($in['soglasje'])) {
    kl_store($id, $path, array_merge(array_slice($msgs, 0, -1), [['role' => 'user', 'content' => $msg], ['role' => 'assistant', 'content' => $reply]]), $handoff);
} elseif (is_file(kl_file($id))) {
    @unlink(kl_file($id)); // soglasje preklicano
}
kl_housekeeping();

json_out(['ok' => true, 'id' => $id, 't' => kl_token($id), 'odgovor' => $reply, 'podpis' => kl_sig($id, $n, $reply),
    'predaja' => $handoff, 'ostalo' => KL_MAX_TURNS - (int)($n / 2) - 1]);
