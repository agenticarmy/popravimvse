#!/usr/bin/env python3
"""Zgradi statično stran popravimvse.si iz content/*.html v public/.

Vsaka datoteka v content/ se začne z vrstico <!--{json}--> (metapodatki strani),
sledi HTML vsebina. Zaženi: python3 build.py
"""
import json, re, shutil, html, subprocess, os, urllib.parse
from pathlib import Path
from datetime import date, datetime, timezone

ROOT = Path(__file__).parent
OUT = ROOT / ('_preview' if os.environ.get('PREVIEW') == '1' else 'public')
SITE = 'https://popravimvse.si'
TODAY = date.today().isoformat()
BUILT = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')

BIZ = {
    'name': 'Popravim vse',
    'person': 'Sebastjan',
    'legal': 'Sebastjan Stjepanović s.p.',
    'phone': '070 755 355',
    'phone_e164': '+38670755355',
    'email': 'popravimvse@gmail.com',
    'rate': '35 €',
    'same_as': [],  # povezave na Google Business, Facebook … ko obstajajo
    'address': {'addressLocality': 'Pernica', 'postalCode': '2231'},  # izhodišče dela (brez ulice); sedež s.p. je v SEAT
    'geo': (46.5785, 15.7269),  # Pernica
    'seat': 'Ronkova ulica 4, 2380 Slovenj Gradec',
}

PERSON = {
    'name': 'Sebastjan Stjepanović',
    'job': 'Hišni mojster',
    'bio': 'Samouk hišni mojster z več kot 30 leti izkušenj v rokodelstvu, od tega 15 let v Nemčiji.',
}
PERSON_ID = SITE + '/o-meni/#sebastjan'
BIZ_ID = SITE + '/#podjetje'
CITIES = ['Maribor', 'Lenart', 'Ptuj', 'Slovenska Bistrica', 'Hoče', 'Slivnica', 'Miklavž na Dravskem polju', 'Rače', 'Fram',
          'Pesnica', 'Šentilj', 'Kungota', 'Ruše', 'Selnica ob Dravi', 'Duplek', 'Starše', 'Kidričevo', 'Hajdina', 'Videm',
          'Markovci', 'Gorišnica', 'Destrnik', 'Juršinci', 'Benedikt', 'Cerkvenjak', 'Sveta Ana', 'Sveta Trojica v Slovenskih goricah',
          'Sveti Jurij v Slovenskih goricah', 'Poljčane', 'Makole', 'Oplotnica']

SERVICES = [
    ('polaganje-keramike', 'Polaganje keramike', 'tiles'),
    ('polaganje-talnih-oblog', 'Laminat in talne obloge', 'floor'),
    ('obnova-fug-in-silikona', 'Obnova fug in silikona', 'drop'),
    ('odmasevanje-odtokov', 'Odmaševanje odtokov', 'drain'),
    ('kopalniska-oprema', 'Kopalniška oprema', 'mirror'),
    ('pleskanje-in-kitanje', 'Pleskanje in kitanje', 'roller'),
    ('priklop-aparatov', 'Priklop aparatov', 'oven'),
    ('montaza-tv-in-polic', 'Montaža TV in polic', 'tv'),
    ('modem-in-termostat', 'Modem, Wi-Fi in termostat', 'wifi'),
    ('popravilo-vrat-in-oken', 'Vrata in okna', 'door'),
    ('tesarska-dela', 'Tesarska dela in pohištvo', 'saw'),
    ('selitve-in-prenosi', 'Manjše selitve in prenosi', 'box'),
    ('kosnja-in-urejanje-okolice', 'Košnja in urejanje okolice', 'grass'),
]
AREAS = [
    ('hisni-mojster-maribor', 'Maribor z okolico', 'Hoče, Slivnica, Miklavž, Rače, Fram, Pesnica, Šentilj, Kungota, Ruše, Selnica, Duplek, Starše'),
    ('hisni-mojster-lenart', 'Lenart in Slovenske gorice', 'Benedikt, Cerkvenjak, Sv. Ana, Sv. Trojica, Sv. Jurij v Slovenskih goricah'),
    ('hisni-mojster-ptuj', 'Ptuj z okolico', 'Kidričevo, Hajdina, Videm, Markovci, Gorišnica, Destrnik, Juršinci'),
    ('hisni-mojster-slovenska-bistrica', 'Slovenska Bistrica', 'Poljčane, Makole, Oplotnica'),
]
OTHER = [('cenik', 'Cenik'), ('o-meni', 'O meni'), ('kontakt', 'Kontakt')]
REFS = [('reference', 'Moja dela'), ('reference/prenova-lesenega-vikenda', 'Prenova lesenega vikenda')]
NAV = [('/#storitve', 'Storitve'), ('/reference/', 'Moja dela'), ('/cenik/', 'Cenik'), ('/o-meni/', 'O meni'), ('/kontakt/', 'Kontakt')]

PHOTOS = json.loads((ROOT / 'photos.json').read_text(encoding='utf-8'))
LISTS = json.loads((ROOT / 'content' / 'seznami.json').read_text(encoding='utf-8'))
PREVIEW = os.environ.get('PREVIEW') == '1'  # PREVIEW=1 zgradi tudi osnutke seznamov (samo za pregled, ne objavljaj)
KK = 'kaj-kupiti'


def lists_visible():
    return [l for l in LISTS['lists'] if l['status'] == 'objavljeno' or PREVIEW]
PMETA = json.loads((ROOT / 'photos_meta.json').read_text(encoding='utf-8'))
PORTRAIT = {'alt': 'Sebastjan Stjepanović, hišni mojster', 'sizes': [(240, 300), (480, 600), (810, 1012)]}
PORTRAIT_URL = SITE + '/static/img/sebastjan-stjepanovic-960.webp'
_used = []  # fotografije, uporabljene na trenutni strani (za JSON-LD in og:image)


def img_url(slug, w):
    return f'/static/img/dela/{slug}-{w}.webp'


def figure(slug, sizes='(max-width: 640px) 50vw, (max-width: 960px) 33vw, 260px', cls='ph'):
    ph, m = PHOTOS['photos'][slug], PMETA[slug]['sizes']
    if slug not in _used:
        _used.append(slug)
    srcset = ', '.join(f'{img_url(slug, w)} {m[w][0]}w' for w in ('480', '960', '1600'))
    w, h = m['960']
    return (f'<figure class="{cls}"><a href="{img_url(slug, 1600)}" target="_blank" rel="noopener">'
            f'<img src="{img_url(slug, 960)}" srcset="{srcset}" sizes="{sizes}" width="{w}" height="{h}" '
            f'loading="lazy" decoding="async" alt="{esc(ph["alt"])}"></a><figcaption>{ph["cap"]}</figcaption></figure>')


def gallery(key):
    items = PHOTOS['galleries'][key]
    n = len(items)
    cols = 1 if n == 1 else 2 if n in (2, 4) else 3
    sizes = {1: '(max-width: 640px) 100vw, 420px', 2: '(max-width: 640px) 50vw, 340px', 3: '(max-width: 640px) 50vw, (max-width: 960px) 33vw, 260px'}[cols]
    return f'<div class="gallery g{cols}">' + ''.join(figure(sl, sizes) for sl in items) + '</div>'


def portrait(cls='portrait', sizes='(max-width: 640px) 60vw, 300px', eager=False):
    srcset = ', '.join(f'/static/img/sebastjan-stjepanovic-{w if w != 810 else 960}.webp {w}w' for w, _ in PORTRAIT['sizes'])
    return (f'<img class="{cls}" src="/static/img/sebastjan-stjepanovic-480.webp" srcset="{srcset}" sizes="{sizes}" '
            f'width="480" height="600" {"" if eager else "loading=\"lazy\" "}decoding="async" alt="{PORTRAIT["alt"]}">')


def expand(body):
    body = re.sub(r'\{\{GALERIJA:([\w-]+)\}\}', lambda m: gallery(m.group(1)), body)
    body = re.sub(r'\{\{FOTO:([\w-]+)\}\}', lambda m: figure(m.group(1), '(max-width: 640px) 100vw, 420px', 'ph ph-single'), body)
    return body.replace('{{PORTRET}}', portrait())


def images_ld():
    out = []
    for sl in _used:
        ph, m = PHOTOS['photos'][sl], PMETA[sl]
        out.append({'@type': 'ImageObject', '@id': f'{SITE}{img_url(sl, 1600)}#slika', 'contentUrl': SITE + img_url(sl, 1600),
                    'thumbnailUrl': SITE + img_url(sl, 480), 'width': m['sizes']['1600'][0], 'height': m['sizes']['1600'][1],
                    'caption': re.sub(r'<[^>]+>', '', ph['cap']), 'description': ph['alt'], 'dateCreated': m['date'],
                    'creator': {'@id': PERSON_ID}, 'copyrightHolder': {'@id': PERSON_ID}, 'creditText': PERSON['name']})
    return out

S = 'fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"'
ICONS = {
    'tiles': f'<svg viewBox="0 0 24 24" {S}><rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/></svg>',
    'drop': f'<svg viewBox="0 0 24 24" {S}><path d="M12 3c3 4 5 6.6 5 9.2a5 5 0 0 1-10 0C7 9.6 9 7 12 3z"/><path d="M3 21c2-1.2 4-1.2 6 0s4 1.2 6 0 4-1.2 6 0"/></svg>',
    'drain': f'<svg viewBox="0 0 24 24" {S}><circle cx="12" cy="12" r="8.5"/><path d="M8.5 8h7M7 11h10M7 14h10M8.5 17h7"/></svg>',
    'tap': f'<svg viewBox="0 0 24 24" {S}><path d="M3 8v6"/><path d="M3 10h10a4 4 0 0 1 4 4v1"/><path d="M8 10V6h5"/><path d="M17 18.5v.5M17 21.5v.5"/></svg>',
    'oven': f'<svg viewBox="0 0 24 24" {S}><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M4 8h16"/><path d="M7.5 5.5h.01M10.5 5.5h.01"/><rect x="7" y="11" width="10" height="7"/></svg>',
    'bulb': f'<svg viewBox="0 0 24 24" {S}><path d="M9.5 18h5M10.5 21h3"/><path d="M12 3a6 6 0 0 0-3.4 10.9c.6.5.9 1.2.9 2V16h5v-.1c0-.8.3-1.5.9-2A6 6 0 0 0 12 3z"/></svg>',
    'door': f'<svg viewBox="0 0 24 24" {S}><path d="M6 21V3h12v18"/><path d="M3 21h18"/><path d="M15 12h.01"/></svg>',
    'saw': f'<svg viewBox="0 0 24 24" {S}><path d="M3 17L17 3l4 4L7 21H3z"/><path d="M6.5 13.5l1.5 1.5M9.5 10.5l1.5 1.5M12.5 7.5l1.5 1.5"/></svg>',
    'trowel': f'<svg viewBox="0 0 24 24" {S}><path d="M10 14l9-9v9h-9z"/><path d="M10 14l-2 2"/><path d="M8 16l-4.5 4.5"/></svg>',
    'brick': f'<svg viewBox="0 0 24 24" {S}><rect x="3" y="5" width="18" height="14"/><path d="M3 9.7h18M3 14.3h18M9 5v4.7M15 5v4.7M6 9.7v4.6M12 9.7v4.6M18 9.7v4.6M9 14.3V19M15 14.3V19"/></svg>',
    'mirror': f'<svg viewBox="0 0 24 24" {S}><rect x="6" y="3" width="12" height="11" rx="5.5"/><path d="M9.5 7.5l2-2M10 10.5l4-4"/><path d="M4 17h16v4H4z"/><path d="M12 17v4"/></svg>',
    'tv': f'<svg viewBox="0 0 24 24" {S}><rect x="3" y="5" width="18" height="12" rx="1"/><path d="M9 21h6M12 17v4"/><path d="M1.5 9v4M22.5 9v4"/></svg>',
    'roller': f'<svg viewBox="0 0 24 24" {S}><rect x="3" y="3" width="15" height="6" rx="1"/><path d="M18 6h3v5h-9v3"/><rect x="10.5" y="14" width="3" height="7" rx="1"/></svg>',
    'floor': f'<svg viewBox="0 0 24 24" {S}><path d="M3 8h18M3 13h18M3 18h18M3 3h18v18H3z"/><path d="M9 3v5M15 8v5M7 13v5M17 18v3"/></svg>',
    'wifi': f'<svg viewBox="0 0 24 24" {S}><path d="M2.5 9a14 14 0 0 1 19 0"/><path d="M5.5 12.5a9.5 9.5 0 0 1 13 0"/><path d="M8.7 16a5 5 0 0 1 6.6 0"/><path d="M12 19.5h.01"/></svg>',
    'box': f'<svg viewBox="0 0 24 24" {S}><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/></svg>',
    'grass': f'<svg viewBox="0 0 24 24" {S}><path d="M3 21h18"/><path d="M5 21c0-5 1-8 3-11M8 21c0-4 .5-7 2-9M12 21V9M16 21c0-4-.5-7-2-9M19 21c0-5-1-8-3-11"/></svg>',
    'phone': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6.6 3h3l1.5 4.5-2 1.5a12 12 0 0 0 6 6l1.5-2 4.5 1.5v3a2 2 0 0 1-2 2A17 17 0 0 1 4.5 5a2 2 0 0 1 2-2z"/></svg>',
}


def esc(s):
    return html.escape(s, quote=True)


def call_btn(cls='call'):
    return f'<a class="{cls}" href="tel:{BIZ["phone_e164"]}">{ICONS["phone"]}Pokličite {BIZ["phone"]}</a>'


def sms_btn():
    return f'<a class="btn-plain" href="sms:{BIZ["phone_e164"]}">Pošljite SMS s fotografijo</a>'


AUDIENCE = {'@type': 'PeopleAudience', 'audienceType': 'Posamezniki in gospodinjstva'}


def hour_price():
    return {'@type': 'UnitPriceSpecification', 'price': '35', 'priceCurrency': 'EUR', 'unitCode': 'HUR', 'unitText': 'ura',
            'eligibleQuantity': {'@type': 'QuantitativeValue', 'minValue': 1, 'unitCode': 'HUR'},
            'description': 'Čas se šteje od prihoda k stranki. Prva ura se obračuna v celoti, vsaka naslednja na minuto natančno. '
                           'Prevoz vključen, brez doplačil zvečer in ob vikendih. Potrošni material vključen; material kupi stranka. '
                           'Nadaljevanje drug dan brez nove prve ure. '
                           'Ni zavezanec za DDV, cena je končna.'}


def person_ld():
    d = {
        '@type': 'Person',
        '@id': PERSON_ID,
        'name': PERSON['name'],
        'givenName': 'Sebastjan',
        'familyName': 'Stjepanović',
        'jobTitle': PERSON['job'],
        'description': PERSON['bio'] + ' Po izobrazbi univerzitetni diplomirani ekonomist.',
        'url': SITE + '/o-meni/',
        'image': {'@type': 'ImageObject', 'url': PORTRAIT_URL, 'width': 810, 'height': 1012, 'caption': PORTRAIT['alt']},
        'worksFor': {'@id': BIZ_ID},
        'knowsAbout': [s[1] for s in SERVICES] + ['Hišna popravila', 'Vzdrževanje stanovanj in hiš'],
        'knowsLanguage': ['sl', 'de'],
        'hasOccupation': {'@type': 'Occupation', 'name': 'Hišni mojster', 'occupationLocation': [{'@type': 'City', 'name': c} for c in CITIES[:4]],
                          'skills': ', '.join(s[1] for s in SERVICES)},
        'hasCredential': {'@type': 'EducationalOccupationalCredential', 'credentialCategory': 'degree',
                          'name': 'Univerzitetni diplomirani ekonomist'},
    }
    if BIZ['same_as']:
        d['sameAs'] = BIZ['same_as']
    return d


def business_ld():
    d = {
        '@type': 'HomeAndConstructionBusiness',
        '@id': BIZ_ID,
        'name': f'{BIZ["name"]} – {BIZ["legal"]}',
        'alternateName': [f'{BIZ["person"]} – {BIZ["name"]}', BIZ['name']],
        'legalName': BIZ['legal'],
        'description': 'Hišni mojster za Maribor, Lenart, Ptuj in Slovensko Bistrico z okolico: keramika, laminat in talne obloge, '
                       'fuge in silikon, odtoki, kopalniška oprema, pleskanje in kitanje, priklop aparatov, montaža TV in polic, '
                       'modemi in pametni termostati, vrata in okna, tesarska dela in pohištvo, manjše selitve in prenosi, pomoč kot druga roka, košnja, obrezovanje žive meje in urejanje okolice. '
                       '35 € na uro, prevoz vključen; prva ura se obračuna v celoti, nato na minuto natančno; '
                       'če popravila ni mogoče opraviti, stranka ne plača nič. Plačilo z gotovino na mestu. '
                       'Dela samo za posameznike in gospodinjstva. Termina se drži; brez predplačila; račun za vsako delo; '
                       'če kaj ni prav, popravi brezplačno; brez doplačil zvečer in ob vikendih; material kupi stranka po njegovem nasvetu.',
        'slogan': 'Če ne popravim, ne plačate nič.',
        'knowsLanguage': ['sl', 'de'],
        'url': SITE + '/',
        'image': PORTRAIT_URL,
        'telephone': BIZ['phone_e164'],
        'email': BIZ['email'],
        'priceRange': '35 € na uro',
        'currenciesAccepted': 'EUR',
        'paymentAccepted': 'Gotovina',
        'founder': {'@id': PERSON_ID},
        'employee': {'@id': PERSON_ID},
        'contactPoint': {'@type': 'ContactPoint', 'contactType': 'customer service', 'telephone': BIZ['phone_e164'],
                         'email': BIZ['email'], 'availableLanguage': ['sl', 'de'], 'areaServed': 'SI'},
        'areaServed': [{'@type': 'City', 'name': n} for n in CITIES],
        'knowsAbout': [s[1] for s in SERVICES],
        'hasOfferCatalog': {'@type': 'OfferCatalog', 'name': 'Storitve hišnega mojstra', 'itemListElement': [
            {'@type': 'Offer', 'itemOffered': {'@type': 'Service', 'name': t, 'url': f'{SITE}/{sl}/', 'audience': AUDIENCE},
             'priceSpecification': hour_price(), 'acceptedPaymentMethod': 'http://purl.org/goodrelations/v1#Cash'}
            for sl, t, _ in SERVICES]},
    }
    if BIZ['same_as']:
        d['sameAs'] = BIZ['same_as']
    if BIZ['address']:
        d['address'] = {'@type': 'PostalAddress', 'addressCountry': 'SI', 'addressRegion': 'Podravska', **BIZ['address']}
        d['geo'] = {'@type': 'GeoCoordinates', 'latitude': BIZ['geo'][0], 'longitude': BIZ['geo'][1]}
    return d


def git_dates(name):
    f = f'content/{name}' if '.' in name else f'content/{name}.html'
    try:
        log = subprocess.run(['git', 'log', '--follow', '--format=%cs', '--', f], cwd=ROOT, capture_output=True, text=True).stdout.split()
        dirty = subprocess.run(['git', 'status', '--porcelain', '--', f], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    except OSError:
        return TODAY, TODAY
    if not log:
        return TODAY, TODAY
    return log[-1], (TODAY if dirty else log[0])


def sl_date(iso):
    y, m, d = iso.split('-')
    return f'{int(d)}. {int(m)}. {y}'


def author_box(modified):
    return f'''<aside class="author" aria-label="Avtor">
  <img class="avatar" src="/static/img/sebastjan-avatar-160.webp" width="80" height="80" loading="lazy" decoding="async" alt="">
  <div>
  <p><strong>Avtor: <a href="/o-meni/">{PERSON["name"]}</a></strong>, samouk hišni mojster z več kot 30 leti izkušenj v rokodelstvu. Dela, ki jih opisujem, opravljam na domovih strank v Mariboru, Slovenskih goricah, na Ptuju in v Slovenski Bistrici.</p>
  <p class="updated">Posodobljeno: <time datetime="{modified}">{sl_date(modified)}</time></p>
  </div>
</aside>'''


def faq_ld(body):
    qs = re.findall(r'<details>\s*<summary>(.*?)</summary>(.*?)</details>', body, re.S)
    if not qs:
        return None
    strip = lambda t: re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', t)).strip()
    return {'@type': 'FAQPage', 'mainEntity': [
        {'@type': 'Question', 'name': strip(q), 'acceptedAnswer': {'@type': 'Answer', 'text': strip(a)}} for q, a in qs]}


def header(current):
    links = ''.join(
        f'<li><a href="{h}"{" aria-current=\"page\"" if h == current else ""}>{t}</a></li>' for h, t in NAV)
    return f'''<a class="skip" href="#vsebina">Na vsebino</a>
<header class="site-head">
  <div class="wrap">
    <a class="brand" href="/"><b>Popravim vse</b><span>Sebastjan, hišni mojster</span></a>
    <nav class="nav" aria-label="Glavni meni">
      <ul>{links}</ul>
      <a class="call small" href="tel:{BIZ["phone_e164"]}">{ICONS["phone"]}{BIZ["phone"]}</a>
      <details class="menu"><summary>Meni</summary><ul>{links}</ul></details>
    </nav>
  </div>
</header>'''


def footer():
    svc = ''.join(f'<li><a href="/{s}/">{t}</a></li>' for s, t, _ in SERVICES)
    ar = ''.join(f'<li><a href="/{s}/">{t}</a></li>' for s, t, _ in AREAS)
    return f'''<footer class="site-foot">
  <div class="wrap">
    <div>
      <h2>Popravim vse</h2>
      <p>Hišni mojster za Maribor, Lenart, Ptuj in Slovensko Bistrico z okolico.</p>
      <p><a href="tel:{BIZ["phone_e164"]}">{BIZ["phone"]}</a><br><a href="mailto:{BIZ["email"]}">{BIZ["email"]}</a></p>
    </div>
    <div><h2>Storitve</h2><ul>{svc}</ul></div>
    <div><h2>Območje</h2><ul>{ar}</ul></div>
    <div><h2>Več</h2><ul><li><a href="/reference/">Moja dela</a></li><li><a href="/cenik/">Cenik</a></li>{'<li><a href="/kaj-kupiti/">Kaj kupiti</a></li>' if lists_visible() else ''}<li><a href="/o-meni/">O meni</a></li><li><a href="/kontakt/">Kontakt</a></li></ul></div>
    <p class="legal">{BIZ["legal"]}, sedež: {BIZ["seat"]}. Delam iz Pernice pri Mariboru. Urna postavka {BIZ["rate"]}, prevoz vključen. Če popravila ni mogoče opraviti, ne plačate nič. Nisem zavezanec za DDV.</p>
  </div>
</footer>
<div class="callbar">{call_btn('call')}<a class="btn-plain sms" href="sms:{BIZ["phone_e164"]}">SMS</a></div>'''


def page(meta, main, path, extra_ld=()):
    url = SITE + path
    wp = {
        '@type': meta.get('page_type', 'WebPage'), '@id': url + '#stran', 'url': url, 'name': meta['title'],
        'description': meta['description'], 'inLanguage': 'sl-SI', 'isPartOf': {'@id': SITE + '/#splet'},
        'about': {'@id': PERSON_ID if meta.get('page_type') == 'ProfilePage' else BIZ_ID},
        'author': {'@id': PERSON_ID}, 'reviewedBy': {'@id': PERSON_ID}, 'publisher': {'@id': BIZ_ID}}
    if meta.get('published'):
        wp['datePublished'] = meta['published']
        wp['dateModified'] = meta['modified']
    if meta.get('page_type') == 'ProfilePage':
        wp['mainEntity'] = {'@id': PERSON_ID}
    graph = [business_ld(), person_ld(), wp,
             {'@type': 'WebSite', '@id': SITE + '/#splet', 'url': SITE + '/', 'name': 'Popravim vse', 'inLanguage': 'sl-SI',
              'publisher': {'@id': BIZ_ID}}]
    graph += [g for g in extra_ld if g]
    imgs = images_ld()
    if imgs:
        graph += imgs
        wp['primaryImageOfPage'] = {'@id': imgs[0]['@id']}
        wp['image'] = [{'@id': i['@id']} for i in imgs]
    og_img = imgs[0]['contentUrl'] if imgs else PORTRAIT_URL
    if meta.get('page_type') == 'ProfilePage':
        og_img = PORTRAIT_URL
    ld = json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False)
    return f'''<!doctype html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(meta["title"])}</title>
<meta name="description" content="{esc(meta["description"])}">
<link rel="canonical" href="{url}">
<meta property="og:type" content="website">
<meta property="og:locale" content="sl_SI">
<meta property="og:site_name" content="Popravim vse">
<meta property="og:title" content="{esc(meta["title"])}">
<meta property="og:description" content="{esc(meta["description"])}">
<meta property="og:url" content="{url}">
<meta name="author" content="{PERSON["name"]}">
<meta property="og:image" content="{og_img}">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#16232B">
<link rel="icon" href="/static/img/favicon.svg" type="image/svg+xml">
<link rel="preload" href="/static/fonts/archivo-latin-standard-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/static/css/site.css?v={TODAY}">
<script type="application/ld+json">{ld}</script>
</head>
<body>
{header(meta.get("nav", ""))}
<main id="vsebina">
{main}
</main>
{footer()}
</body>
</html>
<!-- zgrajeno {BUILT} -->
'''


def aside(current_slug):
    svc = ''.join(
        f'<li><a href="/{s}/"{" aria-current=\"page\"" if s == current_slug else ""}>{t}</a></li>' for s, t, _ in SERVICES)
    ar = ''.join(
        f'<li><a href="/{s}/"{" aria-current=\"page\"" if s == current_slug else ""}>{t}</a></li>' for s, t, _ in AREAS)
    return f'''<aside class="aside" aria-label="Cena in povezave">
  <div class="card">
    <span class="rate">35 € na uro</span>
    <p>Prevoz je vključen. Če popravila ni mogoče opraviti, ne plačate nič. Nisem zavezanec za DDV.</p>
    {call_btn("call small")}
  </div>
  <nav class="card" aria-label="Storitve"><h2>Storitve</h2><ul>{svc}</ul></nav>
  <nav class="card" aria-label="Območje"><h2>Območje</h2><ul>{ar}</ul></nav>
</aside>'''


def cta_band():
    return f'''<section class="cta-band">
  <div class="wrap">
    <div><h2>Opišite, kaj je treba popraviti</h2><p>Pokličite ali pošljite fotografijo. Okvirno oceno časa dobite vnaprej.</p></div>
    {call_btn()}
  </div>
</section>'''


def buy_box(slug):
    ls = [l for l in lists_visible() if l['service'] == slug]
    if not ls:
        return ''
    items = ''.join(f'<li><a href="/{KK}/{l["slug"]}/">{l["h1"]}</a></li>' for l in ls)
    return f'<aside class="buy-box"><h2>Kaj kupiti pred mojim prihodom</h2><p>Material kupite sami, po ceni iz trgovine. Tu je zapisano, kaj točno in koliko:</p><ul>{items}</ul></aside>'


def inner(meta, body, slug, crumbs):
    body = expand(body)
    if meta.get('kind') == 'service':
        box = buy_box(slug)
        if box:
            body = body.replace('<h2>Pogosta vprašanja</h2>', box + '\n<h2>Pogosta vprašanja</h2>', 1) if '<h2>Pogosta vprašanja</h2>' in body else body + box
    crumb_html = '<nav class="crumbs" aria-label="Drobtinice"><ol><li><a href="/">Domov</a></li>' + ''.join(
        f'<li><a href="{h}">{t}</a></li>' for h, t in crumbs) + f'<li aria-current="page">{esc(meta["crumb"])}</li></ol></nav>'
    main = f'''<section class="page-head">
  <div class="wrap">
    {crumb_html}
    <h1>{meta["h1"]}</h1>
    <p class="lead">{meta["lead"]}</p>
    <div class="actions">{call_btn()}{sms_btn()}</div>
  </div>
</section>
<div class="wrap layout">
  <article class="prose">
{body}
{author_box(meta["modified"])}
  </article>
  {aside(slug)}
</div>
{cta_band()}'''
    items = [{'@type': 'ListItem', 'position': 1, 'name': 'Domov', 'item': SITE + '/'}]
    for i, (h, t) in enumerate(crumbs, 2):
        items.append({'@type': 'ListItem', 'position': i, 'name': t, 'item': SITE + h})
    items.append({'@type': 'ListItem', 'position': len(items) + 1, 'name': meta['crumb'], 'item': f'{SITE}/{slug}/'})
    ld = [{'@type': 'BreadcrumbList', 'itemListElement': items}, faq_ld(body)]
    if meta.get('kind') == 'article':
        ld.append({'@type': 'Article', '@id': f'{SITE}/{slug}/#clanek', 'headline': meta['h1'], 'description': meta['description'],
                   'inLanguage': 'sl-SI', 'author': {'@id': PERSON_ID}, 'publisher': {'@id': BIZ_ID},
                   'datePublished': meta['published'], 'dateModified': meta['modified'], 'mainEntityOfPage': {'@id': f'{SITE}/{slug}/#stran'},
                   'about': [{'@type': 'Service', 'name': dict((x[0], x[1]) for x in SERVICES)[sl], 'url': f'{SITE}/{sl}/',
                              'provider': {'@id': BIZ_ID}} for sl in meta.get('about', [])],
                   'image': [SITE + img_url(sl, 1600) for sl in _used]})
    if meta.get('kind') == 'service':
        ld.append({'@type': 'Service', '@id': f'{SITE}/{slug}/#storitev', 'name': meta['crumb'], 'serviceType': meta['crumb'],
                   'description': meta['description'], 'url': f'{SITE}/{slug}/',
                   'provider': {'@id': BIZ_ID}, 'areaServed': [{'@type': 'City', 'name': c} for c in CITIES], 'audience': AUDIENCE,
                   'offers': {'@type': 'Offer', 'priceCurrency': 'EUR', 'price': '35', 'priceSpecification': hour_price(),
                              'acceptedPaymentMethod': 'http://purl.org/goodrelations/v1#Cash', 'seller': {'@id': BIZ_ID}}})
    return main, ld


def home_main(body):
    body = expand(body)
    tiles = ''.join(f'<li><a href="/{s}/">{ICONS[i]}<span>{t}</span></a></li>' for s, t, i in SERVICES)
    tiles += '<li><a href="/kontakt/" class="tile-ask"><svg viewBox="0 0 24 24" ' + S + '><path d="M9 9a3 3 0 1 1 4 2.8c-.6.3-1 .9-1 1.5V14"/><path d="M12 17.5h.01"/></svg><span>Česa ni na seznamu? Vprašajte</span></a></li>'
    tiles += '<li><div class="tile-note"><b>35 €/h</b>Prevoz vključen</div></li>'
    return body.replace('{{TILES}}', tiles).replace('{{CALL}}', call_btn()).replace('{{SMS}}', sms_btn()) \
               .replace('{{AREAS}}', ''.join(
        f'<div class="area"><h3><a href="/{s}/">{t}</a></h3><p>{towns}</p></div>' for s, t, towns in AREAS)) \
               + cta_band()


def list_plain(l):
    lines = [l['h1'] + ':'] + [f"- {m['ime']}: {m['kolicina']}" for m in l['material']]
    lines.append(f"Seznam: {SITE}/{KK}/{l['slug']}/")
    return '\n'.join(lines)


def mark(t):
    return t.replace('[POTRDI]', '<mark class="confirm">[POTRDI]</mark>')


def list_body(l):
    draft = '' if l['status'] == 'objavljeno' else '<p class="draft">OSNUTEK ZA PREGLED – ni objavljeno. Rumeno označene točke potrdi ali popravi Sebastjan.</p>'
    plain = list_plain(l)
    sms = 'sms:?&body=' + urllib.parse.quote(plain)
    kratko = ''.join(f'<li>{mark(x)}</li>' for x in l['kratko'])
    rows = ''.join(
        f'<tr><td class="chk"><input type="checkbox" aria-label="Kupljeno: {esc(m["ime"])}"></td><th scope="row">{m["ime"]}</th>'
        f'<td data-label="Katero vrsto">{m["vrsta"]}</td><td data-label="Koliko">{m["kolicina"]}</td><td data-label="Na kaj paziti">{m["opomba"]}</td></tr>' for m in l['material'])
    walls = ''
    if l.get('tabela_stene'):
        walls = '<h2>Katere vložke za katero steno</h2>\n<table class="walls"><thead><tr><th>Stena</th><th>Kako jo prepoznate</th><th>Kaj kupiti</th></tr></thead><tbody>' + ''.join(
            f'<tr><th scope="row">{w["stena"]}</th><td data-label="Kako jo prepoznate">{w["prepoznate"]}</td><td data-label="Kaj kupiti">{w["kupite"]}</td></tr>' for w in l['tabela_stene']) + '</tbody></table>'
    tools = ', '.join(l['prinesem'])
    napake = ''.join(f'<li>{x}</li>' for x in l['napake'])
    koraki = ''.join(f'<li><strong>{k["ime"]}.</strong> {k["text"]}</li>' for k in l['koraki'])
    faq = ''.join(f'<details>\n<summary>{f["q"]}</summary>\n<p>{f["a"]}</p>\n</details>\n' for f in l['faq'])
    foto = f'{{{{FOTO:{l["foto"]}}}}}' if l.get('foto') else ''
    reviewed = f'<p class="reviewed">Seznam je pregledal in potrdil {PERSON["name"]}, <time datetime="{l["pregledano"]}">{sl_date(l["pregledano"])}</time>.</p>' if l.get('pregledano') else ''
    svc = dict((x[0], x[1]) for x in SERVICES)[l['service']]
    return f"""{draft}
<section class="answer" aria-labelledby="na-kratko">
<h2 id="na-kratko">Na kratko: kaj kupiti</h2>
<ul class="checks">{kratko}</ul>
<div class="list-tools"><button type="button" class="btn-plain" data-copy="{esc(plain)}">Kopiraj seznam</button><a class="btn-plain" href="{esc(sms)}">Pošlji si po SMS</a><button type="button" class="btn-plain" onclick="window.print()">Natisni</button></div>
</section>

<h2>Seznam za nakup</h2>
<div class="table-wrap"><table class="buy"><thead><tr><th class="chk">Kupljeno</th><th>Kaj kupiti</th><th>Katero vrsto</th><th>Koliko</th><th>Na kaj paziti</th></tr></thead><tbody>{rows}</tbody></table></div>
{walls}

<h2>Kako izračunati, koliko kupiti</h2>
{l['izracun']}

<h2>Česa vam ni treba kupiti</h2>
<p>To prinesem jaz: {tools}. Potrošni material, kot so brusni papir, folija, lepilni trak in krpe, je vključen v ceno 35 € na uro.</p>

<h2>Pogoste napake pri nakupu</h2>
<ul>{napake}</ul>

<h2>Kako poteka delo</h2>
<ol>{koraki}</ol>

<h2>Iz moje prakse</h2>
{foto}
<blockquote class="tip"><p>{mark(l['opomba_mojstra'])}</p><footer>{PERSON['name']}, hišni mojster</footer></blockquote>
<p class="note">{l['meja']}</p>

<h2>Ali je seznam pravi za vas?</h2>
<p>Pošljite fotografijo po SMS-u na <a href="sms:{BIZ['phone_e164']}">{BIZ['phone']}</a> ali na <a href="mailto:{BIZ['email']}">{BIZ['email']}</a>. Brezplačno vam potrdim, ali je to pravi material za vaš primer. Če nimate prevoza, ga po dogovoru kupim na poti k vam. Več o storitvi: <a href="/{l['service']}/">{svc.lower()}</a>.</p>
{reviewed}

<h2>Pogosta vprašanja</h2>
<div class="faq">
{faq}</div>
<script>document.querySelectorAll('[data-copy]').forEach(function(b){{b.addEventListener('click',function(){{navigator.clipboard.writeText(b.dataset.copy).then(function(){{b.textContent='Kopirano ✓'}})}})}})</script>"""


def list_ld(l, meta):
    url = f'{SITE}/{KK}/{l["slug"]}/'
    svc = dict((x[0], x[1]) for x in SERVICES)[l['service']]
    d = {'@type': 'HowTo', '@id': url + '#seznam', 'name': l['h1'], 'description': l['description'], 'inLanguage': 'sl-SI',
         'author': {'@id': PERSON_ID}, 'publisher': {'@id': BIZ_ID}, 'mainEntityOfPage': {'@id': url + '#stran'},
         'datePublished': meta['published'], 'dateModified': meta['modified'],
         'about': {'@type': 'Service', 'name': svc, 'url': f'{SITE}/{l["service"]}/', 'provider': {'@id': BIZ_ID}},
         'supply': [{'@type': 'HowToSupply', 'name': m['ime'], 'description': m['vrsta'], 'requiredQuantity': m['kolicina']} for m in l['material']],
         'tool': [{'@type': 'HowToTool', 'name': t, 'description': 'Prinese hišni mojster.'} for t in l['prinesem']],
         'step': [{'@type': 'HowToStep', 'position': i, 'name': k['ime'], 'text': k['text']} for i, k in enumerate(l['koraki'], 1)]}
    if l.get('foto'):
        d['image'] = SITE + img_url(l['foto'], 1600)
    return d


def lists_llms():
    ls = lists_visible()
    if not ls:
        return ''
    rows = '\n'.join(f'- [{l["h1"]}]({SITE}/{KK}/{l["slug"]}/): {l["description"]}' for l in ls)
    return f'## Kaj kupiti (seznami materiala)\nMaterial kupi stranka po Sebastjanovem nasvetu. Strojno berljivo: {SITE}/seznami.json\n{rows}\n\n'


def llms_txt(urls):
    m = {u: meta for u, _, _, meta in urls}
    svc = '\n'.join(f'- [{t}]({SITE}/{s}/): {m[f"/{s}/"]["description"]}' for s, t, _ in SERVICES if f'/{s}/' in m)
    ar = '\n'.join(f'- [{t}]({SITE}/{s}/): {towns}' for s, t, towns in AREAS)
    return f'''# Popravim vse – {PERSON["name"]}, hišni mojster

> Samostojni hišni mojster ({BIZ["legal"]}) za Maribor, Lenart in Slovenske gorice, Ptuj in Slovensko Bistrico z okolico. Več kot 30 let izkušenj v rokodelstvu (samouk, 15 let dela v Nemčiji). Urna postavka 35 € (ni zavezanec za DDV), prevoz vključen; če popravila ni mogoče opraviti, stranka ne plača nič.

## Ključna dejstva
- Cena: 35 € na uro za vsa dela, prevoz vključen na celotnem območju. Prva ura se obračuna v celoti, vsaka naslednja na minuto natančno. Plačilo samo z gotovino na mestu. Ni zavezanec za DDV.
- Dela samo za posameznike in gospodinjstva, ne za podjetja.
- Sedež s.p. je v Slovenj Gradcu (tam ima pisarno), dela pa iz Pernice pri Mariboru.
- Če popravila ni mogoče opraviti, stranka ne plača ne časa ne prevoza.
- Termina se drži; če je preveč zaseden, delo raje takoj odkloni, kot da bi dajal lažno upanje.
- Če se po njegovem delu izkaže, da kaj ni prav (npr. silikon odstopi), pride in popravi brezplačno.
- Čas se začne šteti ob prihodu k stranki (vožnja se ne šteje). Brez doplačil zvečer, v soboto in nedeljo.
- Potrošni material (brusni papir, folija, lepilni trak, obraba svedrov) je vključen v urno postavko; material, ki ostane pri stranki, kupi stranka po ceni iz trgovine. Nasvet pred obiskom je brezplačen.
- Če stranka nima prevoza, kupi material na poti k njej brezplačno; stranka vrne znesek z računa trgovine.
- Nepredviden material med delom: stranka izbere (on gre in čas šteje / gre stranka / drug dan). Nadaljevanje drug dan nima nove prve ure. Če njemu zmanjka orodja, se ta čas ne šteje.
- Brez predplačila: delo se plača ob koncu; brez dodatkov k urni postavki; pred dodatnim delom vpraša za soglasje; ne daje popusta brez računa; dela sam (plača se ena oseba).
- Okvirno oceno časa stranka dobi vnaprej po opisu ali fotografiji. Material kupi stranka; Sebastjan vnaprej svetuje, kaj kupiti (brez pribitka na material).
- Ne pije alkohola in ne kadi; delovišče zapusti čisto in urejeno; med delom je telefon tiho (čas plača stranka); je odgovorno odškodninsko zavarovan.
- Ne izvaja (regulirane dejavnosti z obrtnim dovoljenjem): del na električni, vodovodni in plinski napeljavi (luči, vtičnice, pipe, bojlerji), betoniranja, ometov in novih predelnih sten; prav tako ne polaga tlakovcev.
- Kontakt: telefon {BIZ["phone"]} ({BIZ["phone_e164"]}), SMS na isto številko, e-pošta {BIZ["email"]}.
- Jezik: slovenščina in tekoča nemščina.

## Storitve
{svc}

## Območje
{ar}

## Reference
- [Moja dela]({SITE}/reference/): fotografije opravljenih del po storitvah
- [Prenova lesenega vikenda]({SITE}/reference/prenova-lesenega-vikenda/): kuhinja po meri, tla, kopalnica, ogrevanje, sanacija lesnega črva

{lists_llms()}## Ostalo
- [Cenik]({SITE}/cenik/): urna postavka in kako se obračuna
- [O meni]({SITE}/o-meni/): kdo je Sebastjan Stjepanović
- [Kontakt]({SITE}/kontakt/): telefon, SMS, e-pošta
'''


def read(name):
    f = ROOT / 'content' / f'{name}.html'
    if not f.exists():
        print(f'OPOZORILO: manjka content/{name}.html')
        return None, None
    raw = f.read_text(encoding='utf-8')
    m = re.match(r'\s*<!--(.*?)-->\s*', raw, re.S)
    return json.loads(m.group(1)), raw[m.end():]


def reset_used():
    _used.clear()


def write(path, text):
    p = OUT / path.strip('/') / 'index.html' if path != '/' else OUT / 'index.html'
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(text, encoding='utf-8')


def main():
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir()
    shutil.copytree(ROOT / 'static', OUT / 'static')
    for f in (ROOT / 'root').glob('*'):
        shutil.copy(f, OUT / f.name)
    urls = []

    reset_used()
    meta, body = read('index')
    meta['nav'] = '/'
    meta['published'], meta['modified'] = git_dates('index')
    write('/', page(meta, home_main(body), '/', [faq_ld(body)]))
    urls.append(('/', '1.0', meta['modified'], meta))

    for slug, title, _ in SERVICES:
        reset_used()
        meta, body = read(slug)
        if meta is None: continue
        meta['kind'] = 'service'
        meta.setdefault('crumb', title)
        meta['published'], meta['modified'] = git_dates(slug)
        m, ld = inner(meta, body, slug, [('/#storitve', 'Storitve')])
        write(f'/{slug}/', page(meta, m, f'/{slug}/', ld))
        urls.append((f'/{slug}/', '0.9', meta['modified'], meta))
    for slug, title, _ in AREAS:
        reset_used()
        meta, body = read(slug)
        if meta is None: continue
        meta.setdefault('crumb', title)
        meta['published'], meta['modified'] = git_dates(slug)
        m, ld = inner(meta, body, slug, [('/#obmocje', 'Območje')])
        write(f'/{slug}/', page(meta, m, f'/{slug}/', ld))
        urls.append((f'/{slug}/', '0.9', meta['modified'], meta))
    for slug, title in OTHER:
        reset_used()
        meta, body = read(slug)
        if meta is None: continue
        meta.setdefault('crumb', title)
        meta['nav'] = f'/{slug}/'
        meta['published'], meta['modified'] = git_dates(slug)
        if slug == 'o-meni':
            meta['page_type'] = 'ProfilePage'
        elif slug == 'kontakt':
            meta['page_type'] = 'ContactPage'
        m, ld = inner(meta, body, slug, [])
        write(f'/{slug}/', page(meta, m, f'/{slug}/', ld))
        urls.append((f'/{slug}/', '0.7', meta['modified'], meta))

    for slug, title in REFS:
        reset_used()
        meta, body = read(slug.replace('/', '__'))
        if meta is None: continue
        meta.setdefault('crumb', title)
        meta['nav'] = '/reference/'
        meta['published'], meta['modified'] = git_dates(slug.replace('/', '__'))
        if '/' in slug:
            meta['kind'] = 'article'
            crumbs = [('/reference/', 'Moja dela')]
        else:
            meta['page_type'] = 'CollectionPage'
            crumbs = []
        m, ld = inner(meta, body, slug, crumbs)
        write(f'/{slug}/', page(meta, m, f'/{slug}/', ld))
        urls.append((f'/{slug}/', '0.8', meta['modified'], meta))

    ls = lists_visible()
    if ls:
        hub = dict(LISTS['hub'])
        hub['nav'] = ''
        hub['page_type'] = 'CollectionPage'
        hub['published'], hub['modified'] = git_dates('seznami.json')
        cards = ''.join(f'<li><a href="/{KK}/{l["slug"]}/"><strong>{l["h1"]}</strong><span>{l["description"]}</span></a></li>' for l in ls)
        hbody = f'<ul class="list-cards">{cards}</ul>\n<p>Ne najdete seznama za svoje delo? Pošljite mi opis ali fotografijo na <a href="sms:{BIZ["phone_e164"]}">{BIZ["phone"]}</a>, pa vam brezplačno povem, kaj kupiti.</p>'
        reset_used()
        m, ld = inner(hub, hbody, KK, [])
        ld.append({'@type': 'ItemList', 'itemListElement': [
            {'@type': 'ListItem', 'position': i, 'url': f'{SITE}/{KK}/{l["slug"]}/', 'name': l['h1']} for i, l in enumerate(ls, 1)]})
        write(f'/{KK}/', page(hub, m, f'/{KK}/', ld))
        urls.append((f'/{KK}/', '0.8', hub['modified'], hub))
        public = []
        for l in ls:
            reset_used()
            meta = {k: l[k] for k in ('title', 'description', 'h1', 'lead', 'crumb')}
            meta['published'], meta['modified'] = git_dates('seznami.json')
            m, ld = inner(meta, list_body(l), f'{KK}/{l["slug"]}', [(f'/{KK}/', LISTS['hub']['crumb'])])
            ld.append(list_ld(l, meta))
            write(f'/{KK}/{l["slug"]}/', page(meta, m, f'/{KK}/{l["slug"]}/', ld))
            urls.append((f'/{KK}/{l["slug"]}/', '0.7', meta['modified'], meta))
            public.append({'url': f'{SITE}/{KK}/{l["slug"]}/', 'naslov': l['h1'], 'storitev': f'{SITE}/{l["service"]}/',
                           'posodobljeno': meta['modified'], 'pregledal': PERSON['name'] if l.get('pregledano') else None,
                           'na_kratko': [re.sub(r'<[^>]+>', '', x) for x in l['kratko']],
                           'material': [{k: m2[k] for k in ('ime', 'vrsta', 'kolicina', 'opomba')} for m2 in l['material']],
                           'prinese_mojster': l['prinesem'], 'stene': l.get('tabela_stene', [])})
        (OUT / 'seznami.json').write_text(json.dumps({'opis': 'Seznami materiala Popravim vse. Material kupi stranka, orodje prinese mojster.',
                                                      'seznami': public}, ensure_ascii=False, indent=1), encoding='utf-8')

    reset_used()
    meta, body = read('404')
    if meta: (OUT / '404.html').write_text(page(meta, body, '/404.html'), encoding='utf-8')

    sm = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    sm += [f'  <url><loc>{SITE}{u}</loc><lastmod>{mod}</lastmod><priority>{p}</priority></url>' for u, p, mod, _ in urls]
    sm.append('</urlset>')
    (OUT / 'sitemap.xml').write_text('\n'.join(sm) + '\n', encoding='utf-8')
    (OUT / 'llms.txt').write_text(llms_txt(urls), encoding='utf-8')
    print(f'Zgrajeno: {len(urls)} strani v {OUT}')


if __name__ == '__main__':
    main()
