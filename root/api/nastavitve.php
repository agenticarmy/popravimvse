<?php
/* Sebastjanove nastavitve: UI pomočnik, prepis govora, Google Koledar, delovni čas, komentarji na YouTubu. ?k=… (ista povezava kot seznam)
 * Ključi se shranijo izven public_html in se nikoli ne izpišejo v celoti.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_klepet.php';
require __DIR__ . '/_komentarji.php';

$k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
if (!hash_equals(list_key(), $k)) { http_response_code(404); exit; }
$cfg = config();
$msg = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $c = $cfg;
    foreach (['claude_kljuc', 'prepis_kljuc', 'ical', 'yt_client_secret', 'yt_api_kljuc', 'gemini_kljuc'] as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        if (!empty($_POST[$f . '_brisi'])) $c[$f] = '';
        elseif ($v !== '') $c[$f] = $v;
    }
    foreach (['claude_model', 'prepis_model', 'gemini_model', 'kom_model', 'kom_model_hitri'] as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        if (preg_match('/^[a-z0-9.\-]{3,60}$/', $v)) $c[$f] = $v;
    }
    $c['ui_vklop'] = !empty($_POST['ui_vklop']);
    $c['klepet_vklop'] = !empty($_POST['klepet_vklop']);
    $c['klepet_proracun'] = max(0, min(500, (int)($_POST['klepet_proracun'] ?? 20)));
    $v = trim((string)($_POST['klepet_model'] ?? ''));
    if (preg_match('/^[a-z0-9.\-]{3,60}$/', $v)) $c['klepet_model'] = $v;
    // komentarji na YouTubu
    $c['kom_vklop'] = !empty($_POST['kom_vklop']);
    $v = trim((string)($_POST['yt_client_id'] ?? ''));
    if ($v === '' || preg_match('/^[0-9]{6,20}-[a-z0-9]{10,64}\.apps\.googleusercontent\.com$/', $v)) $c['yt_client_id'] = $v;
    else $msg[] = ['err', 'Client ID ni pravi: konča se na .apps.googleusercontent.com.'];
    foreach (['kom_na_dan' => [1, 10, 5], 'kom_objav_na_dan' => [1, 20, 8], 'kom_proracun' => [0, 200, 10], 'kom_iskanj' => [0, 20, 8], 'kom_max_min' => [5, 120, 40]] as $f => [$lo, $hi, $def]) {
        $c[$f] = max($lo, min($hi, (int)($_POST[$f] ?? $def)));
    }
    foreach (['kom_besede' => [40, 60], 'kom_izkusnje' => [60, 400]] as $f => [$maxLines, $maxLen]) {
        if (!isset($_POST[$f])) continue;
        $lines = array_values(array_filter(array_map(fn($l) => mb_substr(trim(ltrim(trim($l), '-• ')), 0, $maxLen), preg_split('/\R/u', (string)$_POST[$f]) ?: []), fn($l) => $l !== ''));
        $c[$f] = array_slice($lines, 0, $maxLines);
    }
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
        if ($cfg['gemini_kljuc'] !== '') {
            [$code, $j, $err] = kom_http('GET', (getenv('PV_GEMINI_URL') ?: 'https://generativelanguage.googleapis.com') . '/v1beta/models?pageSize=200',
                ['x-goog-api-key: ' . $cfg['gemini_kljuc']], null, 20);
            if ($code === 200) {
                $names = array_map(fn($m) => preg_replace('#^models/#', '', (string)($m['name'] ?? '')), $j['models'] ?? []);
                $flash = array_values(array_filter($names, fn($n) => str_contains($n, 'flash') && !preg_match('/(tts|image|live|audio|embedding)/', $n)));
                $msg[] = in_array($cfg['gemini_model'], $names, true) ? ['ok', 'Gemini: ključ deluje, model ' . $cfg['gemini_model'] . ' je na voljo.']
                    : ['err', 'Gemini: ključ deluje, modela ' . $cfg['gemini_model'] . ' pa ni. Na voljo so npr.: ' . implode(', ', array_slice($flash, 0, 8))];
            } else $msg[] = ['err', 'Gemini: ključ ne deluje (' . ($err ?: "HTTP $code") . ').'];
        }
        if (yt_connected()) {
            [, $j, $err] = yt_api($cfg, 'GET', '/channels', ['part' => 'snippet', 'mine' => 'true'], null, 1, true);
            $msg[] = $err ? ['err', 'YouTube: ' . $err] : ['ok', 'YouTube: povezano s kanalom ' . ($j['items'][0]['snippet']['title'] ?? '?') . '.'];
        } elseif ($cfg['yt_client_id'] !== '') $msg[] = ['err', 'YouTube še ni povezan: na strani Komentarji kliknite »Poveži YouTube«.'];
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
    <legend>Pomočnik »Poskusite sami« na vsaki strani</legend>
    <?php $u = kl_usage(); ?>
    <label class="pv-check"><input type="checkbox" name="klepet_vklop" value="1" <?= $cfg['klepet_vklop'] ? 'checked' : '' ?>> Vključen (obiskovalcem pomaga, da težavo odpravijo sami)</label>
    <p class="pv-hint">Ta mesec: <?= kl_n((int)$u['pogovori'], KL_POG) ?>, <?= kl_n((int)$u['odgovori'], KL_ODG) ?>, poraba <?= number_format((float)$u['usd'], 2, ',', '') ?> $ od <?= (int)$cfg['klepet_proracun'] ?> $. Ko je proračun porabljen, se pomočnik do naslednjega meseca sam izklopi. <a href="/api/klepeti.php?k=<?= h($k) ?>">Shranjeni pogovori</a></p>
    <label class="pv-label" for="kp">Mesečni proračun (USD; 20 $ je manj kot 20 €)</label>
    <input id="kp" name="klepet_proracun" type="number" min="0" max="500" step="1" value="<?= (int)$cfg['klepet_proracun'] ?>">
    <label class="pv-label" for="km">Model za pomočnika</label>
    <input id="km" name="klepet_model" type="text" value="<?= h($cfg['klepet_model']) ?>" spellcheck="false">
    <p class="pv-hint">Uporablja isti ključ za Claude kot zgoraj. Dodatno nastavite mesečno omejitev porabe tudi v konzoli Anthropic: <a href="https://platform.claude.com/settings/billing" target="_blank" rel="noopener">Billing → Spend limits</a>.</p>
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

  <fieldset class="pv-step" id="komentarji">
    <legend>Komentarji na YouTubu</legend>
    <p class="pv-hint">Vsako jutro poišče nove slovenske videe o popravilih, Gemini si jih ogleda, Claude predlaga komentar. Objavite samo vi, s klikom na strani <a href="/api/komentarji.php?k=<?= h($k) ?>">Komentarji na YouTubu</a>. Uporablja isti ključ za Claude kot zgoraj.</p>
    <label class="pv-check"><input type="checkbox" name="kom_vklop" value="1" <?= $cfg['kom_vklop'] ? 'checked' : '' ?>> Vsako jutro poišči nove videe in pripravi predloge (e-pošta, ko so predlogi)</label>
    <p class="pv-hint" style="overflow-wrap:anywhere"><b>Google Cloud</b> (projekt »Popravim vse komentarji« na vašem Google računu): projekt z vklopljenim »YouTube Data API v3«, zaslon za soglasje OAuth »External« in <b>»In production«</b> (v načinu Testing prijava poteče po 7 dneh), ključ OAuth vrste »Web application« z naslovom za preusmeritev:<br><code><?= h(yt_redirect_uri()) ?></code></p>
    <label class="pv-label" for="yci">Client ID</label>
    <input id="yci" name="yt_client_id" type="text" value="<?= h($cfg['yt_client_id']) ?>" placeholder="….apps.googleusercontent.com" spellcheck="false" autocomplete="off">
    <label class="pv-label" for="ycs">Client secret <small><?= $mask($cfg['yt_client_secret']) ?></small></label>
    <input id="ycs" name="yt_client_secret" type="password" placeholder="GOCSPX-… (prazno = ostane)" autocomplete="new-password" spellcheck="false">
    <?php if ($cfg['yt_client_secret'] !== ''): ?><label class="pv-check"><input type="checkbox" name="yt_client_secret_brisi" value="1"> Izbriši</label><?php endif; ?>
    <label class="pv-label" for="yak">Ključ API za YouTube (neobvezno, samo za branje) <small><?= $mask($cfg['yt_api_kljuc']) ?></small></label>
    <input id="yak" name="yt_api_kljuc" type="password" placeholder="AIza… (prazno = ostane)" autocomplete="new-password" spellcheck="false">
    <?php if ($cfg['yt_api_kljuc'] !== ''): ?><label class="pv-check"><input type="checkbox" name="yt_api_kljuc_brisi" value="1"> Izbriši</label><?php endif; ?>
    <p class="pv-hint">Povezava s kanalom: <?= yt_connected() ? '✓ ' . h(yt_oauth()['kanal_ime'] ?? YT_KANAL_IME) : 'ni povezan – po shranjevanju kliknite <a href="/api/yt-oauth.php?k=' . h($k) . '&amp;zacni=1">Poveži YouTube</a> in izberite kanal ' . YT_KANAL_IME ?></p>
    <label class="pv-label" for="gk">Ključ API za Gemini (Google AI Studio, s plačevanjem) <small><?= $mask($cfg['gemini_kljuc']) ?></small></label>
    <input id="gk" name="gemini_kljuc" type="password" placeholder="AIza… (prazno = ostane)" autocomplete="new-password" spellcheck="false">
    <?php if ($cfg['gemini_kljuc'] !== ''): ?><label class="pv-check"><input type="checkbox" name="gemini_kljuc_brisi" value="1"> Izbriši ključ</label><?php endif; ?>
    <label class="pv-label" for="gm">Model Gemini za ogled videa</label>
    <input id="gm" name="gemini_model" type="text" value="<?= h($cfg['gemini_model']) ?>" spellcheck="false">
    <div class="pv-row">
      <div><label class="pv-label" for="km1">Model za predloge</label><input id="km1" name="kom_model" type="text" value="<?= h($cfg['kom_model']) ?>" spellcheck="false"></div>
      <div><label class="pv-label" for="km2">Model za razvrščanje</label><input id="km2" name="kom_model_hitri" type="text" value="<?= h($cfg['kom_model_hitri']) ?>" spellcheck="false"></div>
    </div>
    <?php $ku = kom_usage(); ?>
    <p class="pv-hint">Ta mesec: <?= number_format(kom_spent(), 2, ',', '') ?> $ (Claude <?= number_format((float)$ku['claude'], 2, ',', '') ?> $, Gemini <?= number_format((float)$ku['gemini'], 2, ',', '') ?> $), <?= (int)$ku['videi'] ?> videov, <?= (int)$ku['objave'] ?> objav. Ko je proračun porabljen, se iskanje do naslednjega meseca ustavi.</p>
    <div class="pv-row">
      <div><label class="pv-label" for="kp1">Predlogov na dan</label><input id="kp1" name="kom_na_dan" type="number" min="1" max="10" value="<?= (int)$cfg['kom_na_dan'] ?>"></div>
      <div><label class="pv-label" for="kp2">Največ objav na dan</label><input id="kp2" name="kom_objav_na_dan" type="number" min="1" max="20" value="<?= (int)$cfg['kom_objav_na_dan'] ?>"></div>
    </div>
    <div class="pv-row">
      <div><label class="pv-label" for="kp3">Mesečni proračun (USD)</label><input id="kp3" name="kom_proracun" type="number" min="0" max="200" value="<?= (int)$cfg['kom_proracun'] ?>"></div>
      <div><label class="pv-label" for="kp4">Iskanj na dan (100 enot vsako)</label><input id="kp4" name="kom_iskanj" type="number" min="0" max="20" value="<?= (int)$cfg['kom_iskanj'] ?>"></div>
    </div>
    <label class="pv-label" for="kp5">Najdaljši video za ogled (minute)</label>
    <input id="kp5" name="kom_max_min" type="number" min="5" max="120" value="<?= (int)$cfg['kom_max_min'] ?>">
    <label class="pv-label" for="kb">Ključne besede za iskanje (ena na vrstico, vsak dan naslednje)</label>
    <textarea id="kb" name="kom_besede" rows="8" class="kom-ta" spellcheck="false"><?= h(implode("\n", (array)$cfg['kom_besede'])) ?></textarea>
    <label class="pv-label" for="ki">Moje izkušnje, ki jih komentarji smejo omeniti (ena na vrstico)</label>
    <p class="pv-hint">Poleg teh smejo komentarji uporabiti še vaše potrjene nasvete »Iz moje prakse« s seznamov Kaj kupiti. Vsega drugega ne trdijo v vašem imenu.</p>
    <textarea id="ki" name="kom_izkusnje" rows="10" class="kom-ta"><?= h(implode("\n", (array)$cfg['kom_izkusnje'])) ?></textarea>
  </fieldset>

  <button class="call pv-send" name="akcija" value="shrani">Shrani</button>
  <button class="btn-plain pv-send2" name="akcija" value="preizkus">Shrani in preizkusi povezave</button>
</form>
<?php page_end();
