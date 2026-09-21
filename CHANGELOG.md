# Changelog

Die Versionshistorie steht in dieser Datei; der Reiter „Changelog“ im Adminpanel zeigt sie an.
Bei jedem Release: Header-Version **und** Konstante `HBCH_VERSION` in `handballch-api.php` gemeinsam anpassen.
Der Dateiname muss exakt `CHANGELOG.md` lauten (auf Linux-Servern case-sensitive).

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
