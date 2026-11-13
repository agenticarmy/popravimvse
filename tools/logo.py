"""Logotip »Popravim vse« (smer K, 14. 11. 2026): hiša s kljukico.

Ustvari:
  static/img/logo-znak.svg        znak (hiša + kljukica) za svetlo podlago
  static/img/logo.svg             vodoravni logotip (znak + napis, besedilo v krivuljah)
  static/img/logo-google.png      720×720, zelena podlaga (Google profil, YouTube, schema.org logo)
  static/img/favicon.svg, icon-48/192/512.png, apple-touch-icon.png, root/favicon.ico
  _logo/ (izven repozitorija, pot kot argument): PNG/PDF za tisk in profile

Zaženi: python3 tools/logo.py [izhodna_mapa_za_tisk]
"""
import io
import sys
from pathlib import Path

import cairosvg
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
IMG = ROOT / 'static' / 'img'

INK, YELLOW, GREEN, WHITE, GLAZE, SOFT = '#16232B', '#F2C21B', '#1D6A58', '#FFFFFF', '#F5F7F6', '#4B5A62'

HOUSE = 'M8 29 L32 8 L56 29 V56 H8 Z'
CHECK = 'M19 38 L28 47 L51 19'


def mark(house=INK, under=INK, check=YELLOW, hw=5, uw=11, cw=5.5):
    """Znak v koordinatah 0–64: obris hiše, kljukica z obrobo, ki sega čez streho."""
    return (f'<path d="{HOUSE}" fill="none" stroke="{house}" stroke-width="{hw}" stroke-linejoin="round"/>'
            f'<path d="{CHECK}" fill="none" stroke="{under}" stroke-width="{uw}" stroke-linecap="round" stroke-linejoin="round"/>'
            f'<path d="{CHECK}" fill="none" stroke="{check}" stroke-width="{cw}" stroke-linecap="round" stroke-linejoin="round"/>')


def svg(w, h, body, vb=None):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="{vb or f"0 0 {w} {h}"}">'
            f'{body}</svg>')


def icon(size, bg=GREEN, radius=0.22, pad=0.14):
    """Kvadratna ikona: zelena podlaga, bela hiša, rumena kljukica (obroba v barvi podlage)."""
    s = size * (1 - 2 * pad) / 64
    off = size * pad
    r = size * radius
    inner = mark(house=WHITE, under=bg, check=YELLOW, hw=5.5, uw=12, cw=6.5)
    return svg(size, size, f'<rect width="{size}" height="{size}" rx="{r}" fill="{bg}"/>'
                           f'<g transform="translate({off} {off}) scale({s})">{inner}</g>')


# --- besedilo v krivuljah (Archivo, spremenljiva pisava iz fonts-src) ---
_fonts = {}


def font(wght, wdth):
    key = (wght, wdth)
    if key not in _fonts:
        fs = []
        for name in ('archivo-latin-standard-normal.woff2', 'archivo-latin-ext-standard-normal.woff2'):
            f = TTFont(ROOT / 'fonts-src' / name)
            fs.append(instantiateVariableFont(f, {'wght': wght, 'wdth': wdth}))
        _fonts[key] = fs
    return _fonts[key]


def text_path(txt, size, wght, wdth, x, y, tracking=0.0):
    """Vrne (svg path d, širina) za besedilo; y je osnovna črta."""
    fs = font(wght, wdth)
    scale = size / fs[0]['head'].unitsPerEm
    d, cx = [], x
    for ch in txt:
        f = next((f for f in fs if ord(ch) in f.getBestCmap()), None)
        if f is None:
            raise SystemExit(f'Pisava nima znaka {ch!r}')
        gname = f.getBestCmap()[ord(ch)]
        gs = f.getGlyphSet()
        pen = SVGPathPen(gs)
        gs[gname].draw(TransformPen(pen, (scale, 0, 0, -scale, cx, y)))
        d.append(pen.getCommands())
        cx += gs[gname].width * scale + tracking * size
    return ' '.join(d), cx - x - tracking * size


def lockup(dark=False):
    """Vodoravni logotip: znak 112 px + »Popravim vse« + vrstica »HIŠNI MOJSTER · POPRAVILA NA DOMU«."""
    m = 112
    gap = 26
    tx = m + gap
    d1, w1 = text_path('Popravim vse', 54, 800, 112, tx, 64)
    d2, w2 = text_path('HIŠNI MOJSTER · POPRAVILA NA DOMU', 15, 600, 100, tx + 1, 98, tracking=0.16)
    width = int(tx + max(w1, w2) + 4)
    height = 112
    house, under, txt, sub = (WHITE, INK, WHITE, '#CBD3CF') if dark else (INK, INK, INK, SOFT)
    body = (f'<g transform="scale({m / 64})">{mark(house=house, under=under)}</g>'
            f'<path d="{d1}" fill="{txt}"/><path d="{d2}" fill="{sub}"/>')
    if dark:
        body = f'<rect width="{width}" height="{height}" fill="{INK}"/>' + body
    return svg(width, height, body), width, height


def png(svg_text, path, w, h=None):
    cairosvg.svg2png(bytestring=svg_text.encode(), write_to=str(path), output_width=w, output_height=h or w)


def main():
    out = Path(sys.argv[1]) if len(sys.argv) > 1 else None

    znak = svg(64, 64, mark())
    (IMG / 'logo-znak.svg').write_text(znak, encoding='utf-8')
    lo, lw, lh = lockup()
    (IMG / 'logo.svg').write_text(lo, encoding='utf-8')

    # favicon: zelena ikona, za majhne velikosti debelejše poteze
    fav = icon(64, radius=0.2, pad=0.1).replace('stroke-width="5.5"', 'stroke-width="7"').replace(
        'stroke-width="12"', 'stroke-width="14"').replace('stroke-width="6.5"', 'stroke-width="8"')
    (IMG / 'favicon.svg').write_text(fav, encoding='utf-8')
    for n in (48, 192, 512):
        png(fav if n == 48 else icon(n), IMG / f'icon-{n}.png', n)
    png(icon(180, radius=0), IMG / 'apple-touch-icon.png', 180)
    png(icon(720, radius=0, pad=0.16), IMG / 'logo-google.png', 720)

    ico_imgs = []
    for n in (16, 32, 48):
        b = io.BytesIO()
        cairosvg.svg2png(bytestring=fav.encode(), write_to=b, output_width=n, output_height=n)
        ico_imgs.append(Image.open(io.BytesIO(b.getvalue())).convert('RGBA'))
    ico_imgs[-1].save(ROOT / 'root' / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)], append_images=ico_imgs[:-1])

    if out:
        out.mkdir(parents=True, exist_ok=True)
        (out / 'logo.svg').write_text(lo, encoding='utf-8')
        (out / 'logo-znak.svg').write_text(znak, encoding='utf-8')
        dlo, _, _ = lockup(dark=True)
        (out / 'logo-temna-podlaga.svg').write_text(dlo, encoding='utf-8')
        png(lo, out / 'logo-2400.png', 2400, int(2400 * lh / lw))
        png(dlo, out / 'logo-temna-podlaga-2400.png', 2400, int(2400 * lh / lw))
        png(znak, out / 'logo-znak-1024.png', 1024)
        png(icon(800, radius=0, pad=0.16), out / 'profilna-800-google-youtube.png', 800)
        cairosvg.svg2pdf(bytestring=lo.encode(), write_to=str(out / 'logo-za-tisk.pdf'))
        cairosvg.svg2pdf(bytestring=znak.encode(), write_to=str(out / 'logo-znak-za-tisk.pdf'))
    print('logo:', lw, '×', lh)


if __name__ == '__main__':
    main()
