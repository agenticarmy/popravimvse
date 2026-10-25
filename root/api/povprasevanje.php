<?php
/* Sprejem povpraševanja (obrazec na /povprasevanje/, brez JavaScripta ali kot JSON za agente UI).
 * Polja: telefon (obvezno), opis, kraj, ime, odgovor (sms|klic|email), email, upload_id (datoteke iz nalozi.php),
 *        media[] (neposredno nalaganje brez JS), agent (ime agenta, neobvezno), soglasje_stranke (za agente: true).
 * Odgovor: 303 na stran s stanjem ali JSON {ok, id, stanje}.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

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
$files = array_slice($files, 0, MAX_FILES);

if ($opis === '' && !$files) fail('Opišite težavo ali dodajte fotografijo, video ali glasovno sporočilo.', 422, $json);
if (!rate_ok('oddaja', 8, 3600)) fail('Poslali ste že več povpraševanj. Če je nujno, me pokličite.', 429, $json);

// shrani
do { $id = date('Ymd') . '-' . substr(str_shuffle(str_repeat('abcdefghjkmnpqrstuvwxyz23456789', 3)), 0, 6); } while (is_dir(req_dir($id)));
$dir = req_dir($id);
mkdir($dir, 0700, true);
$stored = [];
foreach ($files as $n => $f) {
    [$kind, $ext] = MIME[$f['tip']];
    $name = sprintf('%02d-%s.%s', $n + 1, $kind === 'image' ? 'foto' : ($kind === 'video' ? 'video' : 'glas'), $ext);
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

// obvestilo Sebastjanu
$what = counts($stored);
$subject = 'Povpraševanje: ' . ($kraj ?: 'kraj ni naveden') . ($what ? ' – ' . $what : '') . ' (' . $tel[0] . ')';
$ogled = admin_url($r);
$cal = 'https://calendar.google.com/calendar/render?' . http_build_query([
    'action' => 'TEMPLATE',
    'text' => 'Popravim vse: ' . ($kraj ?: 'stranka') . ' – ' . ($ime ?: $tel[0]),
    'details' => trim(mb_substr($opis, 0, 600) . "\n\nTelefon: " . $tel[0] . "\nPovpraševanje: " . $ogled),
    'location' => $kraj,
]);
$smsBody = 'Pozdravljeni, tukaj Sebastjan (Popravim vse). Prejel sem vaše povpraševanje. ';
$rows = [
    'Telefon' => '<a href="tel:' . h($tel[1]) . '">' . h($tel[0]) . '</a>',
    'Želi odgovor' => h(REPLY[$odg]) . ($email ? ' · <a href="mailto:' . h($email) . '">' . h($email) . '</a>' : ''),
    'Ime' => h($ime ?: '—'),
    'Kraj' => h($kraj ?: '—'),
    'Priloge' => h($what ?: 'brez prilog'),
];
if ($agent) $rows['Poslal agent'] = h($agent);
$tr = '';
foreach ($rows as $k => $v) $tr .= '<tr><td style="padding:4px 12px 4px 0;color:#4B5A62;vertical-align:top">' . $k . '</td><td style="padding:4px 0"><b>' . $v . '</b></td></tr>';
$btn = fn($href, $label, $bg = '#F2C21B') => '<a href="' . h($href) . '" style="display:inline-block;margin:0 8px 8px 0;padding:12px 16px;background:' . $bg . ';color:#16232B;border:2px solid #16232B;border-radius:4px;font-weight:700;text-decoration:none">' . $label . '</a>';
$html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:16px;line-height:1.5;color:#16232B;max-width:560px">'
    . '<h2 style="margin:0 0 12px">Novo povpraševanje</h2>'
    . '<table style="border-collapse:collapse;margin:0 0 16px">' . $tr . '</table>'
    . ($opis !== '' ? '<p style="margin:0 0 16px;padding:12px 14px;background:#F5F7F6;border-left:5px solid #1D6A58;white-space:pre-wrap">' . h($opis) . '</p>' : '')
    . '<p>' . $btn($ogled, 'Odpri povpraševanje') . $btn('tel:' . $tel[1], 'Pokliči', '#ffffff')
    . $btn('sms:' . $tel[1] . '?&body=' . rawurlencode($smsBody), 'SMS', '#ffffff') . $btn($cal, 'Dodaj v koledar', '#ffffff') . '</p>'
    . '<p style="color:#4B5A62;font-size:14px">Fotografije in glasovna sporočila so priložena, kjer niso prevelika. Videe odprete s klikom »Odpri povpraševanje«. '
    . 'Vsa povpraševanja: <a href="' . h(SITE . '/api/seznam.php?k=' . list_key()) . '">seznam</a>. Povpraševanje se samodejno izbriše po ' . RETENTION_DAYS . ' dneh.</p></div>';
$text = "Novo povpraševanje\n\nTelefon: {$tel[0]}\nŽeli odgovor: " . REPLY[$odg] . ($email ? " ($email)" : '') . "\nIme: " . ($ime ?: '—')
    . "\nKraj: " . ($kraj ?: '—') . "\nPriloge: " . ($what ?: 'brez prilog') . "\n\n$opis\n\nOdpri: $ogled\nDodaj v koledar: $cal\n";
$attach = [];
$sum = 0;
foreach ($stored as $f) {
    if ($f['vrsta'] === 'video' || $sum + $f['velikost'] > MAIL_ATTACH_MAX) continue;
    $attach[] = ["$dir/{$f['datoteka']}", $f['datoteka'], $f['tip']];
    $sum += $f['velikost'];
}
$sent = send_mail(NOTIFY_TO, $subject, $text, $html, $attach, $email ?: null);
if (!$sent && $attach) $sent = send_mail(NOTIFY_TO, $subject, $text, $html, [], $email ?: null); // brez prilog, če so bile težava
$r['obvestilo_poslano'] = $sent;
save_req($r);

// potrditev stranki po e-pošti (če jo je vpisala)
if ($email !== '') {
    $u = status_url($r);
    send_mail($email, 'Prejel sem vaše povpraševanje – Popravim vse',
        "Pozdravljeni,\n\nprejel sem vaše povpraševanje" . ($what ? " ($what)" : '') . ". Pregledal ga bom sam in vam odgovoril, ko končam delo pri stranki.\n\nStanje: $u\n\nSebastjan Stjepanović, Popravim vse\n" . BIZ_PHONE . "\n",
        '<div style="font-family:system-ui,sans-serif;font-size:16px;line-height:1.5;color:#16232B;max-width:560px"><p>Pozdravljeni,</p><p>prejel sem vaše povpraševanje'
        . ($what ? ' (' . h($what) . ')' : '') . '. Pregledal ga bom sam in vam odgovoril, ko končam delo pri stranki.</p><p><a href="' . h($u) . '">Poglejte stanje povpraševanja</a></p>'
        . '<p>Sebastjan Stjepanović, Popravim vse<br>' . BIZ_PHONE . '</p></div>', [], NOTIFY_TO);
}

housekeeping();

if ($json) json_out(['ok' => true, 'id' => $id, 'stanje' => status_url($r),
    'sporocilo' => 'Povpraševanje je prejeto. Sebastjan ga pregleda sam in odgovori ' . REPLY_PO[$odg] . '. Termin ni potrjen, dokler ga ne potrdi Sebastjan.'], 201);
header('Location: ' . status_url($r) . '&novo=1', true, 303);
exit;
