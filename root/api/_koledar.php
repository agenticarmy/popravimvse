<?php
/* Prosti termini iz Google Koledarja prek zasebnega naslova iCal (samo branje, brez prijave v Google).
 * Podpira časovne pasove, celodnevne dogodke, ponavljajoče dogodke (DAILY/WEEKLY/MONTHLY/YEARLY, INTERVAL, COUNT, UNTIL, BYDAY za tedenske),
 * EXDATE in spremenjene ponovitve (RECURRENCE-ID).
 */
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';

const KOLEDAR_DNI = 21;

/** Prenese iCal (5 min predpomnjeno). Vrne [besedilo, napaka]. */
function ical_fetch(string $url, bool $fresh = false): array
{
    if (getenv('PV_ICAL_FILE')) return [(string)file_get_contents(getenv('PV_ICAL_FILE')), null]; // samo lokalni preizkus
    if (!preg_match('#^https://calendar\.google\.com/calendar/ical/[^\s]+\.ics$#', $url)) {
        return [null, 'Naslov ni zasebni naslov Google Koledarja v obliki iCal (…/basic.ics).'];
    }
    $cache = storage() . '/koledar.ics';
    if (!$fresh && is_file($cache) && filemtime($cache) > time() - 300 && filesize($cache) > 0) {
        return [(string)file_get_contents($cache), null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200 || !str_contains((string)$body, 'BEGIN:VCALENDAR')) {
        return [null, 'Koledarja ni bilo mogoče prebrati' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : '') . '.'];
    }
    file_put_contents($cache, $body, LOCK_EX);
    @chmod($cache, 0600);
    return [(string)$body, null];
}

/** @return array{0:DateTimeImmutable,1:bool} [čas, celodnevni] */
function ics_dt(string $value, array $params): array
{
    $tz = new DateTimeZone('Europe/Ljubljana');
    if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value)) {
        return [new DateTimeImmutable(substr($value, 0, 8), $tz), true];
    }
    if (str_ends_with($value, 'Z')) {
        return [(new DateTimeImmutable(rtrim($value, 'Z'), new DateTimeZone('UTC')))->setTimezone($tz), false];
    }
    if (isset($params['TZID'])) {
        try { $tz2 = new DateTimeZone(trim($params['TZID'], '"')); } catch (Exception) { $tz2 = $tz; }
        return [(new DateTimeImmutable($value, $tz2))->setTimezone($tz), false];
    }
    return [new DateTimeImmutable($value, $tz), false];
}

function ics_events(string $ics): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)) ?? '';
    $events = [];
    foreach (explode('BEGIN:VEVENT', $ics) as $i => $block) {
        if ($i === 0) continue;
        $block = explode('END:VEVENT', $block)[0];
        $e = ['exdate' => []];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', trim($line), $m)) continue;
            $params = [];
            foreach (array_filter(explode(';', $m[2])) as $pp) {
                [$k, $v] = array_pad(explode('=', $pp, 2), 2, '');
                $params[strtoupper($k)] = $v;
            }
            $name = $m[1];
            $val = $m[3];
            if ($name === 'EXDATE') {
                foreach (explode(',', $val) as $x) $e['exdate'][] = ics_dt($x, $params)[0]->getTimestamp();
            } elseif (in_array($name, ['DTSTART', 'DTEND', 'RECURRENCE-ID'], true)) {
                $e[$name] = ics_dt($val, $params);
            } else {
                $e[$name] = $val;
            }
        }
        if (!isset($e['DTSTART']) || ($e['STATUS'] ?? '') === 'CANCELLED') continue;
        $events[] = $e;
    }
    return $events;
}

function ics_duration(string $d): int
{
    if (!preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $d, $m)) return 3600;
    return ((int)($m[1] ?? 0)) * 604800 + ((int)($m[2] ?? 0)) * 86400 + ((int)($m[3] ?? 0)) * 3600 + ((int)($m[4] ?? 0)) * 60 + (int)($m[5] ?? 0);
}

/** Zasedeni intervali [začetek, konec] (unix) v oknu. */
function busy_intervals(string $ics, int $from, int $to, bool $allDayBlocks = true): array
{
    $events = ics_events($ics);
    $overridden = [];
    foreach ($events as $e) if (isset($e['RECURRENCE-ID'])) $overridden[($e['UID'] ?? '') . '|' . $e['RECURRENCE-ID'][0]->getTimestamp()] = true;

    $busy = [];
    foreach ($events as $e) {
        [$start, $allDay] = $e['DTSTART'];
        if ($allDay && !$allDayBlocks) continue;
        if (!$allDay && ($e['TRANSP'] ?? '') === 'TRANSPARENT') continue;
        $len = isset($e['DTEND']) ? $e['DTEND'][0]->getTimestamp() - $start->getTimestamp()
            : (isset($e['DURATION']) ? ics_duration($e['DURATION']) : ($allDay ? 86400 : 3600));
        $len = max($len, 60);
        $starts = isset($e['RRULE']) && !isset($e['RECURRENCE-ID']) ? rrule_expand($start, $e['RRULE'], $to) : [$start];
        foreach ($starts as $s) {
            $ts = $s->getTimestamp();
            if (in_array($ts, $e['exdate'], true)) continue;
            if (isset($e['RRULE']) && !isset($e['RECURRENCE-ID']) && isset($overridden[($e['UID'] ?? '') . '|' . $ts])) continue;
            $end = $allDay ? $s->modify('+' . max(1, (int)round($len / 86400)) . ' day')->getTimestamp() : $ts + $len;
            if ($end > $from && $ts < $to) $busy[] = [$ts, $end];
        }
    }
    usort($busy, fn($a, $b) => $a[0] <=> $b[0]);
    return $busy;
}

/** @return DateTimeImmutable[] */
function rrule_expand(DateTimeImmutable $start, string $rule, int $until): array
{
    $r = [];
    foreach (explode(';', $rule) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $r[strtoupper($k)] = $v;
    }
    $freq = $r['FREQ'] ?? 'DAILY';
    $int = max(1, (int)($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int)$r['COUNT'] : PHP_INT_MAX;
    $end = $until;
    if (isset($r['UNTIL'])) $end = min($end, ics_dt($r['UNTIL'], [])[0]->getTimestamp() + 86399 * (strlen($r['UNTIL']) === 8 ? 1 : 0));
    $out = [];
    $n = 0;
    if ($freq === 'WEEKLY' && !empty($r['BYDAY'])) {
        $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $days = array_values(array_filter(array_map(fn($d) => $map[substr($d, -2)] ?? null, explode(',', $r['BYDAY']))));
        sort($days);
        $weekStart = $start->modify('-' . ((int)$start->format('N') - 1) . ' day');
        for ($w = 0; $w < 600 && $n < $count; $w += $int) {
            foreach ($days as $d) {
                $occ = $weekStart->modify("+$w week")->modify('+' . ($d - 1) . ' day');
                if ($occ < $start) continue;
                if ($occ->getTimestamp() > $end || $n >= $count) break 2;
                $out[] = $occ;
                $n++;
            }
        }
        return $out;
    }
    $step = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'][$freq] ?? 'day';
    for ($i = 0; $i < 2000 && $n < $count; $i++) {
        $occ = $start->modify('+' . ($i * $int) . " $step");
        if ($occ->getTimestamp() > $end) break;
        $out[] = $occ;
        $n++;
    }
    return $out;
}

/**
 * Predlogi terminov: $n prostih terminov na različne dni, z rezervo za vožnjo pred in po.
 * @return array{termini:int[], napaka:?string}
 */
function free_slots(int $durMin, int $n = 3, ?array $cfg = null, ?int $now = null, ?string $icsOverride = null): array
{
    $cfg ??= config();
    $now ??= time();
    $busy = [];
    $err = null;
    if ($icsOverride !== null || $cfg['ical'] !== '') {
        [$ics, $err] = $icsOverride !== null ? [$icsOverride, null] : ical_fetch($cfg['ical']);
        if ($ics) $busy = busy_intervals($ics, $now, $now + KOLEDAR_DNI * 86400, (bool)$cfg['celodnevni_blokirajo']);
    } else {
        $err = 'Koledar ni povezan – termini so predlagani brez preverjanja zasedenosti.';
    }
    $buf = max(0, (int)$cfg['rezerva']) * 60;
    $dur = max(30, $durMin) * 60;
    [$oh, $om] = array_map('intval', explode(':', $cfg['od']));
    [$dh, $dm] = array_map('intval', explode(':', $cfg['do']));
    $out = [];
    $day = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Ljubljana'))->setTime(0, 0)->modify('+1 day');
    for ($i = 0; $i < KOLEDAR_DNI && count($out) < $n; $i++, $day = $day->modify('+1 day')) {
        if (!in_array((int)$day->format('N'), array_map('intval', $cfg['dnevi']), true)) continue;
        $open = $day->setTime($oh, $om)->getTimestamp();
        $close = $day->setTime($dh, $dm)->getTimestamp();
        // dolgo delo, ki presega dan: predlagaj začetek zjutraj
        $need = min($dur, $close - $open);
        for ($s = $open; $s + $need <= $close; $s += 1800) {
            $ok = true;
            foreach ($busy as [$b0, $b1]) {
                if ($b0 < $s + $need + $buf && $b1 > $s - $buf) { $ok = false; break; }
            }
            if ($ok) { $out[] = $s; break; }
        }
    }
    return ['termini' => $out, 'napaka' => $err];
}
