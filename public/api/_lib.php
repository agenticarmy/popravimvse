<?php
/* Skupne funkcije za povpraševanja na popravimvse.si.
 * Podatki se shranjujejo IZVEN public_html (npr. /home/<uporabnik>/povprasevanja), skrivni ključ se ustvari sam ob prvi uporabi.
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_lib.php') { http_response_code(404); exit; }
date_default_timezone_set('Europe/Ljubljana');

define('SITE', getenv('PV_SITE') ?: 'https://popravimvse.si'); // PV_SITE samo za lokalni preizkus
const NOTIFY_TO = 'popravimvse@gmail.com';
const MAIL_FROM = 'povprasevanje@popravimvse.si';
const BIZ_PHONE = '070 755 355';
const BIZ_PHONE_E164 = '+38670755355';
const MAX_FILES = 12;
const MAX_FILE_BYTES = 250 * 1024 * 1024;   // en video do 250 MB
const MAX_TOTAL_BYTES = 600 * 1024 * 1024;
const MAX_TEXT = 5000;
const RETENTION_DAYS = 90;                   // povpraševanja in posnetki se samodejno izbrišejo
const MAIL_ATTACH_MAX = 18 * 1024 * 1024;    // fotografije in glas kot priloga v e-pošti do te velikosti

const MIME = [
    'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'],
    'image/heic' => ['image', 'heic'], 'image/heif' => ['image', 'heif'], 'image/gif' => ['image', 'gif'],
    'video/mp4' => ['video', 'mp4'], 'video/quicktime' => ['video', 'mov'], 'video/webm' => ['video', 'webm'],
    'video/3gpp' => ['video', '3gp'], 'video/x-matroska' => ['video', 'mkv'],
    'audio/webm' => ['audio', 'webm'], 'audio/mp4' => ['audio', 'm4a'], 'audio/x-m4a' => ['audio', 'm4a'],
    'audio/mpeg' => ['audio', 'mp3'], 'audio/ogg' => ['audio', 'ogg'], 'audio/wav' => ['audio', 'wav'],
    'audio/x-wav' => ['audio', 'wav'], 'audio/aac' => ['audio', 'aac'], 'audio/3gpp' => ['audio', '3gp'],
];

const STATUS = [
    'prejeto' => 'Prejeto',
    'pregledujem' => 'Sebastjan pregleduje',
    'odgovorjeno' => 'Sebastjan vam je odgovoril',
    'zakljuceno' => 'Zaključeno',
];
const REPLY = ['sms' => 'SMS', 'klic' => 'Klic', 'email' => 'E-pošta'];
const REPLY_PO = ['sms' => 'po SMS-u', 'klic' => 'po telefonu', 'email' => 'po e-pošti'];

function storage(string $sub = ''): string
{
    $base = getenv('PV_STORAGE') ?: dirname(rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/')) . '/povprasevanja';
    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
        @file_put_contents($base . '/.htaccess', "Require all denied\n");
    }
    $dir = $sub === '' ? $base : $base . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function secret(): string
{
    $f = storage() . '/.secret';
    if (!is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
}

function admin_key(string $id): string { return substr(hash_hmac('sha256', 'ogled|' . $id, secret()), 0, 32); }
function list_key(): string { return substr(hash_hmac('sha256', 'seznam', secret()), 0, 32); }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valid_id(string $id): bool { return (bool)preg_match('/^\d{8}-[a-z0-9]{6}$/', $id); }

function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }

function wants_json(): bool
{
    $a = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $c = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    return str_contains($a, 'application/json') || str_contains($c, 'application/json');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Največ $max dogodkov v $window sekundah na IP in vrsto. */
function rate_ok(string $bucket, int $max, int $window): bool
{
    $f = storage('omejitve') . '/' . sha1($bucket . '|' . client_ip());
    $fh = fopen($f, 'c+');
    if (!$fh) return true;
    flock($fh, LOCK_EX);
    $now = time();
    $list = array_filter(json_decode(stream_get_contents($fh) ?: '[]', true) ?: [], fn($t) => $t > $now - $window);
    $ok = count($list) < $max;
    if ($ok) $list[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(array_values($list)));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/** Slovenska ali mednarodna številka → [za prikaz, E.164] ali null. */
function phone(string $raw): ?array
{
    $p = preg_replace('/[^\d+]/', '', $raw) ?? '';
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if (preg_match('/^0\d{7,8}$/', $p)) $p = '+386' . substr($p, 1);
    if (preg_match('/^386\d{8}$/', $p)) $p = '+' . $p;
    if (!preg_match('/^\+\d{8,15}$/', $p)) return null;
    $disp = $p;
    if (str_starts_with($p, '+386')) {
        $n = '0' . substr($p, 4);
        $disp = strlen($n) === 9 ? substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6) : $n;
    }
    return [$disp, $p];
}

function clean(string $s, int $max): string
{
    $s = trim(preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function mime_of(string $path): string
{
    $m = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    // nekatere različice libmagic posnetke iz brskalnika označijo kot video, čeprav vsebujejo samo zvok
    return $m;
}

function req_dir(string $id): string { return storage('povprasevanja') . '/' . $id; }

function load_req(string $id): ?array
{
    if (!valid_id($id)) return null;
    $f = req_dir($id) . '/meta.json';
    return is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
}

function save_req(array $r): void
{
    file_put_contents(req_dir($r['id']) . '/meta.json', json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function set_status(array &$r, string $status): void
{
    if (!isset(STATUS[$status]) || $r['status'] === $status) return;
    $r['status'] = $status;
    $r['zgodovina'][] = ['status' => $status, 'cas' => date('c')];
    save_req($r);
}

function status_url(array $r): string { return SITE . '/api/stanje.php?id=' . $r['id'] . '&t=' . $r['token']; }
function admin_url(array $r): string { return SITE . '/api/ogled.php?id=' . $r['id'] . '&k=' . admin_key($r['id']); }

function sl_time(string $iso): string { return date('j. n. Y \o\b H.i', strtotime($iso)); }

function bytes(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '') . ' MB' : max(1, (int)round($b / 1024)) . ' kB';
}

function counts(array $files): string
{
    $c = ['image' => 0, 'video' => 0, 'audio' => 0];
    foreach ($files as $f) $c[$f['vrsta']]++;
    $out = [];
    if ($c['image']) $out[] = $c['image'] . ($c['image'] === 1 ? ' fotografija' : ($c['image'] === 2 ? ' fotografiji' : ($c['image'] < 5 ? ' fotografije' : ' fotografij')));
    if ($c['video']) $out[] = $c['video'] . ($c['video'] === 1 ? ' video' : ' videi');
    if ($c['audio']) $out[] = $c['audio'] === 1 ? 'glasovno sporočilo' : $c['audio'] . ' glasovna sporočila';
    return implode(', ', $out);
}

/** Pošlje e-pošto (besedilo + HTML + priloge). */
function send_mail(string $to, string $subject, string $text, string $html, array $attach = [], ?string $replyTo = null): bool
{
    $b1 = 'pv1_' . bin2hex(random_bytes(8));
    $b2 = 'pv2_' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . mb_encode_mimeheader('Popravim vse – povpraševanje', 'UTF-8') . ' <' . MAIL_FROM . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $b1 . '"',
        'X-Mailer: popravimvse.si',
    ];
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
    $body = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n"
        . "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$b2--\r\n";
    foreach ($attach as [$path, $name, $type]) {
        $body .= "--$b1\r\nContent-Type: $type; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($path)));
    }
    $body .= "--$b1--\r\n";
    if (getenv('PV_MAIL_DIR')) { // lokalni preizkus: shrani namesto pošiljanja
        file_put_contents(getenv('PV_MAIL_DIR') . '/' . date('His') . '-' . bin2hex(random_bytes(3)) . '.eml',
            "To: $to\r\nSubject: $subject\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
        return true;
    }
    return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers), '-f' . MAIL_FROM);
}

/** Občasno: izbriše stare povpraševanja (RETENTION_DAYS) in nedokončana nalaganja (1 dan). */
function housekeeping(): void
{
    if (random_int(1, 20) !== 1) return;
    $now = time();
    foreach (glob(storage('povprasevanja') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (filemtime($d . '/meta.json') < $now - RETENTION_DAYS * 86400) rrmdir($d);
    }
    foreach (glob(storage('nalaganje') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (filemtime($d) < $now - 86400) rrmdir($d);
    }
    foreach (glob(storage('omejitve') . '/*') ?: [] as $f) {
        if (filemtime($f) < $now - 86400) @unlink($f);
    }
}

function rrmdir(string $d): void
{
    foreach (glob($d . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) is_dir($f) ? rrmdir($f) : @unlink($f);
    @rmdir($d);
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** Velikost kosa pri nalaganju, prilagojena omejitvam strežnika. */
function chunk_size(): int
{
    $lim = min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size')));
    return max(512 * 1024, min(8 * 1024 * 1024, (int)($lim * 0.8)));
}

/** HTML ogrodje za strani v /api/ (ne indeksira se). */
function page_start(string $title, string $extraHead = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="sl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#16232B">'
        . '<title>' . h($title) . '</title><link rel="icon" href="/static/img/favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="/static/css/site.css">' . $extraHead . '</head><body class="pv-app">'
        . '<header class="site-head"><div class="wrap"><a class="brand" href="/"><b>Popravim vse</b><span>Sebastjan, hišni mojster</span></a>'
        . '<a class="call small" href="tel:' . BIZ_PHONE_E164 . '">' . BIZ_PHONE . '</a></div></header><main id="vsebina" class="wrap pv-main">';
}

function page_end(): void
{
    echo '</main></body></html>';
    exit;
}
