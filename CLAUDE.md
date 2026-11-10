# popravimvse.si – navodila za vsako spremembo

Stalno navodilo lastnika (Sebastjan Stjepanović): vsaka sprememba strani mora ostati
**Schema.org**, **SEO**, **GEO** in **agentic-search** optimizirana. Ne sprašuj znova, naredi vedno.

Postopek:
1. Uredi `content/*.html` (JSON glava: title ≤ 65 znakov, description 70–160 znakov, h1, lead, crumb) ali `build.py`.
2. Nova poslovna dejstva (cena, plačilo, publika, območje) vnesi povsod: vsebina, FAQ (`<details>` → FAQPage), JSON-LD v `build.py` (business_ld, hour_price, Service), `llms.txt` (Ključna dejstva). Brez protislovij med stranmi.
3. `python3 build.py`, nato preveri: vsi JSON-LD bloki se parsajo, dolžine meta, sitemap, 301 v `root/.htaccess` za odstranjene strani.
   Če build izpiše »pisava nima znakov«, zaženi `python3 tools/pisava.py` (podmnožica Archivo iz `fonts-src/`) in build znova.
   Zgodbe (`content/reference__*.html`) se samodejno pokažejo na straneh storitev iz `about` (ožje: `primer_za`), če jih vsebina še ne povezuje.
   Mobilno preveri pri 320, 360 in 412 px: brez vodoravnega drsenja (mreže z `minmax(0, 1fr)`).
4. Commit + push. Objava je samodejna: cron v cPanelu vsakih 30 minut (ob :00 in :30) naredi `git pull` z GitHuba (main) in, če je kaj novega,
   Deploy (`.cpanel.yml` kopira `public/` v `public_html`); dnevnik `/home/igvzfmyz/deploy.log`. Vse na `main` gre torej v živo v ≤ 30 min,
   zato spremembe strani pripravi na ločeni veji in jih na `main` prenesi šele, ko Sebastjan reče »objavi«.
   Hostko ne dovoli cron opravil pogosteje kot 1× na 30 min (splošni pogoji, razdelek Omejitve); krajši interval strežnik vsako uro sam vrne na `*/30`. Po objavi preveri živo stran.
   Ročno (če cron ne dela): Git Version Control → Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit.
   Po vsakem Deployu `.cpanel.yml` zažene `api/_indexnow.php`: IndexNow (Bing, Yandex, Seznam …) dobi samo strani s spremenjeno vsebino
   (ključ `root/0e6f1c85e2fd2860f1c9516442ade4d0.txt`, dnevnik `~/indexnow.log`, stanje `~/povprasevanja/indexnow.json`). Google IndexNow ne uporablja.

Vsebinska pravila:
- Preprost jezik, kratki citabilni stavki, konkretni primeri in številke. Ne izmišljuj dejstev.
- Brez reguliranih del (elektro, vodovod, plin, ometi, betoniranje); v primerih ne omenjaj vtičnic, luči, pip, bojlerjev.
- V referenci o vikendu ohrani omembo električnega bojlerja in preureditve ogrevanja.
- Fotografije: brez gesel/nalepk, tretjih oseb, registrskih tablic, AI slik; odstrani EXIF/GPS.

Dejstva (25. 10. 2026): 35 €/h, prevoz vključen; prva ura v celoti, nato na minuto natančno; če ne popravi, stranka ne plača nič (natančno: /cenik/#jamstvo – nič, če okvare ne more odpraviti, dela ni mogoče dobiti, je potreben pooblaščeni serviser ali se na kraju izkaže delo z obrtnim dovoljenjem; porabljeni čas s celo prvo uro, če stranka ne nadaljuje mogočega popravila, ne kupi potrebnega dela ali delo prekine; pred preseganjem ocene vpraša);
plačilo samo z gotovino na mestu, račun za vsako delo; ni zavezanec za DDV; samo posamezniki in gospodinjstva (ne podjetja);
sedež Ronkova ulica 4, 2380 Slovenj Gradec (pisarna), dela iz Pernice pri Mariboru.
Čas se šteje od prihoda k stranki (stisk roke), vožnja ne; dela pon–pet 6–18, zvečer in ob vikendih ne (4. 11. 2026, enako v Google profilu in openingHoursSpecification); material kupi stranka (Sebastjan svetuje, brez pribitka);
potrošni material vključen; nakup materiala na poti k stranki brezplačen samo, če stranka nima prevoza (po dogovoru; vrne znesek z računa); nadaljevanje drug dan brez nove prve ure; zmanjka orodja = se ne šteje;
če kaj ni prav (npr. silikon odstopi), popravi brezplačno; na željo stranke snema ves čas dela (akcijska kamera na prsih, hramba 30 dni, kopija na zahtevo), YouTube samo s posebnim dovoljenjem (le kader dela, pred objavo pokaže stranki) – /zasebnost/#snemanje; v druge prostore NE obljubljaj spremstva stranke; termina se drži – če je preveč zaseden, raje takoj odkloni; brez predplačila.
Pomočnik »Poskusite sami« (27. 10. 2026): UI klepet na vsaki strani (root/api/klepet.php, static/js/klepet.js), svetuje najcenejšo samopomoč, varnostna pravila (brez elektrike, vode pod tlakom, plina), predaja v obrazec; proračun 20 $/mesec (nastavitve), pogovori se shranijo samo s soglasjem 90 dni (/api/klepeti.php).
Google profil podjetja (preverjen 4. 11. 2026): https://www.google.com/maps?cid=17974519320533287577 – v sameAs in hasMap; opis in storitve brez reguliranih del, usklajeni s stranjo.
Komentarji na YouTubu (8. 11. 2026, veja yt-komentarji): /api/komentarji.php?k=… (ključ kot seznam), knjižnica root/api/_komentarji.php,
jutranji cron `40 6 * * * /usr/local/bin/php /home/igvzfmyz/public_html/api/komentarji-cron.php >> /home/igvzfmyz/komentarji.log 2>&1`.
Novi slovenski videi (spremljani kanali + iskanje) → hitri Claude razvrsti → Gemini si video ogleda prek povezave (brez prenosa) → Claude predlaga komentar
in odgovore na vprašanja gledalcev → Sebastjan objavi s klikom (nikoli samodejno; pravila YouTube API III.E.3.d). Odločitve 8. 11.: brez povezav in omemb strani
(kom_has_link zavrne), samo slovenski kanali (10. 11.: govor po Geminiju ≠ sl ali strojni prevod → neprimerno brez klica Claude; tuji kanali in »uradni kanal @… v slovenščini« se ne predlagajo, spremljani dobijo opozorilo), tudi odgovori gledalcem, do 5 predlogov na dan, največ 8 objav na dan. Povezati se sme samo kanal
UCOO6ihJvxkvTwFfNguWNO7w (yt-oauth.php zavrne drugega). Izkušnje v 1. osebi samo iz nastavitev (kom_izkusnje) in »iz_prakse« (_kontekst.json). Podatki tujih videov 30 dni.
Lokalni preizkus: PV_YT_URL, PV_GOOGLE_AUTH_URL, PV_GOOGLE_TOKEN_URL, PV_GOOGLE_REVOKE_URL, PV_GEMINI_URL (+ PV_ANTHROPIC_URL, PV_STORAGE, PV_MAIL_DIR).
Glavna prednost (USP, 27. 10. 2026): en mojster namesto desetih – več drobnih popravil v enem obisku (/drobna-popravila/ s seznamom za obkljukanje).
