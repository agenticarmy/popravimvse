<?php
/* Nalaganje fotografij, videov in glasovnih sporočil po kosih (deluje tudi pri slabem signalu in velikih videih).
 * GET  ?info=1                      → {chunk, max_file, max_files}
 * POST upload_id, idx, offset, size, name, type, chunk(file) → {ok, received, done}
 * Če se offset ne ujema s tem, kar je že na strežniku, vrne 409 in {received}, da brskalnik nadaljuje od tam.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_out(['chunk' => chunk_size(), 'max_file' => MAX_FILE_BYTES, 'max_files' => MAX_FILES]);
}

if (!rate_ok('kos', 4000, 3600)) json_out(['ok' => false, 'napaka' => 'Preveč nalaganj. Poskusite čez nekaj minut.'], 429);

$uid = (string)($_POST['upload_id'] ?? '');
$idx = (int)($_POST['idx'] ?? -1);
$offset = (int)($_POST['offset'] ?? -1);
$size = (int)($_POST['size'] ?? 0);
if (!preg_match('/^[a-f0-9]{32}$/', $uid) || $idx < 0 || $idx >= MAX_SLOTS || $offset < 0 || $size < 1) {
    json_out(['ok' => false, 'napaka' => 'Napačna zahteva.'], 400);
}
if ($size > MAX_FILE_BYTES) json_out(['ok' => false, 'napaka' => 'Datoteka je prevelika (največ ' . bytes(MAX_FILE_BYTES) . '). Posnemite krajši video.'], 413);
$chunk = $_FILES['chunk'] ?? null;
if (!$chunk || ($chunk['error'] ?? 1) !== UPLOAD_ERR_OK) json_out(['ok' => false, 'napaka' => 'Kos se ni naložil.'], 400);

$dir = storage('nalaganje') . '/' . $uid;
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$part = "$dir/$idx.part";

// skupna velikost vseh datotek v tem povpraševanju
$total = 0;
foreach (glob("$dir/*.part") ?: [] as $f) if ($f !== $part) $total += filesize($f);
if ($total + $size > MAX_TOTAL_BYTES) json_out(['ok' => false, 'napaka' => 'Skupaj je preveč podatkov. Pošljite manj ali krajše videe.'], 413);

$fh = fopen($part, 'c+');
flock($fh, LOCK_EX);
clearstatcache(true, $part);
$have = filesize($part);
if ($have !== $offset) {
    flock($fh, LOCK_UN);
    fclose($fh);
    json_out(['ok' => false, 'received' => $have], 409);
}
$len = (int)$chunk['size'];
if ($offset + $len > $size) {
    flock($fh, LOCK_UN);
    fclose($fh);
    json_out(['ok' => false, 'napaka' => 'Kos je predolg.'], 400);
}
fseek($fh, $offset);
$in = fopen($chunk['tmp_name'], 'rb');
stream_copy_to_stream($in, $fh);
fclose($in);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
clearstatcache(true, $part);
$received = filesize($part);
$done = $received === $size;

if ($done) {
    $mime = mime_of($part);
    $claimed = strtolower(explode(';', (string)($_POST['type'] ?? ''))[0]);
    // posnetek glasu iz brskalnika (webm/mp4 brez slike) libmagic pogosto prepozna kot video – zaupamo navedbi brskalnika, če se ujema z vsebnikom
    if (str_starts_with($claimed, 'audio/') && in_array($mime, ['video/webm', 'video/mp4', 'audio/webm', 'audio/mp4'], true)) {
        $mime = $claimed === 'audio/x-m4a' ? 'audio/mp4' : $claimed;
    }
    if ($mime === 'video/quicktime' && $claimed === 'video/mp4') $mime = 'video/mp4';
    if (!isset(MIME[$mime])) {
        @unlink($part);
        json_out(['ok' => false, 'napaka' => 'Te vrste datoteke ne morem sprejeti. Pošljite fotografijo, video ali glasovno sporočilo.'], 415);
    }
    file_put_contents("$dir/$idx.json", json_encode([
        'ime' => clean((string)($_POST['name'] ?? 'datoteka'), 120), 'tip' => $mime, 'velikost' => $received,
    ], JSON_UNESCAPED_UNICODE));
}
json_out(['ok' => true, 'received' => $received, 'done' => $done]);
