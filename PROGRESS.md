# Project Progress Log

## Last Updated
Friday, Oct 9, 2026 — 6:56 PM (UTC+5)

## Current State
- App is running on MAMP at http://127.0.0.1:8888/b2bhotels-backup/
- Apache is on port 8888 (PHP 8.3.30 via FastCGI). MySQL is on port 8889, database `bbhotelm_db`.
- Homepage redirects to `/login` because user restriction is on.
- RateHawk / GRN stay flow from the previous session is unchanged.

## What Was Done This Session
- Started from an already-running MAMP Apache. PHP was not executing: `.htaccess` set a cPanel handler (`application/x-httpd-ea-php84`) that MAMP does not have, so Apache returned the PHP source.
- Commented out that cPanel `AddHandler` so MAMP FastCGI handles `.php`.
- `config.php` now passes `DB_PORT` from `.env` into Medoo. `.env` host is `127.0.0.1` so PHP uses TCP port 8889 instead of the system MySQL socket.
- Created MySQL users `bbhotelm_db`@`127.0.0.1` and `bbhotelm_db`@`localhost` with access to `bbhotelm_db`. The `settings` table is present.
- Verified in the browser: the login page renders (HBK Holidays / b2bhotels.pk).
- Login for `hbk@hbkholdings.com.pk` failed because sessions were saved to cPanel’s `/var/cpanel/php/sessions/ea-php84`, which does not exist on this Mac. The database and password were already valid.
- Session path is now `/Applications/MAMP/tmp/php` in `php.ini`, `.user.ini`, and `.htaccess`. No application PHP was changed for this. Apache was restarted. Login redirects to the admin dashboard.

## In Progress / Half Done
- RateHawk Search → rooms (`book_hash`) → issue → cancel still not completed locally.

## Next Steps (Do This First When You Return)
1. Sign in at http://127.0.0.1:8888/b2bhotels-backup/login (user restriction is on).
2. New GRN search → select a 3-room package → checkout → pay. Do not retry the failed invoice (that `rate_key` is spent).
3. Confirm book payload: same `search_id` + `rate_key` + `room_code` + `group_code`; `rooms` length equals `no_of_rooms`; every room has `room_reference`.
4. Confirm a RateHawk hotel still uses the main design.

## Known Issues / Blockers
- Failed invoice `72A2D038` cannot be re-issued with the old `rate_key`.
- Local sandbox SERP Dubai ids ≠ local 4 Dubai rows → RateHawk listing stays small.
- cPanel `AddHandler` in `.htaccess` is commented out for local MAMP. Restore it before deploying this copy back to cPanel.

## Key Decisions & Context
- Do not copy beta onto RateHawk files. GRN uses separate `*-grn.php` views.
- GRN flow: `sid` / `rate_key` / `search_id` / bundled `rate_key` (one key covers multiple rooms).
- Each GRN booking room uses `room_reference` from that same `rooms[]` entry (search if bookable, refetch/recheck otherwise). Never copy another room’s ref.
- RateHawk `rooms.php` select logic is qty/`book_hash` and must not be used for GRN packages.
- Local DB is MAMP MySQL 8 on port 8889. `DB_HOST` must stay `127.0.0.1` (not `localhost`) or PHP ignores `DB_PORT`.
---
