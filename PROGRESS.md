# Project Progress Log

## Last Updated
Monday, Oct 12, 2026 — 8:50 PM (UTC+5)

## Current State
- App is running on MAMP at http://127.0.0.1:8888/b2bhotels-backup/
- GRNConnect API test works. `modules/index.php` now uses `DB_PORT` (8889).
- GRN content database `bbhotelm-grnconnect` and user `bbhotelm-grnconnect` exist on MAMP MySQL (port 8889). Module host is `127.0.0.1`. phpMyAdmin: http://localhost:8888/phpMyAdmin5 (root / root).
- GRNConnect flow is wired: import (admin module), city/hotel lookup, streaming search, open hotel with search id, room select, checkout recheck, then payment calls the existing issue endpoint.
- GRN hotel pages use `stay-grn.php` and `index-grn.php`. RateHawk still uses its own pages.
- No hotel was booked. The live book API was not called.

## What Was Done This Session
- `grnconnect_hotels.hotel_code` already has a unique index (`hotel_code`). `EXPLAIN` for an 80-code `IN` lookup uses that index (`type=range`). No extra index was added.
- After each availability batch, available hotel codes are loaded from `grnconnect_hotels` only when the rate response is missing name, address, coordinates, facilities, accommodation type, or images. That row fills the listing card. If both the response and the hotels `images` column are empty, photos are read from `grnconnect_hotel_images` (index `idx_hotel_code` already exists).
- An 80-code lookup returned in about 7ms; 73 of those Dubai rows have facilities, and the hotels `images` column was empty for all 80.
- Dubai search still streams only hotels with a rate. Cutoff stays 15s. Catalog hotels with no rooms are not sent. Last full stream before this lookup: 621 available, 0 no-room rows, 21.7s.

## What Was Done Earlier This Session
- Copied the GRNConnect module from `/Applications/XAMPP/xamppfiles/htdocs/b2bhotels/modules/stays/grnconnect` into this project, including content import, pay-later helpers, and booking status checks.
- Copied `modules/stays/hotel_name_query.php` because the GRN module entry point loads it for hotel-name search.
- GRN detail and checkout routes now load the GRN views. Other suppliers keep the existing views.
- Booking checks that GRN rejects otherwise:
  - Child ages clamped to 1–17 on search, listing, and hotel detail.
  - Guest titles limited to Mr., Ms., Mrs., and Mstr. for children.
  - Names limited to letters and spaces, 30 characters.
  - Price-change and availability checks remain in `validation.php` and run before a book call.
- PHP checks for ages, titles, names, pay-by date, price change, and unavailable rates passed. No booking request was sent.

## In Progress / Half Done
- Pay-later admin notice, invoice banner, and expiry cron from the original app are not wired. Payment still calls `modules/stays/grnconnect/issue` after a successful payment.

## Next Steps (Do This First When You Return)
1. Sign in and search Dubai. Cards should be hotels that have a rate, with facilities and any missing name/address/photos filled from the catalog by `hotel_code`.
2. Open a hotel, select a room, and stop on checkout before payment.
3. Confirm checkout shows the GRN titles and waits for rate verification before submit.

## Known Issues / Blockers
- Safari 500 on GRN search was MAMP FastCGI idle timeout (30s). `httpd.conf` FastCgiServer has `-idle-timeout 120`.
- GRN search stays in progress until the rate stream finishes. Catalog hotels with no rooms are not sent. Dubai stream: 621 available, 0 no-room rows, 21.7s, cutoff 15s.
- GRN availability uses SSL verify on. Socket wait is cutoff + 25s, capped at 40s. Curl timeout 28 is not retried.
- Dubai city row `grnconnect_cities.country_code` is blank. Hotels carry `AE`. City search uses `city_code` 121449, so the blank city country does not hide them.
- Stays pages redirect to login while user restriction is on.
- Destination, guests, and nationality dropdowns need `window.flIsDesktop` and `window.flAnchorPanel` in `assets/js/app.js`. Those helpers are in place. Reload the stays page to pick them up.
- cPanel `AddHandler` in `.htaccess` stays commented out for local MAMP.
- Failed invoice `72A2D038` still cannot be re-issued with the old `rate_key`.

## Key Decisions & Context
- `grnconnect_hotels.hotel_code` is already UNIQUE. Lookups after availability use that index for the returned codes only, not a preload of every city row’s long text.
- Only the GRNConnect module and the small GRN booking checks were brought over. RateHawk views were left as they were.
- Live GRN credentials are in use. Do not submit a booking, issue, or pay-later confirm against the live API.
- GRN book payload still needs the same `search_id`, `rate_key`, `room_code`, and `group_code`, with `room_reference` on every room.
---
