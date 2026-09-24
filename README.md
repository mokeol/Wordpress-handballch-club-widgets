# Club-Widgets für handball.ch

WordPress-Plugin für Handballvereine: Ranglisten, Spielpläne, Resultate, Countdown zum nächsten Spiel und
ICS-Kalender-Export auf Basis der `clubapi.handball.ch`-API des SHV. Alles ist über ein Adminpanel einstellbar
(Teams, API-Zugang, sichtbare Felder, Texte, Farben, Cache-Dauer, eigenes CSS).

**Inoffiziell:** Dieses Plugin ist nicht mit dem Schweizerischen Handballverband (SHV) verbunden. Für die API braucht
jeder Verein eigene Zugangsdaten (Club-ID und Passwort) vom SHV.

- **Version:** siehe [CHANGELOG.md](CHANGELOG.md)
- **Benötigt:** WordPress 6.0+, PHP 7.4+
- **Ausgabe:** 8 Shortcodes und 5 Gutenberg-Blöcke (kein Build-Schritt)
- **Zusätzlich:** öffentliche REST-Endpunkte, `/spielplan.ics`, schema.org-Strukturdaten (JSON-LD)
- **Lizenz:** GPL-2.0-or-later

---

## Inhalt

1. [Installation & Ersteinrichtung](#installation--ersteinrichtung)
2. [Shortcodes](#shortcodes)
3. [Gutenberg-Blöcke](#gutenberg-blöcke)
4. [ICS-Kalender](#ics-kalender)
5. [REST-Endpunkte](#rest-endpunkte)
6. [Adminpanel](#adminpanel)
7. [Farben](#farben)
8. [Caching](#caching)
9. [Besonderheiten der Ausgabe](#besonderheiten-der-ausgabe)
10. [Filter & Hooks](#filter--hooks)
11. [Fehlersuche](#fehlersuche)
12. [Projektstruktur](#projektstruktur)
13. [Entwicklung & Release](#entwicklung--release)

---

## Installation & Ersteinrichtung

1. Ordner `handballch-api` nach `wp-content/plugins/` kopieren (oder das ZIP hochladen) und aktivieren.
   Bei der Aktivierung werden die Permalinks neu geschrieben, damit `/spielplan.ics` sofort funktioniert.
   Der Ordner muss **vollständig** sein: fehlt eine der Dateien, die `handballch-api.php` per `require_once` lädt,
   bricht WordPress mit einem Fatal Error ab.
2. **Einstellungen → handball.ch Club-Widgets → Allgemein & API:**
   - **Club-ID** eintragen.
   - **API-Passwort** eintragen: das Passwort (Secret), das der SHV zusammen mit der Club-ID ausstellt. Das Plugin
     setzt daraus selbst den Base64-Token für die Basic-Auth zusammen (`Authorization: Basic <Base64 von ClubID:Secret>`).
     Das gespeicherte Passwort wird im Formular nicht angezeigt; beim späteren Speichern lässt man das Feld leer,
     um es zu behalten.
   - **Teams** zuordnen, eine Zeile pro Team im Format `slug=handball.ch-Team-ID` (z. B. `team1=12345`).
     Der Slug ist der Wert, den man später bei `team="…"` angibt. Die Team-ID steht in der URL des Teams auf handball.ch.
3. Im Reiter **Diagnose** auf „Alle Team-IDs jetzt prüfen“ klicken. Jede ID sollte grün („gültig“) sein.
4. Shortcodes oder Blöcke auf den Seiten einfügen.

**Saisonwechsel:** Die Team-IDs ändern sich pro Saison. Nach dem Wechsel im Reiter „Diagnose“ alle IDs prüfen und
die neuen unter „Allgemein & API“ eintragen.

---

## Shortcodes

`team` ist immer der **Slug** aus den Einstellungen (nicht die numerische ID).

| Shortcode | Attribute | Ausgabe |
|---|---|---|
| `[hbch_ranking team="team1"]` | `team` | Kompakte Rangliste (Tabelle `#hbch-ranking-mini`) |
| `[hbch_team_ranking team="team1"]` | `team` | Detaillierte Rangliste mit Logos, S/U/N, Toren und Auf-/Abstiegszonen (`#hbch-ranking-team`) |
| `[hbch_team_next_games team="team1"]` | `team` | Noch nicht gespielte Spiele eines Teams (`#hbch-team-games-next`) |
| `[hbch_team_last_games team="team1"]` | `team` | Gespielte Spiele eines Teams mit Resultat (`#hbch-team-games-last`) |
| `[hbch_home_next_games limit="3" exclude="U13"]` | `limit`, `exclude`, `layout` | Kommende Spiele über alle Teams des Vereins |
| `[hbch_home_last_games limit="3" exclude="U13"]` | `limit`, `exclude`, `layout` | Letzte Resultate über alle Teams des Vereins |
| `[hbch_next_game team="team1"]` | `team` | Nächstes Spiel mit laufendem Countdown (Tage/Stunden/Minuten) |
| `[hbch_ics team="team1" label="…"]` | `team`, `label` | „Kalender abonnieren“-Button mit Dropdown |

- `limit`: Anzahl Spiele. Ohne Angabe gilt die Standard-Anzahl aus dem Reiter „Vereins-Spielplan“ (Default 3, maximal 50).
- `exclude`: Freitext. Spiele, bei denen Teamname, Liga oder Gruppentext diesen Text enthalten, werden ausgeblendet.
- `layout`: `cards` (Default, Kartenlook für die Startseite) oder `table` (Tabellenlook wie beim Team-Spielplan, mit Liga-Spalte).
- `label`: überschreibt den Button-Text; leer = Text aus dem Reiter „ICS-Export“.
- `team` beim ICS-Shortcode leer = ganzer Verein.

Shortcodes verwenden immer die **globalen Farben** (Reiter „Farben“). Farben pro Einsatzort gibt es nur bei den Blöcken.

---

## Gutenberg-Blöcke

Die Shortcodes gibt es auch als Blöcke in der Kategorie **handball.ch Club-Widgets**. Fünf Blöcke bündeln die acht
Shortcodes; wo ein Block mehrere Shortcodes abdeckt, wählt man per Checkbox in der Seitenleiste. Die Blöcke sind
dynamisch (Server-Side-Render, kein `save()`), im Editor erscheint die echte Ausgabe als Live-Vorschau. Team, Anzahl,
Ausschluss-Text und Beschriftung stellt man in der Seitenleiste ein.

| Block | Anzeigename | Entspricht |
|---|---|---|
| `handballch/ranking` | Rangliste | `[hbch_ranking]`, mit Checkbox „Detailliert“ `[hbch_team_ranking]` (optional mit Auf-/Abstiegszonen) |
| `handballch/team-games` | Team – Spielplan / Resultate | `[hbch_team_next_games]` und/oder `[hbch_team_last_games]` (Checkboxen „Nächste Spiele“ / „Resultate“) |
| `handballch/next-game` | Countdown (nächstes Spiel) | `[hbch_next_game]` |
| `handballch/home-games` | Verein – Spielplan / Resultate | `[hbch_home_next_games]` und/oder `[hbch_home_last_games]`, Layout „Karten“ oder „Tabelle“ |
| `handballch/ics-subscribe` | Kalender | `[hbch_ics]` |

**Farben pro Block:** Jeder Block hat in der Seitenleiste ein Panel „Farben“. Dort lassen sich die globalen Farben
nur für diesen einen Block überschreiben (z. B. eine andere Akzentfarbe für einen Countdown). Nicht gesetzte Farben
übernehmen den globalen Wert. Welche Farben ein Block anbietet, hängt von seinem Inhalt ab.

Der Editor-Code liegt bewusst ohne Bundler in `assets/js/blocks-editor.js`. Sind noch keine Teams konfiguriert,
zeigt der Block einen Hinweis mit Link zu den Einstellungen.

---

## ICS-Kalender

- Ganzer Verein: `https://deine-domain.ch/spielplan.ics`
- Einzelnes Team: `https://deine-domain.ch/spielplan.ics?team=team1` (ein unbekannter Slug liefert eine 404-Seite)

Der Dropdown-Button bietet: direkt abonnieren (`webcal://`, iPhone/Mac/Outlook), Google Kalender,
Kalenderlink kopieren (Android), Datei herunterladen und – wo vom Browser unterstützt – Link teilen.

Im Kalendereintrag lässt sich einstellen, ob Runde, Spielart und Halladresse in der Beschreibung stehen.
Kalendername, Button-Text und die angenommene Spieldauer (für die Endzeit) sind im Reiter „ICS-Export“ einstellbar.
Die Zeiten stehen in UTC (`…Z`) im Kalender und werden von den Kalender-Apps in die Ortszeit umgerechnet.
Forfait-Spiele werden nicht exportiert. Die Antwort trägt einen `Cache-Control`-Header passend zur Cache-Dauer
(mindestens 5 Minuten).

---

## REST-Endpunkte

Öffentlich, nur lesend, Basis `/wp-json/handballch/v1/`:

| Endpunkt | Inhalt |
|---|---|
| `GET /next-games` | Künftige Spiele des Vereins, aufsteigend sortiert |
| `GET /last-games` | Bereits gespielte Spiele des Vereins, neueste zuerst |

| Parameter | Bedeutung |
|---|---|
| `limit` | Anzahl (Default aus den Einstellungen, 1–50) |
| `exclude` | Freitext-Filter wie beim Shortcode |
| `include_live` | Nur `next-games`, Boolean: ein gerade laufendes Spiel bleibt in der Liste |

Die Quelle ist immer die Spielliste des eigenen Vereins; eine frei wählbare Quell-URL gibt es nicht.
Forfait-Spiele werden nicht ausgeliefert. Die Antworten haben `Cache-Control: public, max-age=…`
(Cache-Dauer der Vereins-Spielliste, mindestens 60 Sekunden).

---

## Adminpanel

**Einstellungen → handball.ch Club-Widgets.** Alle Werte stehen in **einer** Options-Zeile (`hbch_settings`);
im Code liest ausschliesslich `hbch_get_setting()` diese Option.

| Reiter | Inhalt |
|---|---|
| **Allgemein & API** | Club-ID, API-Passwort, Team-Zuordnung, Team-ID-Prüfung, Vereins-Cache-Dauer, Logo-Cache-Dauer, Strukturdaten (SEO) |
| **Rangliste** | Live-Vorschau, Spalten (pro Variante kompakt/detailliert an- und abschaltbar, gemeinsame Beschriftung), eigene Mannschaft hervorheben, Texte, Cache, CSS |
| **Team-Spielplan** | Live-Vorschau, Felder pro Shortcode (nächste/letzte) an- und abschaltbar, „Auf Mobile anzeigen“, LIVE-Badge, Cache, CSS |
| **Vereins-Spielplan** | Live-Vorschau, Felder der Startseiten-Widgets, Standard-Anzahl, CSS |
| **Countdown** | Live-Vorschau, Texte (Titel, „vs“, Tage/Stunden/Minuten), Felder, Cache, CSS |
| **ICS-Export** | Live-Vorschau, Kalendername, Button-Text, Inhalt der Beschreibung, Cache, Spieldauer, CSS |
| **Farben** | Alle Farben des Plugins (Grundfarben und Auf-/Abstiegszonen), Live-Vorschau, Zurücksetzen, Referenz der CSS-Variablen |
| **Diagnose** | Cache leeren, Rohdaten eines Teams live abrufen (ohne Cache), alle Team-IDs prüfen |
| **Anleitung** | Zeigt diese `README.md` im Adminpanel |
| **Changelog** | Zeigt `CHANGELOG.md` |

Jeder Reiter mit CSS zeigt das mitgelieferte Standard-CSS als Referenz (aus den `HBCH-TAB:…`-Markern in
`public.css`) und ein Feld „Eigenes CSS“, das **nach** dem Standard-CSS geladen wird.

---

## Farben

`public.css` enthält keine festen Farbwerte. Alle Farben sind CSS-Variablen, deren Werte im Reiter **Farben** stehen.
Das Plugin gibt sie als `:root`-Regel direkt nach `public.css` aus.

| Variable | Verwendet für |
|---|---|
| `--hbch-color-accent` | Eigene Mannschaft (Hervorhebung), Liga- und Hallentext, Countdown-Zahlen und -Titel |
| `--hbch-color-inverse` | Schrift auf Farbflächen (Hervorhebung, Rang-Badge, Ergebnis-Badge, Badges) |
| `--hbch-color-muted` | Grau: Kopfzeile der Rangliste, Countdown-Labels, Rang-Badge ohne Zone, Forfait-Badge |
| `--hbch-color-dark` | Ergebnis-Badge in der mobilen Ansicht, Schatten des Kalender-Menüs |
| `--hbch-color-live` | LIVE-Badge (Hover automatisch dunkler) |
| `--hbch-color-line` | Trennlinien und Rahmen |
| `--hbch-color-surface-alt` | Zebra-Zeilen der Team-Spielpläne, Hover im Kalender-Menü |
| `--hbch-color-surface` | Hintergrund des Kalender-Menüs |
| `--hbch-zone-promotion-direct` | Rang-Badge: direkter Aufstieg |
| `--hbch-zone-promotion-candidate` | Rang-Badge: Aufstiegskandidat |
| `--hbch-zone-relegation-candidate` | Rang-Badge: Abstiegskandidat |
| `--hbch-zone-relegation-direct` | Rang-Badge: direkter Abstieg |

**Überschreiben:**

- **Pro Block:** Seitenleiste des Blocks → Panel „Farben“.
- **Pro Seite** (für Shortcodes, oder wenn der Block nicht reicht), im Feld „Eigenes CSS“ oder im Theme:
  ```css
  .page-id-123 { --hbch-color-accent: #004ABD; }
  ```
- **Dark Mode:** im Theme die Variablen unter der Dark-Mode-Regel neu setzen.

---

## Caching

Alle API-Antworten liegen als Transients mit dem Präfix `hbch_` in der Datenbank. Der Reiter „Diagnose → Cache leeren“
löscht sie sofort.

| Was | Default | Einstellung |
|---|---|---|
| Rangliste / Gruppendaten | 20 Minuten | Reiter Rangliste |
| Team-Spielplan | 20 Minuten | Reiter Team-Spielplan |
| Vereins-Spielliste (Startseite, Countdown, ICS-Quelle, REST) | 20 Minuten | Reiter Allgemein & API |
| Nächstes Spiel (Countdown) | 20 Minuten | Reiter Countdown |
| ICS-Kalender | 6 Stunden | Reiter ICS-Export |
| Team-/Vereinslogos (lokale Kopie) | 30 Tage | Reiter Allgemein & API |

Jeder Cache-Schlüssel enthält einen Versionszähler (hbch_cache_version). ‚Cache jetzt leeren‘ und das Speichern der Einstellungen zählen ihn hoch, auch mit Object-Cache. Pro Abruf wird eine letzte gute Kopie aufgehoben (7 Tage, Filter hbch_stale_cache_seconds); ein Fehler-Marker (60 s) verhindert blockierende Neuversuche. Das Ranglisten-HTML wird nicht separat gecacht.

**Logos:** Beim ersten Aufruf wird die Original-URL von handball.ch ausgeliefert (kein Warten), ein einmaliger
WP-Cron-Job lädt die Datei nach `uploads/hbch-logo-cache/`. Ab dem nächsten Aufruf kommt die lokale Kopie.
Gespeichert werden nur Dateien von `https://handball.ch/…`, die wirklich Bilder sind.

**Assets:** `public.css` und das Frontend-JS werden nur auf Seiten geladen, die einen Shortcode oder Block dieses
Plugins enthalten. Geprüft werden der Seiteninhalt, eingebundene wiederverwendbare Blöcke und die Widgets
(Text, Eigenes HTML, Block). Steht ein Shortcode woanders (Theme-Template, Page-Builder), das Laden per Filter
erzwingen, siehe [Filter & Hooks](#filter--hooks).

---

## Besonderheiten der Ausgabe

- **Eigene Mannschaft hervorheben:** Zeilen, deren Teamname den eingestellten Text enthält (z. B. der Vereinsname),
  werden in beiden Ranglisten in der Akzentfarbe hervorgehoben (ohne Beachtung der Gross-/Kleinschreibung). Leer = aus.
- **Spielgemeinschaften (SG):** Bei einer SG über zwei Vereine liefert handball.ch nur eine Club-ID. Ist der eigene
  Vereinstext (siehe oben) im Teamnamen enthalten, wird zusätzlich das eigene Logo daneben gezeigt (pro Widget abschaltbar).
- **Auf-/Abstiegszonen:** Das Rang-Badge der detaillierten Rangliste wird nach den Zonengrössen aus
  `/teams/{id}/group` eingefärbt (direkter Aufstieg, Kandidat, direkter Abstieg, Abstiegskandidat). Die Farben
  stehen im Reiter „Farben“.
- **LIVE-Badge:** Ersetzt Datum und Zeit durch einen Link ins Matchcenter, solange ein Spiel läuft. Die API hat
  keinen „läuft“-Status, deshalb gilt ein Spiel ab Anpfiff **90 Minuten** lang als live (per Filter anpassbar).
- **Liga-Spalte im Vereins-Spielplan (Tabellenlayout):** `[hbch_home_next_games layout="table"]` und
  `[hbch_home_last_games layout="table"]` (bzw. Block „Verein – Spielplan / Resultate“, Layout „Tabelle“) zeigen
  zusätzlich eine Liga-Spalte direkt nach Datum/Zeit, da hier Spiele mehrerer Ligen gemischt auftreten. Der
  Team-Spielplan hat diese Spalte nicht (dort immer nur eine Liga).
- **Forfait:** Forfait-Spiele fehlen in den vereinsweiten Listen, im Countdown, im ICS-Kalender und in den
  REST-Antworten. Im Team-Spielplan stehen sie mit Datum und „Forfait“-Badge statt Datum/Uhrzeit.
- **Matchcenter-Link:** Das Icon wird pro Seite einmal als SVG-`<symbol>` ausgegeben und pro Zeile referenziert.
- **Datumsformat:** Datum und Uhrzeit formatiert der Server (Europe/Zurich, deutsche Namen), ohne JavaScript.
- Nur der Countdown zählt im Browser weiter (public.js).
- **Strukturdaten (SEO):** Bei `[hbch_team_next_games]`, `[hbch_home_next_games]` und `[hbch_next_game]` wird pro Spiel
  ein schema.org-`SportsEvent` als JSON-LD ausgegeben. Spiele ohne Halle bekommen keine Strukturdaten (Pflichtfeld
  `location`). Optional mit `offers` (Eintrittspreis, Default 0 CHF = kostenlos). Alles im Reiter „Allgemein & API“
  abschaltbar.
- **title-Attribute an Logos:** SEO-Plugins hängen teils `title`-Attribute an Bilder. Das Plugin entfernt sie
  serverseitig von allen `hbch-team-logo*`-Bildern. Kommen sie trotzdem zurück, hilft nur, die Funktion im
  jeweiligen Plugin abzuschalten.

---

## Filter & Hooks

```php
// TLS-Prüfung für Anfragen an handball.ch (Default: true).
// Nur im Notfall abschalten, z. B. bei einem kaputten Zertifikat auf Seiten des SHV.
add_filter( 'hbch_api_sslverify', '__return_false' );

// Ab Anpfiff so viele Minuten gilt ein Spiel als "live" (Default: 90).
add_filter( 'hbch_live_game_duration_minutes', fn() => 100 );

// Plugin-CSS/-JS auf jeder Seite laden, auch wenn der Shortcode nicht im Seiteninhalt steht.
add_filter( 'hbch_force_assets', '__return_true' );

add_filter( 'hbch_stale_cache_seconds', fn() => 3 * DAY_IN_SECONDS );
```

Weitere Hooks:

- `hbch_download_logo` (Action, WP-Cron): lädt ein Logo in den lokalen Cache.
- `hbch_ics_clear_cache` (Action): leert den ICS-Cache; ohne Argument alle Kalender, mit Team-Slug nur diesen.

---

## Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| Fatal Error „Failed opening required …“ | Unvollständiger Upload. Alle Dateien aus der `require_once`-Liste in `handballch-api.php` müssen im Plugin-Ordner liegen. Notfalls den Plugin-Ordner umbenennen (WordPress deaktiviert das Plugin), vollständig hochladen, zurückbenennen |
| Hinweis „Bitte zuerst Club-ID und API-Passwort eintragen“ | Club-ID oder Passwort fehlt (Reiter „Allgemein & API“) |
| „Rangliste momentan nicht verfügbar“ | Passwort falsch oder leer, API nicht erreichbar oder Team-ID veraltet. Diagnose → „Alle Team-IDs prüfen“, danach „Rohdaten live abrufen“ |
| „Unbekanntes Team“ | Der Slug im Shortcode existiert nicht in den Einstellungen |
| Daten sind veraltet | Diagnose → „Cache jetzt leeren“ (oder Cache-Dauer verkürzen) |
| `/spielplan.ics` liefert 404 | Einstellungen → Permalinks → „Änderungen speichern“. Bei `?team=…` zusätzlich prüfen, ob der Slug in den Einstellungen existiert |
| Kein Styling / Countdown läuft nicht | Shortcode steht ausserhalb des Seiteninhalts, Filter `hbch_force_assets` setzen |
| Farben fehlen oder sind falsch | `includes/frontend.php` und `assets/css/public.css` müssen beide auf demselben Stand sein (die `:root`-Variablen kommen aus `hbch_get_dynamic_inline_css()`). Danach Browser- und Seiten-Cache leeren |
| Logos fehlen auf dem iPhone | Plugin aktualisieren und Cache leeren (kein natives `loading="lazy"` mehr, das sich mit JS-Lazy-Load-Plugins beisst) |
| Browser zeigt alte CSS-Version | `HBCH_VERSION` und Header-Version müssen übereinstimmen und beim Release erhöht werden |
| Reiter „Anleitung“ oder „Changelog“ zeigt nichts | Die Dateien müssen exakt `README.md` bzw. `CHANGELOG.md` heissen und im Plugin-Ordner liegen (Linux unterscheidet Gross-/Kleinschreibung) |
| Search Console meldet fehlendes `location` | Spiel hat keine Halle bei handball.ch, das Plugin gibt dafür bewusst kein JSON-LD aus |

---

## Projektstruktur

```
handballch-api/
├── handballch-api.php          Plugin-Header, Konstanten, Includes, (De-)Aktivierung
├── uninstall.php               Räumt Option, Transients und Logo-Cache beim Löschen auf
├── CHANGELOG.md                Versionshistorie (wird auch im Adminpanel angezeigt)
├── README.md
├── includes/
│   ├── settings.php            Defaults und hbch_get_setting() (einzige Stelle, die die Option liest)
│   ├── colors.php              Farbrollen, CSS-Variablen (:root), Block-Farben
│   ├── api.php                 API-Fetch, Caching, Logos, Live/Forfait, JSON-LD, Matchcenter-Helfer
│   ├── rest.php                REST-Endpunkte next-games / last-games
│   ├── shortcodes.php          Alle Shortcodes ausser dem ICS-Button
│   ├── ics.php                 ICS-Export, Rewrite-Rule, Shortcode [hbch_ics]
│   ├── frontend.php            Asset-Laden, Frontend-JS, Inline-CSS, title-Filter
│   ├── blocks.php              Server-seitige Block-Registrierung
│   └── admin-settings.php      Adminpanel (nur geladen, wenn is_admin())
└── assets/
    ├── css/public.css          Frontend-Styles (mit HBCH-TAB-Markern für die Referenzanzeige)
    ├── css/admin.css           Adminpanel und Block-Editor
    ├── js/public.js
    ├──  js/blocks-legacy.js
    └── js/blocks-editor.js     Editor-Ansicht der Blöcke (ohne Build-Schritt)
```

Deinstallation über das WordPress-Backend löscht die Plugin-Option, alle `hbch_`-Transients und den Ordner
`uploads/hbch-logo-cache/`. Reines Deaktivieren löscht nichts.

---

## Entwicklung & Release

- Namenskonvention: Funktionen `hbch_*`, CSS-Klassen `hbch-*`, Shortcodes `hbch_*`, Option `hbch_settings`.
- Neue Einstellungen mit Default in `hbch_settings_defaults()` eintragen, im Adminpanel (`admin-settings.php`) anzeigen
  und in `hbch_sanitize_settings()` behandeln. Checkboxen zusätzlich in `hbch_current_tab_has_field()` aufnehmen,
  sonst lassen sie sich nicht abschalten.
- **Neue Farbe:** Rolle in `hbch_color_roles()` (`colors.php`) eintragen (Setting-Schlüssel, Variable, Default, Gruppe,
  Hilfetext) und im CSS als `var(--…)` verwenden. Default, Adminfeld, Speichern und `:root`-Ausgabe ergeben sich daraus.
  Soll die Farbe pro Block überschreibbar sein, die Rollen-ID in `hbch_block_color_roles()` beim passenden Block ergänzen.
  Im Editor-JS ist nichts zu ändern (die Liste kommt aus PHP).
- **Neuer Block:** in `blocks.php` (`hbch_register_block()`) und `assets/js/blocks-editor.js` registrieren **und** den
  Blocknamen in `hbch_content_uses_plugin()` (`frontend.php`) aufnehmen, sonst lädt das Plugin auf Seiten mit nur diesem
  Block kein CSS/JS.
- Ändert sich die Struktur eines Einstellungs-Arrays, braucht es einen Migrationspfad für bestehende Installationen.
- Zeit: `gameDateTime` von handball.ch ist naive Schweizer Ortszeit, immer mit `Europe/Zurich` parsen
  (am besten über `hbch_game_datetime()`, das ungültige Werte abfängt).

**Release-Checkliste**

1. `Version:` im Plugin-Header **und** `HBCH_VERSION` in `handballch-api.php` gemeinsam erhöhen.
2. Eintrag oben in `CHANGELOG.md` ergänzen.
3. PHP-Lint über alle Dateien (`php -l`).
4. Prüfen, dass jede `require_once`-Datei aus `handballch-api.php` im Deploy enthalten ist.
5. Auf Staging testen: Rangliste, Team-Spielplan (mit Matchcenter-Link), Startseiten-Widgets, Countdown, ICS-Button,
   Reiter „Farben“, ein Block mit überschriebener Farbe.
6. Nach dem Deploy: Diagnose → Cache leeren, bei Änderungen an den Strukturdaten in der Search Console die Validierung starten.
