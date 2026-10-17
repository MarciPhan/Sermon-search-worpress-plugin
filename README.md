<div align="center">

# 📖 Sermon Search

**Vyhledávání v přepisech kázání pro WordPress** – slova, tvary slov, biblické odkazy a skok na přesný čas ve videu.

![Version](https://img.shields.io/badge/version-3.5.11-2b6cb0)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%2FMariaDB-FULLTEXT-4479a1?logo=mysql&logoColor=white)
![Language](https://img.shields.io/badge/jazyk-čeština-d7141a)

</div>

Plugin si sám stahuje titulky z vlastního YouTube kanálu, ukládá je do databáze WordPressu a nad nimi staví rychlé vyhledávání. Vznikl pro Sbor baptistů v Jablonci nad Nisou, ale funguje s libovolným kanálem s českými kázáními.

```
[kazani_search]
```

Shortcode vložíte na libovolnou stránku (alias `[biblesearch]`). Žádná externí služba, žádné AI API – vše běží na vašem hostingu.

## Obsah

- [Hlavní vlastnosti](#hlavní-vlastnosti)
- [Jak se plní archiv](#jak-se-plní-archiv)
- [Instalace](#instalace)
- [Nástroje pro import](#nástroje-pro-import)
- [Architektura](#architektura)
- [REST API](#rest-api)
- [Bezpečnost a soukromí](#bezpečnost-a-soukromí)
- [Struktura repozitáře](#struktura-repozitáře)

## Hlavní vlastnosti

### Hledání
- **Čeština bez kompromisů** – hledá bez diakritiky, přesnou frázi v uvozovkách, najde i frázi rozdělenou mezi dva 30s úseky přepisu.
- **Tvary slov** – „Bůh“ najde i *Boha, Bohu, Bože*; „odpuštění“ i *odpustil*. Jednoduchý český stemmer + vlastní skupiny synonym (*Hospodin, Jahve*).
- **Našeptávač a opravy překlepů** – návrhy slov přímo z archivu.
- **Řazení** podle relevance (počet míst v kázání, shoda v názvu) nebo podle data.
- **Odkaz na čas** – každý zásah vede na video v přesném okamžiku, kdy to zaznělo.

### Bible
- **Biblické odkazy v hledání** – `Jan 3,16`, `Ř 8,28`, `1 Kor 13`, `Žalm 23` se poznají automaticky.
- **Mřížka 66 knih** s počty kázání, kapitoly a verše; validace proti všem 1 189 kapitolám (české číslování).
- **Odkazy se berou z přepisu** („Galatským 5 22“, „v třetí kapitole ve verši 16“), z názvu videa i ze starší ruční databáze. Chytré rozlišení – „Jan Hus“ není odkaz.

### Procházení
- **Filtry nad výsledky** – druh (kázání / biblická hodina / ostatní), kazatel, playlist, rok, zákon, kniha, kapitola. Nabízí jen hodnoty, které ve výsledcích opravdu jsou.
- **Celý přepis** (`?bsw_prepis=VIDEO_ID`) – čitelný text s časy, přehrávač na kliknutí (YouTube se nenačte předem), hledání v jednom kázání.
- **Otázky víry** (`?bsw_otazka=slug`) – kurátorský seznam otázek; odpověď tvoří citace z kázání s odkazem na video. Žádné generování textu.
- **Související kázání** a slovník klíčových slov z automatického rozboru přepisů.

### SEO
Každý přepis má vlastní titulek, canonical a popis a je v sitemapě `/wp-sitemap-kazani-1.xml`, takže ho najde i Google.

## Jak se plní archiv

Tři nezávislé cesty – výpadek jedné nezastaví ostatní:

| | Cesta | Jak běží | Zdroj titulků |
|---|---|---|---|
| A | **WP-Cron** | titulky po 10 min, RSS po hodině, nová videa znovu za 20 min / 3 h / 24 h | server (přehrávač YouTube), případně Data API |
| B | **Návštěvy webu** | bez cronu, po odeslání stránky (max. 1× / 10 min) | server |
| C | **Domácí PC** | systemd timer, jiná IP | `yt-dlp`, případně **Whisper** |

Doplňkově **WebSub** (YouTube sám oznámí nové video) a **cron URL** pro plánovač hostingu. Videa bez titulků se vracejí do fronty s rostoucím odstupem (1 h → 6 h → 1 d → 3 d → 7 d → měsíčně).

Zobrazují se jen **veřejná videa** – premiéry, soukromá a smazaná se skryjí a po zveřejnění se objeví sama. Druh videa se pozná z názvu, jinak z času vysílání (středa = biblická hodina, neděle = kázání).

## Instalace

1. Stáhněte repozitář jako ZIP tak, aby kořen archivu obsahoval `biblesearch.php` (složka pluginu `biblesearch-wedos`).
2. **Pluginy → Přidat → Nahrát plugin**, aktivujte.
3. V **Kázání → Nastavení** zadejte kanál a případně Google OAuth údaje pro Data API.
4. Klikněte na **Přihlásit okamžitá oznámení (WebSub)**.
5. Volitelně přidejte u hostingu cron úlohu typu *URL* s adresou **Cron URL** z administrace.
6. Vložte `[kazani_search]` na stránku.

Požadavky: WordPress 6.2+, PHP 7.4+, MySQL/MariaDB s InnoDB FULLTEXT, rozšíření `ZipArchive`. Automatika bez cronu nejlépe běží na PHP-FPM (`fastcgi_finish_request`).

## Nástroje pro import

Složka [`tools/`](tools) obsahuje skript, který stáhne **jen titulky** (ne video) a zabalí je do ZIPů pro hromadný import:

```bash
pip install -U yt-dlp
python tools/backfill.py "https://www.youtube.com/@KANAL/videos"
# ZIPy pak nahrajte v Kázání → Rychlý hromadný backfill
```

| Přepínač | Význam |
|---|---|
| `--out DIR` | pracovní adresář a výstup (výchozí `biblesearch-export`) |
| `--part-mb N` | velikost jednoho ZIPu (výchozí 3 MB) |
| `--pack-only` | znovu zabalit už stažené titulky |

Na Windows stačí spustit `tools/backfill-windows.bat`. Import je opakovatelný – stejné video se nahradí, nevznikají duplicity.

## Architektura

```
biblesearch.php            vstupní bod, shortcody, cron, načtení stylů
includes/
├─ class-bsw-search.php      vykreslení formuláře a výsledků, filtry
├─ class-bsw-db.php          schéma, FULLTEXT index, cache revize
├─ class-bsw-stem.php        český stemmer a synonyma
├─ class-bsw-bible.php       knihy, kapitoly, verše, rozpoznání odkazů
├─ class-bsw-analysis.php    slovník kázání, související kázání, překlepy
├─ class-bsw-topics.php      otázky víry, našeptávač
├─ class-bsw-transcript.php  stránka s celým přepisem
├─ class-bsw-youtube.php     OAuth a YouTube Data API
├─ class-bsw-auto.php        cron, WebSub, REST pro automatiku
├─ class-bsw-bulk.php        hromadný import ZIP
├─ class-bsw-legacy.php      převod starší ruční databáze
├─ class-bsw-sitemap.php     sitemapa přepisů
└─ class-bsw-admin.php       administrace
assets/                    CSS a JS
tools/                     lokální stahování titulků
```

Data jsou v tabulkách `<prefix>bsw_sermons`, `bsw_segments`, `bsw_refs`, `bsw_playlists`, `bsw_queries`, `bsw_terms` a `bsw_analysis`. Rozbor přepisů běží po kázáních na pozadí a po změně algoritmu se přepočítá sám.

## REST API

Jmenný prostor `bsw/v1`. Adresy se generují přes `rest_url`, fungují i bez „hezkých“ permalinků (`?rest_route=/bsw/v1/…`).

| Cesta | Metoda | Přístup |
|---|---|---|
| `/suggest` | GET | veřejné (našeptávač) |
| `/websub` | GET, POST | ověření podpisu HMAC od YouTube |
| `/cron`, `/visit`, `/pending`, `/push`, `/playlists` | GET / POST | tajný klíč |

## Bezpečnost a soukromí

- **Statistika hledání je anonymní** – ukládá se jen text dotazu a počty, bez IP, přihlášených uživatelů a robotů.
- Import ZIP i ruční nahrávání smí jen administrátor s platným nonce; pracovní ZIP leží v `wp-content/uploads/bsw-import-staging/` chráněný `.htaccess`.
- Automatické cesty vyžadují tajný klíč, WebSub ověřuje HMAC.
- Stahování přímo ze serveru používá neveřejné rozhraní přehrávače YouTube, které se může změnit nebo být pro IP hostingu omezeno – proto existují nezávislé cesty přes domácí PC a Data API.

## Licence

Licence zatím není určena – bez ní platí výchozí autorská práva. Pokud má být plugin volně použitelný, doplňte soubor `LICENSE` (např. GPL-2.0-or-later, jako WordPress).
