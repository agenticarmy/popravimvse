"""Podmnožica pisave Archivo za popravimvse.si.

Iz celotnih datotek v fonts-src/ naredi static/fonts/archivo-sl-lat.woff2 in archivo-sl-ext.woff2:
samo znaki, ki so na straneh (public/) in v static/js, plus varnostni nabor (čćđšž, äöü, €, „“ – …),
osi wght 400–850 in wdth 100–125 (toliko uporablja site.css).
Zaženi po python3 build.py, če se pojavijo novi znaki (build.py jih izpiše kot opozorilo), nato build znova.
Potrebuje: pip install fonttools brotli
"""
import glob, html, os, re
from fontTools.ttLib import TTFont
from fontTools import subset
from fontTools.varLib import instancer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SAFETY = 'čćđšžČĆĐŠŽäöüÄÖÜßéèêáàâíóúñ€„“”‚‘’»«–—…•·×÷±°²³½¼¾'


def site_chars():
    chars = set(SAFETY) | {chr(c) for c in range(0x20, 0x7f)}
    for f in glob.glob(ROOT + '/public/**/*.html', recursive=True):
        t = re.sub(r'<script.*?</script>|<style.*?</style>', '', open(f, encoding='utf-8').read(), flags=re.S)
        chars |= set(html.unescape(re.sub(r'<[^>]+>', ' ', t)))
    for f in glob.glob(ROOT + '/static/js/*.js'):
        chars |= set(open(f, encoding='utf-8').read())
    return {c for c in chars if ord(c) >= 0x20}


def main():
    used = site_chars()
    for src, out in (('archivo-latin-standard-normal', 'archivo-sl-lat'), ('archivo-latin-ext-standard-normal', 'archivo-sl-ext')):
        f = instancer.instantiateVariableFont(TTFont(f'{ROOT}/fonts-src/{src}.woff2'), {'wght': (400, 850), 'wdth': (100, 125)})
        cmap = set(f.getBestCmap())
        opt = subset.Options()
        opt.flavor = 'woff2'
        opt.layout_features = ['kern', 'liga', 'calt', 'ccmp', 'locl', 'mark', 'mkmk']
        opt.name_IDs = ['*']
        sub = subset.Subsetter(opt)
        sub.populate(unicodes=[ord(c) for c in used if ord(c) in cmap])
        sub.subset(f)
        f.flavor = 'woff2'
        path = f'{ROOT}/static/fonts/{out}.woff2'
        f.save(path)
        print(path, os.path.getsize(path), 'B')


if __name__ == '__main__':
    main()
