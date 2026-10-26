/* popravimvse.si – kalkulatorji materiala na seznamih »Kaj kupiti«.
 * Računajo natanko po pravilih iz razdelka »Kako izračunati, koliko kupiti« na isti strani.
 * Vsebnik: <div class="calc" data-calc="slug"></div>
 */
(function () {
  'use strict';

  function num(v) { var n = parseFloat(String(v).replace(',', '.').replace(/\s/g, '')); return isFinite(n) ? n : NaN; }
  function fmt(n, d) { return (Math.round(n * Math.pow(10, d || 0)) / Math.pow(10, d || 0)).toString().replace('.', ','); }
  function ceil(n) { return Math.ceil(n - 1e-9); }
  function kos(n, ena, dve, tri, vec) { return n + ' ' + (n === 1 ? ena : n === 2 ? dve : (n === 3 || n === 4) ? tri : vec); }

  /* opis polj: [id, oznaka, enota, privzeto, namig] ali {radio:[…]} ali {check:…} */
  var C = {
    'obnova-silikona-v-kopalnici': {
      naslov: 'Koliko kartuš silikona potrebujem?',
      polja: [
        ['dolzina', 'Skupna dolžina vseh stikov', 'm', '6', 'Seštejte vse stike, npr. kad ob dveh stenah in kote ob tušu.'],
        { radio: 'sirina', oznaka: 'Širina stika', moznosti: [['5', '5 mm (običajno)'], ['10', '10 mm (širok stik)']], privzeto: '5' },
        ['ml', 'Vsebina kartuše', 'ml', '300', 'Piše na kartuši, običajno 300 ali 310 ml.']
      ],
      racun: function (v) {
        var w = num(v.sirina), cap = num(v.ml) / (w * w);
        var n = ceil(num(v.dolzina) / cap);
        return { glavno: kos(n, 'kartuša', 'kartuši', 'kartuše', 'kartuš') + ' silikona',
          vrstice: ['Ena kartuša zadošča za približno ' + fmt(cap, 1) + ' m stika ' + w + ' × ' + w + ' mm.'] };
      }
    },
    'polaganje-laminata': {
      naslov: 'Koliko paketov laminata in koliko letev?',
      polja: [
        ['dolzina', 'Dolžina sobe', 'm', '4'], ['sirina', 'Širina sobe', 'm', '3,5'],
        { radio: 'rez', oznaka: 'Rezerva za razrez', moznosti: [['5', '5 %'], ['10', '10 %'], ['15', '15 % (diagonalno)']], privzeto: '10' },
        ['paket', 'm² v enem paketu', 'm²', '2,2', 'Piše na paketu.'],
        ['vrata', 'Širina vrat (skupaj vseh)', 'm', '0,8', 'Ob vratih letev ni.']
      ],
      racun: function (v) {
        var a = num(v.dolzina) * num(v.sirina), z = a * (1 + num(v.rez) / 100), p = ceil(z / num(v.paket));
        var letve = ceil((2 * (num(v.dolzina) + num(v.sirina)) - num(v.vrata)) * 1.1);
        return { glavno: kos(p, 'paket', 'paketa', 'paketi', 'paketov') + ' laminata',
          vrstice: ['Površina sobe ' + fmt(a, 1) + ' m², z rezervo ' + fmt(z, 1) + ' m² (' + kos(p, 'paket', 'paketa', 'paketi', 'paketov') + ' = ' + fmt(p * num(v.paket), 1) + ' m²).',
            'Podloga: ' + fmt(ceil(a), 0) + ' m² (enako kot površina sobe).',
            'Talne letve: približno ' + letve + ' m (obseg brez vrat + 10 %).'] };
      }
    },
    'obnova-fug': {
      naslov: 'Koliko fugirne mase potrebujem?',
      polja: [
        ['povrsina', 'Površina s ploščicami', 'm²', '5'],
        ['poraba', 'Poraba z embalaže', 'kg/m²', '0,6', 'Na embalaži poiščite vrstico za vaše ploščice in širino fuge. Primer: 30 × 30 cm in fuga 5 mm ≈ 0,6 kg/m².'],
        ['vreca', 'Velikost vrečke', 'kg', '5']
      ],
      racun: function (v) {
        var kg = num(v.povrsina) * num(v.poraba), n = ceil(kg / num(v.vreca));
        return { glavno: kos(n, 'vrečka', 'vrečki', 'vrečke', 'vrečk') + ' po ' + fmt(num(v.vreca), 1) + ' kg',
          vrstice: ['Poraba: ' + fmt(num(v.povrsina), 1) + ' m² × ' + fmt(num(v.poraba), 2) + ' kg/m² = ' + fmt(kg, 1) + ' kg.',
            'Pri obnovi, ko staro fugo odstranim le delno, gre manj.'] };
      }
    },
    'pleskanje-sobe': {
      naslov: 'Koliko barve potrebujem?',
      polja: [
        ['dolzina', 'Dolžina sobe', 'm', '4'], ['sirina', 'Širina sobe', 'm', '3'], ['visina', 'Višina sten', 'm', '2,6'],
        ['odbij', 'Okna in vrata skupaj', 'm²', '3,5', 'Za vsako okno in vrata: širina × višina.'],
        { check: 'strop', oznaka: 'Pleskam tudi strop', privzeto: true },
        ['izd', 'Izdatnost z embalaže', 'm²/l', '5', 'Pogosto okoli 5 m² na liter za dva sloja.']
      ],
      racun: function (v) {
        var st = 2 * (num(v.dolzina) + num(v.sirina)) * num(v.visina) - num(v.odbij);
        var sp = v.strop ? num(v.dolzina) * num(v.sirina) : 0, a = st + sp, l = ceil(a / num(v.izd));
        return { glavno: 'približno ' + l + ' l barve',
          vrstice: ['Stene ' + fmt(st, 1) + ' m²' + (v.strop ? ', strop ' + fmt(sp, 1) + ' m²' : '') + ', skupaj ' + fmt(a, 1) + ' m².',
            fmt(a, 1) + ' m² ÷ ' + fmt(num(v.izd), 1) + ' m²/l = ' + fmt(a / num(v.izd), 1) + ' l. Kupite vedro, ki ima vsaj toliko.'] };
      }
    },
    'polaganje-ploscic': {
      naslov: 'Koliko ploščic in lepila potrebujem?',
      polja: [
        ['tla', 'Površina tal', 'm²', '5'], ['stene', 'Površina sten', 'm²', '18'],
        { radio: 'rez', oznaka: 'Rezerva', moznosti: [['10', '10 %'], ['15', '15 % (velike ploščice ali diagonalno)']], privzeto: '10' },
        ['skatla', 'm² v eni škatli', 'm²', '1,44', 'Piše na škatli; vpišite svojo vrednost.'],
        ['lepilo', 'Poraba lepila', 'kg/m²', '5', 'Približno 3–6 kg/m², večje ploščice porabijo več.'],
        ['vreca', 'Vreča lepila', 'kg', '25']
      ],
      racun: function (v) {
        var a = num(v.tla) + num(v.stene), z = a * (1 + num(v.rez) / 100), s = ceil(z / num(v.skatla));
        var kg = a * num(v.lepilo), vr = ceil(kg / num(v.vreca));
        return { glavno: kos(s, 'škatla', 'škatli', 'škatle', 'škatel') + ' ploščic in ' + kos(vr, 'vreča', 'vreči', 'vreče', 'vreč') + ' lepila',
          vrstice: ['Površina ' + fmt(a, 1) + ' m², z rezervo ' + fmt(z, 1) + ' m² (' + kos(s, 'škatla', 'škatli', 'škatle', 'škatel') + ' = ' + fmt(s * num(v.skatla), 1) + ' m²).',
            'Lepilo: ' + fmt(a, 1) + ' m² × ' + fmt(num(v.lepilo), 1) + ' kg/m² = ' + fmt(kg, 0) + ' kg.',
            'Fugirno maso izračunate po tabeli na embalaži.'] };
      }
    },
    'menjava-cilindra-kljucavnice': {
      naslov: 'Katero mero cilindra kupiti?',
      polja: [
        ['a', 'Mera A: od vijaka do zunanjega roba okovja', 'mm', '32'],
        ['b', 'Mera B: od vijaka do notranjega roba okovja', 'mm', '37']
      ],
      racun: function (v) {
        var a = num(v.a), b = num(v.b), A = ceil(a / 5) * 5, B = ceil(b / 5) * 5, sA = A - a, out = [];
        out.push('Zunaj štrli približno ' + fmt(sA, 0) + ' mm' + (sA <= 3 ? ', kar je še v redu.' : '.'));
        if (sA > 3) out.push('To je več kot približno 3 mm. Pošljite mi fotografijo vrat z metrom ob cilindru, pa vam povem, katerega kupiti.');
        return { glavno: 'cilinder ' + A + '/' + B + ' mm', vrstice: out };
      }
    },
    'montaza-garnisne': {
      naslov: 'Kako dolgo garnišno kupiti?',
      polja: [['okno', 'Širina okna', 'cm', '120']],
      racun: function (v) {
        var w = num(v.okno), od = w + 30, d = w + 40;
        return { glavno: 'garnišna dolga ' + od + ' do ' + d + ' cm',
          vrstice: ['Širina okna in 15 do 20 cm na vsako stran.',
            'Obročki ali drsniki: približno ' + ceil(od / 100 * 8) + ' do ' + ceil(d / 100 * 10) + ' (8–10 na meter zavese).',
            'Nosilci: najmanj 2, pri dolgih 3 ali več.'] };
      }
    }
  };

  function init(box) {
  var c = C[box.getAttribute('data-calc')];
    if (!c) return;
    var id = 'calc-' + box.getAttribute('data-calc');
    var h = '<h3 class="calc-h">' + c.naslov + '</h3><div class="calc-f">';
    c.polja.forEach(function (p) {
      if (Array.isArray(p)) {
        h += '<div class="calc-row"><label for="' + id + '-' + p[0] + '">' + p[1] + '</label>'
          + '<div class="calc-in"><input id="' + id + '-' + p[0] + '" name="' + p[0] + '" type="text" inputmode="decimal" autocomplete="off" value="' + p[3] + '"'
          + (p[4] ? ' aria-describedby="' + id + '-' + p[0] + '-n"' : '') + '><span>' + p[2] + '</span></div>'
          + (p[4] ? '<small id="' + id + '-' + p[0] + '-n">' + p[4] + '</small>' : '') + '</div>';
      } else if (p.radio) {
        h += '<fieldset class="calc-row calc-radio"><legend>' + p.oznaka + '</legend><div>';
        p.moznosti.forEach(function (m) {
          h += '<label><input type="radio" name="' + p.radio + '" value="' + m[0] + '"' + (m[0] === p.privzeto ? ' checked' : '') + '><span>' + m[1] + '</span></label>';
        });
        h += '</div></fieldset>';
      } else if (p.check) {
        h += '<label class="calc-row calc-check"><input type="checkbox" name="' + p.check + '"' + (p.privzeto ? ' checked' : '') + '> ' + p.oznaka + '</label>';
      }
    });
    h += '</div><div class="calc-out" aria-live="polite"><p class="calc-res"></p><ul></ul>'
      + '<div class="calc-act"><button type="button" class="btn-plain calc-copy">Kopiraj rezultat</button><a class="btn-plain calc-sms" href="#">Pošlji si po SMS</a></div></div>';
    box.innerHTML = h;

    var res = box.querySelector('.calc-res'), ul = box.querySelector('.calc-out ul'), sms = box.querySelector('.calc-sms'), copy = box.querySelector('.calc-copy');
    var last = '';
    function vals() {
      var v = {};
      c.polja.forEach(function (p) {
        if (Array.isArray(p)) v[p[0]] = box.querySelector('[name="' + p[0] + '"]').value;
        else if (p.radio) v[p.radio] = (box.querySelector('[name="' + p.radio + '"]:checked') || {}).value;
        else if (p.check) v[p.check] = box.querySelector('[name="' + p.check + '"]').checked;
      });
      return v;
    }
    function update() {
      var v = vals(), bad = [];
      c.polja.forEach(function (p) {
        if (!Array.isArray(p)) return;
        var el = box.querySelector('[name="' + p[0] + '"]'), n = num(el.value), ok = isFinite(n) && n >= 0 && (n > 0 || p[0] === 'odbij');
        el.setAttribute('aria-invalid', ok ? 'false' : 'true');
        if (!ok) bad.push(p[1]);
      });
      if (bad.length) {
        res.textContent = 'Vpišite število pri: ' + bad.join(', ') + '.';
        ul.innerHTML = ''; box.classList.add('calc-bad'); last = ''; return;
      }
      var r = c.racun(v);
      box.classList.remove('calc-bad');
      res.innerHTML = 'Kupite: <b></b>';
      res.querySelector('b').textContent = r.glavno;
      ul.innerHTML = '';
      r.vrstice.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
      last = c.naslov + '\nKupite: ' + r.glavno + '\n' + r.vrstice.join('\n') + '\n' + location.href.split('#')[0];
      sms.href = 'sms:?&body=' + encodeURIComponent(last);
    }
    box.addEventListener('input', update);
    box.addEventListener('change', update);
    copy.addEventListener('click', function () {
      if (!last || !navigator.clipboard) return;
      navigator.clipboard.writeText(last).then(function () { copy.textContent = 'Kopirano ✓'; setTimeout(function () { copy.textContent = 'Kopiraj rezultat'; }, 2000); }, function () {});
    });
    update();
  }
  Array.prototype.forEach.call(document.querySelectorAll('.calc[data-calc]'), init);
})();
