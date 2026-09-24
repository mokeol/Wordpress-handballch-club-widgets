# Changelog

Die Versionshistorie steht in dieser Datei; der Reiter „Changelog“ im Adminpanel zeigt sie an.
Bei jedem Release: Header-Version **und** Konstante `HBCH_VERSION` in `handballch-api.php` gemeinsam anpassen.
Der Dateiname muss exakt `CHANGELOG.md` lauten (auf Linux-Servern case-sensitive).

## 1.0.5 — Robusterer Cache, weniger JavaScript, Aufräumen
- Neu: Cache-Versionszähler. Jeder Transient-Schlüssel enthält die Version, „Cache jetzt leeren“ zählt sie hoch. Das
  funktioniert auch mit externem Object-Cache (Redis/Memcached). Beim Speichern der Einstellungen wird der Cache
  automatisch geleert.
- Neu: „Letzte gute Kopie“. Antwortet die API nicht, zeigt das Plugin die zuletzt geladenen Daten (bis zu 7 Tage, Filter
  `hbch_stale_cache_seconds`) statt „Rangliste momentan nicht verfügbar“. Ein kurzer Fehler-Marker (60 Sekunden)
  verhindert, dass jeder Seitenaufruf 5–8 Sekunden auf die API wartet.
- Das fertige Ranglisten-HTML wird nicht mehr separat gecacht (die Rohdaten sind es bereits). Spaltenänderungen wirken
  dadurch sofort, die Cache-Signatur aus 1.0.4 entfällt.
- Datum und Uhrzeit werden serverseitig formatiert (Europe/Zurich, deutsche Monats- und Wochentage). Das bisherige
  Datums-JavaScript entfällt: kein Layout-Sprung mehr, auch ohne JavaScript lesbar.
- „Eigene Mannschaft hervorheben“ passiert serverseitig (ohne Beachtung der Gross-/Kleinschreibung).
- Countdown: Datum, Halle und Startwerte kommen vom Server, ein leerer Hallenname hängt keinen Trennpunkt mehr an.
- Alle Inline-Scripts im Frontend (Datum, Hervorhebung, Countdown, Kalender-Dropdown) liegen in `assets/js/public.js`
  (vom Browser cachebar, WordPress.org-tauglich).
- Alte Blocknamen (`handballch/team-ranking`, `team-next-games`, `team-last-games`, `home-next-games`,
  `home-last-games`) werden im Frontend auf die neuen Blöcke umgeleitet und im Editor zum Umwandeln angeboten
  (`assets/js/blocks-legacy.js`, Liste in `hbch_legacy_block_map()`). Die Asset-Erkennung kennt sie ebenfalls.
- Logos: Ein Logo, das sich nicht laden lässt, wird 1 Tag lang nicht erneut geplant. Die Prüfung der lokalen Datei
  läuft pro Logo nur einmal je Seitenaufruf.
- Aufräumen: reine Funktionen `hbch_get_next_games()` / `hbch_get_last_games()` statt interner REST-Requests,
  `hbch_render_next_game()` und `hbch_render_ics_button()` statt `do_shortcode()` in den Blöcken,
  `hbch_is_game_played()` statt fünfmal `stripos()`, Spiele werden per Zeichenkettenvergleich sortiert.
- Aufräumen: ungenutzte CSS-Regeln entfernt, Klassen-Hinweise im Reiter „Vereins-Spielplan“ aktualisiert,
  `wp_delete_file()` statt `@unlink()`.
- `uninstall.php` entfernt auch die Cache-Versions-Option.

## 1.0.4 — Fehlerbehebungen & Sicherheit
- Behoben: `HBCH_VERSION` stand noch auf `1.0.2` (Header: `1.0.3`); Browser konnten dadurch veraltetes CSS/JS behalten.
- Behoben: Auf Seiten, die nur die Blöcke „Team – Spielplan / Resultate“ oder „Verein – Spielplan / Resultate“
  enthielten, wurden CSS und JS nicht geladen (die Block-Erkennung kannte nur die alten Blocknamen).
- Behoben: Nach einer Änderung der Ranglisten-Spalten oder des Doppel-Logo-/Such-Text-Felds passten Kopfzeile und
  gecachte Zeilen bis zu 20 Minuten nicht zusammen. Der Cache-Schlüssel enthält jetzt eine Signatur dieser Einstellungen.
- Behoben (Kalender-Dropdown): „Kalenderlink kopieren“ und „Teilen“ reagierten nicht, wenn man auf das Icon klickte;
  nach dem Kopieren fehlte das Icon; „Teilen“ war auch ohne Browser-Unterstützung sichtbar; Fallback fürs Kopieren
  auf Seiten ohne https.
- ICS-Export: Forfait-Spiele werden nicht mehr exportiert; Zeiten werden in UTC ausgegeben (kein VTIMEZONE-Block
  nötig, zuverlässig in Outlook); unbekannter `?team=`-Slug liefert eine 404-Seite statt eines leeren Kalenders.
- Behoben: Countdown zeigte ein „&“ im Hallennamen als „&amp;“.
- Behoben: „Eigene Mannschaft hervorheben“ vergleicht jetzt ohne Beachtung der Gross-/Kleinschreibung (wie die
  Erkennung von Spielgemeinschaften) und kommt mit Sonderzeichen wie „&“ im Such-Text zurecht.
- Behoben: Ein einzelner ungültiger Datumswert der API konnte die ganze Seite lahmlegen (Fatal Error in
  `/next-games` und beim Countdown). Solche Spiele werden jetzt übersprungen.
- Sicherheit: Der REST-Parameter `source` wurde entfernt. Er erlaubte beliebig viele authentifizierte Anfragen und
  Cache-Einträge. Die Endpunkte lesen immer die Spielliste des eigenen Vereins.
- Sicherheit: REST-Parameter (`limit`, `exclude`, `include_live`) werden bereinigt/validiert; `include_live=false`
  wird jetzt auch als „aus“ erkannt.
- Sicherheit: Das API-Passwort wird nicht mehr im Formular ausgegeben (kein Wert im Seitenquelltext). Leer lassen
  beim Speichern behält das gespeicherte Passwort. Sonderzeichen im Passwort werden nicht mehr verändert.
- Sicherheit: Anfragen mit Zugangsdaten folgen keinen Weiterleitungen mehr.
- Sicherheit: Der Logo-Download lädt nur von `https://handball.ch/…` und speichert nur echte Bilddateien; IDs in
  Logo-URLs werden als Zahlen erzwungen.

## 1.0.3 — Liga-Spalte im Vereins-Spielplan, Block-Umbenennung
- `[hbch_home_next_games layout="table"]` / `[hbch_home_last_games layout="table"]` (Block
  „Verein – Spielplan / Resultate“ mit Layout „Tabelle“): neue Liga-Spalte direkt nach
  Datum/Zeit, da hier mehrere Ligen gemischt vorkommen. Der Team-Spielplan bleibt
  unverändert (dort immer nur eine Liga).
- Gutenberg-Blöcke im Block-Inserter umbenannt (nur die Anzeige, die eigentlichen
  Blocknamen bleiben unverändert, bestehende Seiten sind nicht betroffen):
  - „handball.ch: Rangliste“ → „Rangliste“
  - „handball.ch: Team – Spielplan“ → „Team – Spielplan / Resultate“
  - „handball.ch: Verein – Spielplan“ → „Verein – Spielplan / Resultate“
  - „handball.ch: Kalender abonnieren“ → „Kalender“

## 1.0.2 — Fehlerbehebungen & Aufräumen
- Sortierung von Spiellisten (Rangliste/Spielpläne/ICS) nutzt jetzt konsequent `Europe/Zurich` statt der
  Server-Standardzeitzone (konnte rund um die Zeitumstellung zu falscher Reihenfolge führen).
- Admin-Aktionen „Teams von handball.ch laden“ und „Alle Team-IDs jetzt prüfen“ (Reiter „Allgemein & API“)
  sind jetzt per Nonce abgesichert.
- Neuer täglicher Aufräum-Job entfernt veraltete Dateien aus `uploads/hbch-logo-cache/` (bisher unbegrenztes
  Wachstum, z. B. nach Team-ID-Wechsel pro Saison).
- Toten Code entfernt: ungenutzter `$club_id`-Parameter in `hbch_ics_fetch_games()`, ungenutzter
  `$mode`-Parameter bei den Logo-Funktionen (Überbleibsel der entfernten `loading="lazy"`-Logik).

## 1.0.1 — Layout „Vereinsweit – letzte Resultate“
- `[hbch_home_last_games]` / Block „Vereinsweit – letzte Resultate“: Datum und Zuschauerzahl stehen auf einer Zeile
  (auf schmalen Bildschirmen untereinander).
- Neues Grid-Layout: die Mitte (Liga, Resultat, Datum, Halle) ist so breit wie ihr Inhalt, die beiden Team-Spalten
  sind immer gleich breit.
- Die Zuschauerzeile erscheint nur noch, wenn eine Zuschauerzahl vorhanden ist.

## 1.0.0 — Erste öffentliche Version
- Shortcodes und Gutenberg-Blöcke für kompakte und detaillierte Rangliste (mit Auf-/Abstiegszonen), Team-Spielplan
  (nächste/letzte Spiele), Vereins-Spielplan für die Startseite, Countdown zum nächsten Spiel und
  „Kalender abonnieren“-Button.
- ICS-Kalender-Export unter `/spielplan.ics` (ganzer Verein oder `?team=slug`).
- Öffentliche REST-Endpunkte `/wp-json/handballch/v1/next-games` und `/last-games`.
- Adminpanel mit Live-Vorschau, Feld- und Spaltenauswahl, Texten, Cache-Dauer, eigenem CSS und Diagnose
  (Cache leeren, Rohdaten abrufen, Team-IDs prüfen).
- Alle Farben im Reiter „Farben“ als CSS-Variablen (`--hbch-*`), pro Block überschreibbar.
- LIVE-Badge, Forfait-Kennzeichnung, Spielgemeinschaften mit Doppel-Logo, lokaler Logo-Cache.
- schema.org-Strukturdaten (`SportsEvent`, JSON-LD), abschaltbar.
- Laden von CSS/JS nur auf Seiten, die einen Shortcode oder Block des Plugins enthalten.
