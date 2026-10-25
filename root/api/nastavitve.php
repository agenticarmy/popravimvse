<?php
/* Sebastjanove nastavitve: UI pomočnik, prepis govora, Google Koledar, delovni čas. ?k=… (ista povezava kot seznam)
 * Ključi se shranijo izven public_html in se nikoli ne izpišejo v celoti.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_ui.php';

$k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
if (!hash_equals(list_key(), $k)) { http_response_code(404); exit; }
$cfg = config();
$msg = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $c = $cfg;
    foreach (['claude_kljuc', 'prepis_kljuc', 'ical'] as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        if (!empty($_POST[$f . '_brisi'])) $c[$f] = '';
        elseif ($v !== '') $c[$f] = $v;
    }
    foreach (['claude_model', 'prepis_model'] as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        if (preg_match('/^[a-z0-9.\-]{3,60}$/', $v)) $c[$f] = $v;
    }
    $c['ui_vklop'] = !empty($_POST['ui_vklop']);
    $c['celodnevni_blokirajo'] = !empty($_POST['celodnevni_blokirajo']);
    $c['dnevi'] = array_values(array_filter(array_map('intval', (array)($_POST['dnevi'] ?? [])), fn($d) => $d >= 1 && $d <= 7));
    foreach (['od', 'do'] as $f) if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST[$f] ?? ''))) $c[$f] = $_POST[$f];
    $c['rezerva'] = max(0, min(180, (int)($_POST['rezerva'] ?? 45)));
    if ($c['ical'] !== '' && !preg_match('#^https://calendar\.google\.com/calendar/ical/[^\s]+\.ics$#', $c['ical'])) {
        $msg[] = ['err', 'Naslov koledarja ni pravi: potreben je »Zasebni naslov v obliki iCal« (konča se na basic.ics).'];
        $c['ical'] = $cfg['ical'];
    }
    save_config($c);
    $cfg = $c;
    $msg[] = ['ok', 'Shranjeno.'];

    if (($_POST['akcija'] ?? '') === 'preizkus') {
        if ($cfg['claude_kljuc'] !== '') {
            [$code, $j, $err] = http_json((getenv('PV_ANTHROPIC_URL') ?: 'https://api.anthropic.com') . '/v1/messages', [
                'x-api-key: ' . $cfg['claude_kljuc'], 'anthropic-version: 2023-06-01', 'content-type: application/json',
            ], json_encode(['model' => $cfg['claude_model'], 'max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Odgovori samo z besedo: deluje']]]), 30);
            $msg[] = $code === 200 ? ['ok', 'Claude: ' . trim((string)($j['content'][0]['text'] ?? 'odgovor prejet')) . ' (' . $cfg['claude_model'] . ')']
                : ['err', 'Claude ne deluje: ' . ($err ?: "HTTP $code")];
        }
        if ($cfg['prepis_kljuc'] !== '') {
            $ch = curl_init((getenv('PV_OPENAI_URL') ?: 'https://api.openai.com') . '/v1/models/' . rawurlencode($cfg['prepis_model']));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['prepis_kljuc']]]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $msg[] = $code === 200 ? ['ok', 'Prepis govora: ključ deluje (' . $cfg['prepis_model'] . ').'] : ['err', "Prepis govora: ključ ali model ne deluje (HTTP $code)."];
        }
        if ($cfg['ical'] !== '') {
            [$ics, $err] = ical_fetch($cfg['ical'], true);
            if ($ics) {
                $n = count(busy_intervals($ics, time(), time() + KOLEDAR_DNI * 86400, (bool)$cfg['celodnevni_blokirajo']));
                $sl = free_slots(120, 3, $cfg)['termini'];
                $msg[] = ['ok', "Koledar: v naslednjih " . KOLEDAR_DNI . " dneh $n zasedenih terminov. Prosti za 2 uri: " . implode(' · ', array_map(fn($t) => sl_slot($t, true), $sl))];
            } else $msg[] = ['err', 'Koledar: ' . $err];
        }
    }
}

$mask = fn(string $v) => $v === '' ? 'ni nastavljen' : 'nastavljen (…' . h(substr($v, -4)) . ')';
page_start('Nastavitve | Popravim vse');
?>
<p class="pv-small"><a href="/api/seznam.php?k=<?= h($k) ?>">← Vsa povpraševanja</a></p>
<h1>Nastavitve</h1>
<?php foreach ($msg as [$t, $m]): ?><p class="<?= $t === 'ok' ? 'pv-okmsg' : 'pv-err pv-err-main' ?>"><?= h($m) ?></p><?php endforeach; ?>

<form method="post" class="pv-form pv-settings" autocomplete="off">
  <input type="hidden" name="k" value="<?= h($k) ?>">
  <fieldset class="pv-step">
    <legend>UI pomočnik</legend>
    <label class="pv-check"><input type="checkbox" name="ui_vklop" value="1" <?= $cfg['ui_vklop'] ? 'checked' : '' ?>> Ob vsakem povpraševanju pripravi oceno, material in osnutek odgovora</label>
    <p class="pv-hint">Stranki se nič ne pošlje samodejno. Vsak odgovor potrdite sami.</p>
    <label class="pv-label" for="ck">Ključ API za Claude <small><?= $mask($cfg['claude_kljuc']) ?></small></label>
    <input id="ck" name="claude_kljuc" type="password" placeholder="sk-ant-… (prazno = ostane)" autocomplete="new-password" spellcheck="false">
    <?php if ($cfg['claude_kljuc'] !== ''): ?><label class="pv-check"><input type="checkbox" name="claude_kljuc_brisi" value="1"> Izbriši ključ</label><?php endif; ?>
    <label class="pv-label" for="cm">Model</label>
    <input id="cm" name="claude_model" type="text" value="<?= h($cfg['claude_model']) ?>" spellcheck="false">
  </fieldset>

  <fieldset class="pv-step">
    <legend>Prepis glasovnih sporočil in videov</legend>
    <p class="pv-hint">Claude zvoka ne posluša neposredno, zato se posnetki najprej prepišejo v besedilo (OpenAI). Brez tega ključa UI oceni samo opis in slike.</p>
    <label class="pv-label" for="pk">Ključ API za prepis (OpenAI) <small><?= $mask($cfg['prepis_kljuc']) ?></small></label>
    <input id="pk" name="prepis_kljuc" type="password" placeholder="sk-… (prazno = ostane)" autocomplete="new-password" spellcheck="false">
    <?php if ($cfg['prepis_kljuc'] !== ''): ?><label class="pv-check"><input type="checkbox" name="prepis_kljuc_brisi" value="1"> Izbriši ključ</label><?php endif; ?>
    <label class="pv-label" for="pm">Model za prepis</label>
    <input id="pm" name="prepis_model" type="text" value="<?= h($cfg['prepis_model']) ?>" spellcheck="false">
  </fieldset>

  <fieldset class="pv-step">
    <legend>Google Koledar</legend>
    <p class="pv-hint">Google Koledar → Nastavitve → vaš koledar → »Zasebni naslov v obliki iCal«. Naslov omogoča samo branje.</p>
    <label class="pv-label" for="ic">Zasebni naslov iCal <small><?= $cfg['ical'] === '' ? 'ni nastavljen' : 'nastavljen' ?></small></label>
    <input id="ic" name="ical" type="password" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics" autocomplete="new-password" spellcheck="false">
    <?php if ($cfg['ical'] !== ''): ?><label class="pv-check"><input type="checkbox" name="ical_brisi" value="1"> Odstrani koledar</label><?php endif; ?>
    <div class="pv-label">Delovni dnevi</div>
    <div class="pv-chips">
      <?php foreach ([1 => 'pon', 2 => 'tor', 3 => 'sre', 4 => 'čet', 5 => 'pet', 6 => 'sob', 7 => 'ned'] as $d => $l): ?>
        <label><input type="checkbox" name="dnevi[]" value="<?= $d ?>" <?= in_array($d, array_map('intval', $cfg['dnevi']), true) ? 'checked' : '' ?>><span><?= $l ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="pv-row">
      <div><label class="pv-label" for="od">Začetek pri stranki od</label><input id="od" name="od" type="time" value="<?= h($cfg['od']) ?>"></div>
      <div><label class="pv-label" for="do">Konec najpozneje do</label><input id="do" name="do" type="time" value="<?= h($cfg['do']) ?>"></div>
    </div>
    <label class="pv-label" for="rz">Rezerva za vožnjo pred in po (minute)</label>
    <input id="rz" name="rezerva" type="number" min="0" max="180" step="15" value="<?= (int)$cfg['rezerva'] ?>">
    <label class="pv-check"><input type="checkbox" name="celodnevni_blokirajo" value="1" <?= $cfg['celodnevni_blokirajo'] ? 'checked' : '' ?>> Celodnevni dogodki (npr. dopust) zasedejo cel dan</label>
  </fieldset>

  <button class="call pv-send" name="akcija" value="shrani">Shrani</button>
  <button class="btn-plain pv-send2" name="akcija" value="preizkus">Shrani in preizkusi povezave</button>
</form>
<?php page_end();
