<?php
/* Prikaz priloge povpraševanja (Sebastjan s ključem k, stranka s svojim t). Podpira Range za predvajanje videa na iPhonu. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

$id = (string)($_GET['id'] ?? '');
$r = load_req($id);
$okAdmin = $r && hash_equals(admin_key($id), (string)($_GET['k'] ?? ''));
$okCustomer = $r && isset($_GET['t']) && hash_equals($r['token'], (string)$_GET['t']);
$f = $r['datoteke'][(int)($_GET['f'] ?? -1)] ?? null;
if (!$f || !($okAdmin || $okCustomer)) { http_response_code(404); exit; }

$path = req_dir($id) . '/' . basename($f['datoteka']);
if (!is_file($path)) { http_response_code(404); exit; }
$size = filesize($path);
$start = 0;
$end = $size - 1;

header('Content-Type: ' . $f['tip']);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $r['id'] . '-' . $f['datoteka'] . '"');

if (preg_match('/bytes=(\d*)-(\d*)/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $buf = fread($fh, (int)min(1 << 20, $left));
    echo $buf;
    $left -= strlen($buf);
    flush();
}
fclose($fh);
