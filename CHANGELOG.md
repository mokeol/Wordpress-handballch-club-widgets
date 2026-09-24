# Changelog

Die Versionshistorie steht in dieser Datei; der Reiter „Changelog“ im Adminpanel zeigt sie an.
Bei jedem Release: Header-Version **und** Konstante `HBCH_VERSION` in `handballch-api.php` gemeinsam anpassen.
Der Dateiname muss exakt `CHANGELOG.md` lauten (auf Linux-Servern case-sensitive).

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
  - 
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
