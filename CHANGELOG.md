# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased] – branch: `small_fix`

### Fixed

- **Dashboard date language** – Date on the dashboard was always rendered in English regardless of the selected language. Replaced the unavailable `IntlDateFormatter` extension with manual translation using `day_*` and `month_N` keys from the language files.
- **Timeline pagination label** – "Showing X–Y of Z executions" was hardcoded English. Replaced with the translatable `timeline_showing` key supporting `{from}`, `{to}`, and `{total}` placeholders.
- **Swimlane UI strings hardcoded in English** – Info bar and tooltip strings (time prefix, day names, job count labels, next-run formatting, etc.) were hardcoded JavaScript strings. Fixed by injecting a translated `I18N` object from PHP into the JavaScript IIFE.
- **Tag filter showing unused tags** – Tag filter dropdowns on the Timeline, Swimlane, and Export pages showed tags that had no jobs assigned. Fixed by filtering to tags with `job_count > 0` in `TimelineController`, `SwimlaneController`, and `ExportController`.
- **Role badge showing "Viewer" for admin users** – `layout.php` resolved `$user` conditionally via `isset($user)`, which was polluted by `foreach ($users as $user)` loops in sub-templates, leaving `$user` as a plain string with no `role` key. Fixed by always calling `SessionManager::getUser()` unconditionally in `layout.php`.
- **Filter lost after editing a cron job** – After saving an edit the user was redirected to the unfiltered cron list. Fixed with a `_return` URL pattern: the current filtered list URL is URL-encoded into the edit link, passed through the form as a hidden field, and used as the redirect target after a successful save.
- **Filter lost after adding a new cron job** – Same issue as above, applied to the Add Job flow.
- **Import success message not displayed** – After a successful import `importStore()` redirected to `/crons` (the list page), but the flash message is only rendered on the import template. Fixed by redirecting back to `/crons/import?user=…` so the message is shown and consumed correctly.
- **Import rejects `@`-style and named-field cron schedules (HTTP 422)** – The agent's `isValidCronSchedule()` used a hand-rolled regex that only accepted five or six purely numeric/special-character fields. This rejected valid expressions such as `0 5 * * sat`, `0 5 * jan *`, `@monthly`, `@daily`, etc. Replaced the regex with `dragonmantank/cron-expression` for full semantic validation. `@reboot` is accepted as a special case (it is a valid vixie-cron directive but is not a time expression and is therefore not supported by the library). Applied to both `CronCreateEndpoint` and `CronUpdateEndpoint`.

---

## [speedup] – branch: `speedup` – merged via PR #2

### Added

- **APCu caching for Swimlane page** – `SwimlaneController` now caches the results of `getMultipleRunDates()` and `translateCron()` per expression using APCu (`cronmgr_sched_<md5>` / `cronmgr_trans_<md5>`, TTL 86400 s). Falls back gracefully when APCu is unavailable.
- **Timing instrumentation** – `hrtime(true)` checkpoints measure agent call, compute loop, and JSON encode phases. Timings are logged at DEBUG level and rendered as an HTML comment when `?debug=1` is passed.
- **Swimlane tag filter** – Tags with no assigned jobs are now excluded from the Swimlane tag filter dropdown.

---

## [jobview] – branch: `jobview` – merged via PR #1

### Added

- **Swimlane view** – New visual timeline page showing all active cron jobs across a 24-hour swimlane grid with per-job schedule visualisation, tag filtering, and tooltips.

---

## [Initial release] – branch: `main` (initial commit)

### Added

- Initial project implementation including host agent, web UI, authentication, cron job CRUD, timeline, export/import, tag management, and i18n (de/en).
