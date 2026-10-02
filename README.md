# Sprint Booking

A WordPress plugin for taxi booking in Inverness: a three-step booking form with address suggestions
while typing, via stops, automatic distance pricing, return trips, luggage charges, six service types
and optional customer accounts, plus a bookings list and tariff settings in wp-admin.

Requires WordPress 6.0+ and PHP 8.0+. This is v0.1 — see `docs/booking-form-spec.md` for what is and is not built.

## Use

1. Copy the folder to `wp-content/plugins/sprint-booking/` and activate it. Activation creates the `wp_sb_bookings` table.
2. Add the form to a page: `[sprint_booking_form]`
   - Limit the services: `[sprint_booking_form services="airport,minibus"]`
   - Preselect one: `[sprint_booking_form service="golf"]`
   - Customers' own bookings: put `[sprint_my_bookings]` on a page ("My bookings").
3. **Taxi Bookings → Settings**: replace the temporary tariff with your real rates, set the email that receives bookings, choose a photo for each car (otherwise a simple illustration is shown), and set production routing and address-suggestion services.
4. **Taxi Bookings → Bookings**: see new bookings and change their status.

## How the price is worked out

Distance is measured automatically from pickup through every via stop to drop-off. The tariff
(starting fee, price per mile, minimum fare, via stop fee, free suitcases, fee per extra suitcase,
return discount, per-car multiplier) lives in Settings. The server recalculates the price when a
booking is created, so the browser's figure is never trusted.

## Before going live

The defaults use free public services that forbid heavy use. Replace them first:

| Service | Used for | Setting / file |
|---|---|---|
| OpenStreetMap tile server | map tiles | `includes/Shortcode.php` (`tiles`) |
| photon.komoot.io (Photon) | address suggestions while typing | Settings → Address suggestions URL |
| OSRM demo server | driving distance | Settings → Routing service URL |

Also: set a real tariff, add payment, and check the privacy policy covers the details collected.

## Updates and releases

The plugin updates itself from this repository's GitHub Releases, so a new version appears as the
normal **Update now** notice on the WordPress Plugins screen. Use **Check for updates** on the plugin's
row to refresh the check straight away (it is otherwise cached for six hours).

To publish a new version:

1. Change `Version:` in `sprint-booking.php` **and** `SB_VERSION` to the new number, and commit.
2. Tag and push (`git tag v0.2.0 && git push origin v0.2.0`), or run the workflow by hand: Actions → Release → Run workflow, tag `v0.2.0`.
3. The **Release** workflow checks the tag matches the plugin version, runs the tests, builds
   `sprint-booking.zip` and publishes the release. Sites then offer the update.

To build the zip locally instead: `bin/build-release.sh` (writes `dist/sprint-booking.zip` from the committed files).
The zip keeps everything in a `sprint-booking/` folder, so an update replaces the plugin rather than adding a second copy.

The updater only reads a **public** repository. If you make the repository private, updates will stop being offered.

## Tests

```bash
php tests/pricing-test.php            # fare calculation
php tests/rest-validation-test.php    # server-side validation (WordPress stubbed)
php tests/updater-test.php            # GitHub update decisions
php tests/geocoder-test.php           # address suggestion parsing
```

A browser test of the form is in `tests/e2e/` (see its README).

## Structure

```
sprint-booking.php   bootstrap and autoloader
includes/            Pricing, Routing, Geocoder, Rest, Bookings, Accounts, Mailer, Admin, Settings, Shortcode, Updater…
bin/                 build-release.sh
templates/           booking-form.php
assets/              css/, js/, vendor/leaflet (BSD-2) and vendor/flatpickr (MIT), both bundled
tests/               CLI tests and the browser test
```

Leaflet 1.9.4 (BSD 2-Clause) and flatpickr 4.6.13 (MIT) are bundled under `assets/vendor/`, licences included.
