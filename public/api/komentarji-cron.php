<?php
/* Jutranji zagon komentarjev na YouTubu (samo iz ukazne vrstice, cron v cPanelu):
 *   40 6 * * * /usr/local/bin/php $HOME/public_html/api/komentarji-cron.php >> $HOME/komentarji.log 2>&1
 * Možnosti: --brez-poste (ne pošlje e-pošte)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__); // public_html: shramba je ena mapa višje (~/povprasevanja), kot pri spletnih straneh
require __DIR__ . '/_komentarji.php';
@set_time_limit(0);

$log = fn(string $m) => print(date('Y-m-d H:i:s') . " komentarji: $m\n");
$cfg = config();
kom_run($cfg, $log, !in_array('--brez-poste', $argv, true));
$u = kom_usage();
$log(sprintf('ta mesec: %.2f $ (Claude %.2f, Gemini %.2f), %d videov, %d objav; kvota YouTube danes %d enot',
    kom_spent(), $u['claude'], $u['gemini'], $u['videi'], $u['objave'], yt_units_used()));
