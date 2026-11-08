<?php
/* Povezava s kanalom YouTube »Sebastjan Popravi Vse« (Google OAuth, dovoljenje youtube.force-ssl za objavo komentarjev).
 * ?k=…&zacni=1  → preusmeritev na Google;  Google se vrne sem s ?code=…&state=…
 * Sprejme se SAMO kanal YT_KANAL; drug kanal se zavrne in dovoljenje takoj prekliče.
 */
declare(strict_types=1);
require __DIR__ . '/_komentarji.php';

$cfg = config();
$stateFile = kom_dir() . '/oauth_state.json';

$fail = function (string $title, string $msg, bool $back = false): never {
    page_start($title . ' | Popravim vse');
    echo '<h1>' . h($title) . '</h1><p class="pv-err pv-err-main">' . h($msg) . '</p>';
    if ($back) echo '<p><a href="' . h('/api/komentarji.php?k=' . list_key()) . '">← Nazaj na komentarje</a></p>';
    page_end();
};

if (isset($_GET['zacni'])) {
    if (!hash_equals(list_key(), (string)($_GET['k'] ?? ''))) { http_response_code(404); exit; }
    if ($cfg['yt_client_id'] === '' || $cfg['yt_client_secret'] === '') {
        $fail('Najprej nastavitve', 'V Nastavitvah (Komentarji na YouTubu) vnesite Client ID in Client secret iz Google Cloud.', true);
    }
    $nonce = bin2hex(random_bytes(16));
    kom_write($stateFile, ['nonce' => $nonce, 'cas' => time()]);
    header('Location: ' . google_auth_url() . '?' . http_build_query([
        'client_id' => $cfg['yt_client_id'], 'redirect_uri' => yt_redirect_uri(), 'response_type' => 'code', 'scope' => YT_SCOPE,
        'access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true', 'state' => $nonce,
        'login_hint' => NOTIFY_TO,
    ]), true, 302);
    exit;
}

// vrnitev z Googla
$state = (string)($_GET['state'] ?? '');
$saved = kom_read($stateFile, []);
if ($state === '' || empty($saved['nonce']) || !hash_equals((string)$saved['nonce'], $state) || (int)($saved['cas'] ?? 0) < time() - 900) {
    http_response_code(400);
    $fail('Povezava ni veljavna', 'Povezava je potekla ali ni bila začeta s strani Komentarji. Začnite znova z gumbom »Poveži YouTube«.');
}
@unlink($stateFile);
if (isset($_GET['error'])) $fail('Povezava ni uspela', 'Google je vrnil: ' . (string)$_GET['error'] . '. Dovoljenja niste potrdili ali pa je bil izbran napačen račun.', true);

[$code, $j, $err] = kom_http('POST', google_token_url(), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
    'code' => (string)($_GET['code'] ?? ''), 'client_id' => $cfg['yt_client_id'], 'client_secret' => $cfg['yt_client_secret'],
    'redirect_uri' => yt_redirect_uri(), 'grant_type' => 'authorization_code']), 20);
if ($code !== 200 || empty($j['access_token'])) $fail('Povezava ni uspela', 'Google prijave ni potrdil: ' . ($err ?: "HTTP $code") . '.', true);
$refresh = (string)($j['refresh_token'] ?? '');
$revoke = function () use ($j, $refresh) {
    foreach (array_filter([$refresh, (string)$j['access_token']]) as $t) {
        kom_http('POST', google_revoke_url(), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $t]), 15);
    }
};
if (!str_contains(' ' . (string)($j['scope'] ?? YT_SCOPE) . ' ', ' ' . YT_SCOPE . ' ')) {
    $revoke();
    $fail('Manjka dovoljenje', 'Na Googlovi strani je treba obkljukati dovoljenje za upravljanje računa YouTube (objava komentarjev). Poskusite znova.', true);
}

// kateri kanal je bil izbran?
[$c2, $me, $e2] = kom_http('GET', yt_base() . '/channels?part=snippet&mine=true', ['Authorization: Bearer ' . $j['access_token'], 'Accept: application/json'], null, 20);
yt_add_units(1);
$ch = $me['items'][0] ?? null;
if ($c2 !== 200 || !$ch) { $revoke(); $fail('Povezava ni uspela', 'Kanala ni bilo mogoče prebrati: ' . ($e2 ?: "HTTP $c2") . '.', true); }
if (($ch['id'] ?? '') !== YT_KANAL) {
    $revoke();
    $fail('Napačen kanal', 'Izbran je bil kanal »' . (string)($ch['snippet']['title'] ?? '?') . '«. Povezati je mogoče samo kanal ' . YT_KANAL_IME
        . ' (@popravimvse). Pri izbiri računa izberite popravimvse@gmail.com in nato kanal ' . YT_KANAL_IME . '. Dovoljenje za napačni kanal je že preklicano.', true);
}
if ($refresh === '') {
    $old = yt_oauth();
    if (empty($old['refresh_token'])) {
        $revoke();
        $fail('Povezava ni uspela', 'Google ni vrnil trajne prijave. Na myaccount.google.com/permissions odstranite dostop te aplikacije in povežite znova.', true);
    }
    $refresh = $old['refresh_token'];
}
kom_write(kom_dir() . '/oauth.json', ['refresh_token' => $refresh, 'kanal_id' => $ch['id'], 'kanal_ime' => (string)($ch['snippet']['title'] ?? ''),
    'povezano' => date('c')]);
kom_write(kom_dir() . '/token.json', ['access_token' => $j['access_token'], 'expires' => time() + (int)($j['expires_in'] ?? 3600)]);
header('Location: /api/komentarji.php?k=' . list_key() . '&ok=' . rawurlencode('YouTube je povezan s kanalom ' . (string)($ch['snippet']['title'] ?? '') . '.'), true, 303);
exit;
