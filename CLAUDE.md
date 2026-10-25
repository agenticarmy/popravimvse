# popravimvse.si – navodila za vsako spremembo

Stalno navodilo lastnika (Sebastjan Stjepanović): vsaka sprememba strani mora ostati
**Schema.org**, **SEO**, **GEO** in **agentic-search** optimizirana. Ne sprašuj znova, naredi vedno.

Postopek:
1. Uredi `content/*.html` (JSON glava: title ≤ 65 znakov, description 70–160 znakov, h1, lead, crumb) ali `build.py`.
2. Nova poslovna dejstva (cena, plačilo, publika, območje) vnesi povsod: vsebina, FAQ (`<details>` → FAQPage), JSON-LD v `build.py` (business_ld, hour_price, Service), `llms.txt` (Ključna dejstva). Brez protislovij med stranmi.
3. `python3 build.py`, nato preveri: vsi JSON-LD bloki se parsajo, dolžine meta, sitemap, 301 v `root/.htaccess` za odstranjene strani.
4. Commit + push; objava v cPanelu: Git Version Control → Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit (`.cpanel.yml` kopira `public/` v `public_html`). Preveri živo stran.

Vsebinska pravila:
- Preprost jezik, kratki citabilni stavki, konkretni primeri in številke. Ne izmišljuj dejstev.
- Brez reguliranih del (elektro, vodovod, plin, ometi, betoniranje); v primerih ne omenjaj vtičnic, luči, pip, bojlerjev.
- V referenci o vikendu ohrani omembo električnega bojlerja in preureditve ogrevanja.
- Fotografije: brez gesel/nalepk, tretjih oseb, registrskih tablic, AI slik; odstrani EXIF/GPS.

Dejstva (25. 10. 2026): 35 €/h, prevoz vključen; prva ura v celoti, nato na minuto natančno; če ne popravi, stranka ne plača nič;
plačilo samo z gotovino na mestu, račun za vsako delo; ni zavezanec za DDV; samo posamezniki in gospodinjstva (ne podjetja);
sedež Ronkova ulica 4, 2380 Slovenj Gradec (pisarna), dela iz Pernice pri Mariboru.
Čas se šteje od prihoda k stranki (stisk roke), vožnja ne; brez doplačil zvečer/vikend; material kupi stranka (Sebastjan svetuje, brez pribitka);
če kaj ni prav (npr. silikon odstopi), popravi brezplačno; termina se drži – če je preveč zaseden, raje takoj odkloni; brez predplačila.
