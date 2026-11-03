<?php
/* IndexNow: po vsaki objavi (.cpanel.yml) sporoči Bingu, Yandexu, Seznamu … strani, ki so se spremenile.
 * Teče samo iz ukazne vrstice. Primerja vsebino strani (brez vrstice »zgrajeno …«) s stanjem prejšnje objave.
 * Uporaba: php api/_indexnow.php [--dry] [--all]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Europe/Ljubljana');

const SITE = 'https://popravimvse.si';
const KEY = '0e6f1c85e2fd2860f1c9516442ade4d0';
$endpoint = getenv('PV_INDEXNOW_URL') ?: 'https://api.indexnow.org/indexnow';
$root = dirname(__DIR__);                                   // public_html
$state = (getenv('PV_STORAGE') ?: (getenv('HOME') ?: dirname($root)) . '/povprasevanja') . '/indexnow.json';
$dry = in_array('--dry', $argv, true);
$all = in_array('--all', $argv, true);
$log = fn(string $m) => print(date('Y-m-d H:i:s') . " indexnow: $m\n");

$xml = @simplexml_load_file("$root/sitemap.xml");
if (!$xml) { $log('sitemap.xml ni bil najden'); exit(0); }
$old = is_file($state) ? (json_decode((string)file_get_contents($state), true) ?: []) : [];
$new = [];
$changed = [];
foreach ($xml->url as $u) {
    $loc = (string)$u->loc;
    if (!str_starts_with($loc, SITE . '/')) continue;
    $path = substr($loc, strlen(SITE));
    $file = $root . ($path === '/' ? '/index.html' : rtrim($path, '/') . '/index.html');
    if (!is_file($file)) continue;
    $html = preg_replace('/<!-- zgrajeno [^>]*-->/', '', (string)file_get_contents($file));
    $new[$loc] = md5((string)$html);
    if ($all || ($old[$loc] ?? '') !== $new[$loc]) $changed[] = $loc;
}
if (!$changed) { $log('ni sprememb'); exit(0); }
$log('spremenjene strani (' . count($changed) . '): ' . implode(' ', array_slice($changed, 0, 20)) . (count($changed) > 20 ? ' …' : ''));
if ($dry) exit(0);

$body = json_encode(['host' => parse_url(SITE, PHP_URL_HOST), 'key' => KEY, 'keyLocation' => SITE . '/' . KEY . '.txt',
    'urlList' => array_slice($changed, 0, 10000)], JSON_UNESCAPED_SLASHES);
$ch = curl_init($endpoint);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
$out = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err = curl_error($ch);
curl_close($ch);
$log("odgovor HTTP $code" . ($err ? " ($err)" : '') . ($out ? ' ' . substr((string)$out, 0, 200) : ''));
if ($code === 200 || $code === 202) {
    @mkdir(dirname($state), 0700, true);
    file_put_contents($state, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}
exit(0);
