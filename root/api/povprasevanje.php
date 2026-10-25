<?php
/* Sprejem povpraševanja (obrazec na /povprasevanje/, brez JavaScripta ali kot JSON za agente UI).
 * Polja: telefon (obvezno), opis, kraj, ime, odgovor (sms|klic|email), email, upload_id (datoteke iz nalozi.php),
 *        media[] (neposredno nalaganje brez JS), agent (ime agenta, neobvezno), soglasje_stranke (za agente: true).
 * Odgovor: 303 na stran s stanjem ali JSON {ok, id, stanje}.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_obvestila.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /povprasevanje/', true, 303);
    exit;
}

$isJson = str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
$in = $isJson ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
$json = $isJson || wants_json();

function fail(string $msg, int $code, bool $json): never
{
    if ($json) json_out(['ok' => false, 'napaka' => $msg], $code);
    http_response_code($code);
    page_start('Povpraševanje ni bilo poslano | Popravim vse');
    echo '<h1>Povpraševanje ni bilo poslano</h1><p class="lead">' . h($msg) . '</p>'
        . '<p><a class="btn-plain" href="javascript:history.back()">Nazaj na obrazec</a> '
        . '<a class="call" href="tel:' . BIZ_PHONE_E164 . '">Pokličite ' . BIZ_PHONE . '</a></p>';
    page_end();
}

// past za robote: polje, ki ga človek ne vidi
if (trim((string)($in['spletna_stran'] ?? '')) !== '') {
    if ($json) json_out(['ok' => true, 'id' => 'x', 'stanje' => SITE . '/povprasevanje/']);
    header('Location: /povprasevanje/', true, 303);
    exit;
}
$tel = phone((string)($in['telefon'] ?? ''));
if (!$tel) fail('Vpišite telefonsko številko, na kateri vas dobim, na primer 041 123 456.', 422, $json);
$opis = clean((string)($in['opis'] ?? ''), MAX_TEXT);
$kraj = clean((string)($in['kraj'] ?? ''), 80);
$ime = clean((string)($in['ime'] ?? ''), 80);
$odg = (string)($in['odgovor'] ?? 'sms');
if (!isset(REPLY[$odg])) $odg = 'sms';
$email = trim((string)($in['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('E-poštni naslov ni pravilen.', 422, $json);
if ($odg === 'email' && $email === '') fail('Za odgovor po e-pošti vpišite e-poštni naslov.', 422, $json);
$agent = clean((string)($in['agent'] ?? ''), 120);
if ($isJson && empty($in['soglasje_stranke'])) {
    fail('Agent mora potrditi, da je stranka soglašala z oddajo povpraševanja in deljenjem telefonske številke (soglasje_stranke: true).', 422, true);
}

// zberi datoteke: iz nalaganja po kosih …
$files = [];
$uid = (string)($in['upload_id'] ?? '');
$tmpDir = preg_match('/^[a-f0-9]{32}$/', $uid) ? storage('nalaganje') . '/' . $uid : '';
$skip = array_map('intval', array_filter(explode(',', (string)($in['odstrani'] ?? '')), 'is_numeric'));
if ($tmpDir && is_dir($tmpDir)) {
    $list = glob("$tmpDir/*.json") ?: [];
    natsort($list);
    foreach ($list as $mf) {
        if (in_array((int)basename($mf, '.json'), $skip, true)) continue;
        $m = json_decode((string)file_get_contents($mf), true);
        $p = substr($mf, 0, -5) . '.part';
        if ($m && is_file($p) && filesize($p) === $m['velikost']) $files[] = ['pot' => $p, 'ime' => $m['ime'], 'tip' => $m['tip'], 'velikost' => $m['velikost']];
    }
}
// … ali neposredno (brez JavaScripta)
if (!empty($_FILES['media']['name']) && is_array($_FILES['media']['name'])) {
    foreach ($_FILES['media']['name'] as $i => $n) {
        if (($_FILES['media']['error'][$i] ?? 4) !== UPLOAD_ERR_OK) continue;
        $p = $_FILES['media']['tmp_name'][$i];
        $t = mime_of($p);
        if (!isset(MIME[$t]) || filesize($p) > MAX_FILE_BYTES) continue;
        $files[] = ['pot' => $p, 'ime' => clean((string)$n, 120), 'tip' => $t, 'velikost' => filesize($p), 'upload' => true];
    }
}
$files = array_slice($files, 0, MAX_SLOTS);

if ($opis === '' && !$files) fail('Opišite težavo ali dodajte fotografijo, video ali glasovno sporočilo.', 422, $json);
if (!rate_ok('oddaja', 8, 3600)) fail('Poslali ste že več povpraševanj. Če je nujno, me pokličite.', 429, $json);

// shrani
do { $id = date('Ymd') . '-' . substr(str_shuffle(str_repeat('abcdefghjkmnpqrstuvwxyz23456789', 3)), 0, 6); } while (is_dir(req_dir($id)));
$dir = req_dir($id);
mkdir($dir, 0700, true);
$stored = [];
foreach ($files as $n => $f) {
    [$kind, $ext] = MIME[$f['tip']];
    // sličice, ki jih brskalnik izreže iz videa (za UI oceno), niso prikazane kot fotografije stranke
    if ($kind === 'image' && str_starts_with($f['ime'], 'slicica-')) $kind = 'frame';
    $label = ['image' => 'foto', 'video' => 'video', 'audio' => 'glas', 'frame' => 'slicica'][$kind];
    $name = sprintf('%02d-%s.%s', $n + 1, $label, $ext);
    $ok = !empty($f['upload']) ? move_uploaded_file($f['pot'], "$dir/$name") : rename($f['pot'], "$dir/$name");
    if ($ok) $stored[] = ['datoteka' => $name, 'izvirno_ime' => $f['ime'], 'tip' => $f['tip'], 'vrsta' => $kind, 'velikost' => $f['velikost']];
}
if ($tmpDir && is_dir($tmpDir)) rrmdir($tmpDir);

$r = [
    'id' => $id, 'ustvarjeno' => date('c'), 'token' => bin2hex(random_bytes(16)), 'status' => 'prejeto',
    'zgodovina' => [['status' => 'prejeto', 'cas' => date('c')]],
    'telefon' => $tel[0], 'telefon_e164' => $tel[1], 'ime' => $ime, 'kraj' => $kraj, 'opis' => $opis,
    'odgovor' => $odg, 'email' => $email, 'datoteke' => $stored,
    'vir' => $isJson ? 'agent' : (empty($in['js']) ? 'obrazec-brez-js' : 'obrazec'), 'agent' => $agent,
    'naprava' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
];
save_req($r);

// najprej odgovor stranki (ne čaka na UI in e-pošto) …
ob_start();
if ($json) {
    http_response_code(201);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'id' => $id, 'stanje' => status_url($r),
        'sporocilo' => 'Povpraševanje je prejeto. Sebastjan ga pregleda sam in odgovori ' . REPLY_PO[$odg] . '. Termin ni potrjen, dokler ga ne potrdi Sebastjan.'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    header('Location: ' . status_url($r) . '&novo=1', true, 303);
}
finish_response();

// … nato UI ocena (če je vključena) in obvestila
process_new($id);
housekeeping();
