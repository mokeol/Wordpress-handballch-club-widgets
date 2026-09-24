=== Club Widgets for handball.ch (unofficial) ===
Contributors: your-wordpress-org-username
Tags: handball, sports, standings, fixtures, calendar
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standings, fixtures, results, countdown and calendar export for Swiss handball clubs via shortcodes and blocks. Unofficial.

== Description ==

Club Widgets for handball.ch shows the data of your handball club on your WordPress site, using the club API at clubapi.handball.ch. Everything is configured in one admin panel: teams, visible fields, texts, colors, cache duration and custom CSS.

**This plugin is not affiliated with or endorsed by the Swiss Handball Federation (SHV).** To use the API, your club needs its own credentials (club ID and token) issued by the SHV.

**Features**

* Compact and detailed standings, with promotion and relegation zones
* Fixtures and results per team
* Club-wide "next games" and "latest results" widgets, for example for a home page (table layout includes a league column)
* Countdown to the next game
* "Subscribe to calendar" button and an ICS feed at `/spielplan.ics` (whole club or a single team)
* LIVE badge while a game is running, marking of forfeited games
* Joint teams (two clubs) with both logos
* schema.org structured data (SportsEvent, JSON-LD), can be switched off
* Public read-only REST endpoints for upcoming and past games
* Eight shortcodes and eight matching Gutenberg blocks, no build step required
* All colors editable as CSS variables, overridable per block
* Scripts and styles load only on pages that use the plugin
* Live preview, diagnostics and built-in documentation in the admin panel

The admin panel and the front-end texts are in German. Labels and texts can be changed in the settings.

**Shortcodes**

* `[hbch_ranking team="team1"]` compact standings
* `[hbch_team_ranking team="team1"]` detailed standings
* `[hbch_team_next_games team="team1"]` upcoming games of a team
* `[hbch_team_last_games team="team1"]` results of a team
* `[hbch_home_next_games limit="3" exclude="U13"]` upcoming games of the whole club
* `[hbch_home_last_games limit="3" exclude="U13"]` latest results of the whole club
* `[hbch_next_game team="team1"]` next game with countdown
* `[hbch_ics team="team1"]` calendar subscription button (no team = whole club)

`team` is the slug you define in the settings, not the numeric team ID.

== External services ==

This plugin connects to the following external services.

**handball.ch club API (clubapi.handball.ch)**

Used to load standings, fixtures, results and team data. Your server sends the club ID or team ID in the request URL and your API token in the `Authorization` header. No visitor data is sent. Responses are cached in the database for the durations set in the plugin settings. The service is operated by the Swiss Handball Federation (SHV): https://www.handball.ch

**handball.ch team logos (handball.ch/images/logo)**

Team and club logos are downloaded by your server and stored in `wp-content/uploads/hbch-logo-cache/`. Until a logo has been stored locally (first request only), the page links directly to the image on handball.ch, so the visitor's browser loads it from there.

**handball.ch match center**

Games can link to their page in the handball.ch match center. This is an ordinary outgoing link, nothing is sent until a visitor clicks it.

**Google Calendar**

The "subscribe" dropdown contains a link to Google Calendar. It is only followed when a visitor clicks it, the plugin itself sends nothing to Google. Google privacy policy: https://policies.google.com/privacy

== Installation ==

1. Upload the `handballch-api` folder to `/wp-content/plugins/` or install the ZIP via Plugins > Add New.
2. Activate the plugin. The permalinks are refreshed automatically so that `/spielplan.ics` works right away.
3. Go to Settings > handball.ch Club Widgets > "Allgemein & API" and enter your club ID and API token (the Base64 string `ClubID:Secret` issued by the SHV).
4. Add your teams, one per line, as `slug=team-ID` (for example `team1=12345`). The team ID is part of the team's URL on handball.ch.
5. Open the "Diagnose" tab and click "Alle Team-IDs jetzt prüfen". Every ID should show as valid.
6. Insert shortcodes or blocks on your pages.

Team IDs change every season. After a season change, check them again in the "Diagnose" tab and enter the new ones.

== Frequently Asked Questions ==

= Where do I get a club ID and token? =

From the Swiss Handball Federation (SHV). The plugin cannot work without them.

= The standings show "Rangliste momentan nicht verfügbar". =

The token is wrong or empty, the API cannot be reached, or the team ID is outdated. Use the "Diagnose" tab to check all team IDs and to fetch the raw API response.

= The data is out of date. =

Clear the cache in the "Diagnose" tab, or shorten the cache duration in the settings.

= `/spielplan.ics` returns a 404 error. =

Go to Settings > Permalinks and click "Save Changes".

= There is no styling, or the countdown does not run. =

The shortcode is probably outside the page content (theme template, page builder). Load the assets everywhere with:

`add_filter( 'hbch_force_assets', '__return_true' );`

= How do I change a color for one page only? =

Use the "Farben" panel in the block sidebar, or add CSS such as `.page-id-123 { --hbch-color-accent: #004ABD; }` in the "Eigenes CSS" field of a settings tab.

= How long does a game count as "live"? =

The API has no "running" status, so a game counts as live for 90 minutes after kick-off. Change it with the filter `hbch_live_game_duration_minutes`.

= Does the plugin work for other countries? =

No, it is built for the API of the Swiss Handball Federation.

== Changelog ==
= 1.0.3 =
* Club-wide fixtures/results table layout now shows the league per game (a new column right after date/time), since this view mixes several leagues. The per-team schedule is unaffected (always a single league there).
* Renamed the Gutenberg blocks as they appear in the block inserter (display names only, the underlying block names and existing pages are unaffected).
= 1.0.2 =
* Fixed timezone-inconsistent sorting of game lists (standings, fixtures, ICS export).
* Added nonce protection to the "load teams"/"check team IDs" admin actions.
* Added a daily cleanup job for the local logo cache to prevent unbounded disk growth.
* Removed unused parameters left over from earlier refactors.
= 1.0.1 =
* Layout of the club-wide "latest results" widget: date and spectators on one line, team columns of equal width.

= 1.0.0 =
* First public release.
