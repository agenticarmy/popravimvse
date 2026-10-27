<?php
/* E-poštna obvestila: novo povpraševanje (Sebastjanu), potrditev prejema in odgovor (stranki), potrjen termin (Sebastjanu). */
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_ui.php';

/** Po oddaji: UI ocena (če je vključena), nato obvestilo Sebastjanu in potrditev stranki. */
function process_new(string $id): void
{
    $r = load_req($id);
    if (!$r) return;
    $cfg = config();
    if (ui_ready($cfg)) $r = ui_analyze($r, $cfg);
    notify_new($r);
    if ($r['email'] !== '') {
        $u = status_url($r);
        $what = counts($r['datoteke']);
        send_mail($r['email'], 'Prejel sem vaše povpraševanje – Popravim vse',
            "Pozdravljeni,\n\nprejel sem vaše povpraševanje" . ($what ? " ($what)" : '') . ". Pregledal ga bom sam in vam odgovoril, ko končam delo pri stranki.\n\nStanje: $u\n\nSebastjan Stjepanović, Popravim vse\n" . BIZ_PHONE . "\n",
            mail_wrap('<p>Pozdravljeni,</p><p>prejel sem vaše povpraševanje' . ($what ? ' (' . h($what) . ')' : '')
                . '. Pregledal ga bom sam in vam odgovoril, ko končam delo pri stranki.</p><p><a href="' . h($u) . '">Poglejte stanje povpraševanja</a></p>'
                . '<p>Sebastjan Stjepanović, Popravim vse<br>' . BIZ_PHONE . '</p>'), [], NOTIFY_TO);
    }
}

function notify_new(array $r): void
{
    $dir = req_dir($r['id']);
    $what = counts($r['datoteke']);
    $ui = $r['ui']['rezultat'] ?? null;
    $tag = $ui ? ['da' => '', 'morda' => '[morda] ', 'ne' => '[ni zame] '][$ui['ali_je_zame']] : '';
    $subject = $tag . 'Povpraševanje: ' . ($r['kraj'] ?: 'kraj ni naveden') . ($what ? ' – ' . $what : '') . ' (' . $r['telefon'] . ')';
    $ogled = admin_url($r);
    $cal = gcal_link('Popravim vse: ' . ($r['kraj'] ?: 'stranka') . ' – ' . ($r['ime'] ?: $r['telefon']),
        trim(mb_substr($r['opis'], 0, 600) . "\n\nTelefon: " . $r['telefon'] . "\nPovpraševanje: " . $ogled), $r['kraj']);
    $rows = [
        'Telefon' => '<a href="tel:' . h($r['telefon_e164']) . '">' . h($r['telefon']) . '</a>',
        'Želi odgovor' => h(REPLY[$r['odgovor']]) . ($r['email'] ? ' · <a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' : ''),
        'Ime' => h($r['ime'] ?: '—'),
        'Kraj' => h($r['kraj'] ?: '—'),
        'Priloge' => h($what ?: 'brez prilog'),
    ];
    if ($r['agent']) $rows['Poslal agent'] = h($r['agent']);
    if (!empty($r['klepet'])) $rows['Pred tem'] = 'pogovor s pomočnikom (v povpraševanju)';
    $tr = '';
    foreach ($rows as $k => $v) $tr .= '<tr><td style="padding:4px 12px 4px 0;color:#4B5A62;vertical-align:top">' . $k . '</td><td style="padding:4px 0"><b>' . $v . '</b></td></tr>';
    $hasDraft = ($r['ui']['stanje'] ?? '') === 'ok';
    $html = mail_wrap('<h2 style="margin:0 0 12px">Novo povpraševanje</h2>'
        . '<table style="border-collapse:collapse;margin:0 0 16px">' . $tr . '</table>'
        . ($r['opis'] !== '' ? '<p style="margin:0 0 16px;padding:12px 14px;background:#F5F7F6;border-left:5px solid #1D6A58;white-space:pre-wrap">' . h($r['opis']) . '</p>' : '')
        . ui_mail_html($r)
        . '<p>' . mail_btn($ogled, $hasDraft ? 'Preglej in potrdi odgovor' : 'Odpri povpraševanje') . mail_btn('tel:' . $r['telefon_e164'], 'Pokliči', '#ffffff')
        . mail_btn('sms:' . $r['telefon_e164'], 'SMS', '#ffffff') . mail_btn($cal, 'Dodaj v koledar', '#ffffff') . '</p>'
        . '<p style="color:#4B5A62;font-size:14px">Fotografije in glasovna sporočila so priložena, kjer niso prevelika. Videe odprete s klikom na gumb zgoraj. '
        . 'Vsa povpraševanja: <a href="' . h(SITE . '/api/seznam.php?k=' . list_key()) . '">seznam</a>. Povpraševanje se samodejno izbriše po ' . RETENTION_DAYS . ' dneh.</p>');
    $text = "Novo povpraševanje\n\nTelefon: {$r['telefon']}\nŽeli odgovor: " . REPLY[$r['odgovor']] . ($r['email'] ? " ({$r['email']})" : '')
        . "\nIme: " . ($r['ime'] ?: '—') . "\nKraj: " . ($r['kraj'] ?: '—') . "\nPriloge: " . ($what ?: 'brez prilog')
        . (!empty($r['klepet']) ? "\nPred tem: pogovor s pomočnikom (v povpraševanju)" : '') . "\n\n{$r['opis']}\n\n"
        . ($ui ? "Predlog UI: {$ui['povzetek']}\n\n" : '') . "Odpri: $ogled\n";
    $attach = [];
    $sum = 0;
    foreach ($r['datoteke'] as $f) {
        if (!in_array($f['vrsta'], ['image', 'audio'], true) || $sum + $f['velikost'] > MAIL_ATTACH_MAX) continue;
        $attach[] = ["$dir/{$f['datoteka']}", $f['datoteka'], $f['tip']];
        $sum += $f['velikost'];
    }
    $sent = send_mail(NOTIFY_TO, $subject, $text, $html, $attach, $r['email'] ?: null);
    if (!$sent && $attach) $sent = send_mail(NOTIFY_TO, $subject, $text, $html, [], $r['email'] ?: null);
    $r = load_req($r['id']) ?? $r;
    $r['obvestilo_poslano'] = $sent;
    save_req($r);
}

/** Sebastjanov potrjen odgovor stranki po e-pošti. */
function mail_proposal(array $r): bool
{
    $p = $r['predlog'];
    $u = status_url($r);
    $btns = '';
    foreach ($p['termini'] as $i => $ts) $btns .= mail_btn($u . '#termini', h(sl_slot($ts)), $i === 0 ? '#F2C21B' : '#ffffff');
    $html = mail_wrap('<p style="white-space:pre-wrap">' . h($p['besedilo']) . '</p>'
        . ($p['termini'] ? '<p><b>Izberite termin:</b><br>' . $btns . '</p>' : '<p>' . mail_btn($u, 'Odpri moje povpraševanje') . '</p>'));
    return send_mail($r['email'], 'Odgovor na vaše povpraševanje – Popravim vse', $p['besedilo'], $html, [], NOTIFY_TO);
}

/** Stranka je potrdila termin ali sporočila, da noben ne ustreza. */
function notify_slot(array $r, ?int $ts, string $note = ''): bool
{
    $ogled = admin_url($r);
    $hours = (float)($r['ui']['rezultat']['ocena_ur_max'] ?? 2);
    if ($ts) {
        $end = $ts + (int)ceil($hours * 60 / 30) * 1800;
        $cal = gcal_link('Popravim vse: ' . ($r['kraj'] ?: 'stranka') . ' – ' . ($r['ime'] ?: $r['telefon']),
            trim(mb_substr($r['opis'], 0, 500) . "\n\nTelefon: {$r['telefon']}\nPovpraševanje: $ogled"), $r['kraj'], $ts, $end);
        $subject = 'Termin potrjen: ' . sl_slot($ts, true) . ' – ' . ($r['kraj'] ?: $r['telefon']);
        $html = mail_wrap('<h2 style="margin:0 0 12px">Stranka je potrdila termin</h2><p><b>' . h(sl_slot($ts)) . '</b><br>'
            . h($r['kraj'] ?: '') . ' · <a href="tel:' . h($r['telefon_e164']) . '">' . h($r['telefon']) . '</a></p>'
            . ($note !== '' ? '<p style="padding:10px 12px;background:#F5F7F6;border-left:5px solid #1D6A58">' . nl2br(h($note)) . '</p>' : '')
            . '<p>' . mail_btn($cal, 'Dodaj v Google Koledar') . mail_btn($ogled, 'Odpri povpraševanje', '#ffffff') . '</p>'
            . '<p style="color:#4B5A62;font-size:14px">Naslov se dogovorita po telefonu, če ga še nimate.</p>');
        $text = "Stranka je potrdila termin: " . sl_slot($ts) . "\n{$r['kraj']} · {$r['telefon']}\n\nDodaj v koledar: $cal\nOdpri: $ogled\n";
    } else {
        $subject = 'Noben termin ne ustreza – ' . ($r['kraj'] ?: $r['telefon']);
        $html = mail_wrap('<h2 style="margin:0 0 12px">Stranki noben predlagani termin ne ustreza</h2>'
            . '<p style="padding:10px 12px;background:#F5F7F6;border-left:5px solid #1D6A58">' . nl2br(h($note ?: '(brez sporočila)')) . '</p>'
            . '<p>' . mail_btn($ogled, 'Odpri povpraševanje') . mail_btn('tel:' . $r['telefon_e164'], 'Pokliči', '#ffffff') . '</p>');
        $text = "Stranki noben termin ne ustreza.\n\n$note\n\nOdpri: $ogled\n";
    }
    return send_mail(NOTIFY_TO, $subject, $text, $html, [], $r['email'] ?: null);
}

/** .ics za stranko (»Dodaj v moj koledar«). */
function ics_for_customer(array $r, int $ts): string
{
    $hours = (float)($r['ui']['rezultat']['ocena_ur_max'] ?? 2);
    $end = $ts + (int)ceil($hours * 60 / 30) * 1800;
    $f = fn($t) => gmdate('Ymd\THis\Z', $t);
    $esc = fn($s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//popravimvse.si//povprasevanje//SL\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n"
        . "UID:{$r['id']}@popravimvse.si\r\nDTSTAMP:" . $f(time()) . "\r\nDTSTART:" . $f($ts) . "\r\nDTEND:" . $f($end) . "\r\n"
        . 'SUMMARY:' . $esc('Sebastjan (Popravim vse) pride k vam') . "\r\n"
        . 'DESCRIPTION:' . $esc("Hišni mojster Sebastjan, " . BIZ_PHONE . ". Stanje: " . status_url($r)) . "\r\n"
        . ($r['kraj'] ? 'LOCATION:' . $esc($r['kraj']) . "\r\n" : '')
        . "BEGIN:VALARM\r\nTRIGGER:-PT18H\r\nACTION:DISPLAY\r\nDESCRIPTION:Jutri pride Sebastjan (Popravim vse)\r\nEND:VALARM\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";
}
