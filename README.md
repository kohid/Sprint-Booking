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
4. **Taxi Bookings → Dashboard / Bookings**: today's pickups, what needs action, a 14-day chart, search and filters, CSV export, and a side panel to change a booking's status. Access is by role: **Settings → Booking rules → Who can open the dashboard** lists every role on the site; tick the ones that may see the dashboard and change statuses. Administrators always can; customers never can. The **Taxi dispatcher** role is made for this. (Under the hood the roles get the `sb_manage_bookings` capability.)
5. **Settings → Shortcodes** lists every shortcode with attributes, a Copy button, the pages that use it, and a "Create page" button (makes a draft). In Elementor, drop a Shortcode widget on a page and paste one in.

| Shortcode | For | Shows |
|---|---|---|
| `[sprint_booking_form]` | everyone | the booking form |
| `[sprint_chat_booking form_url="/book/"]` | everyone | the booking assistant as a chat: taxi as soon as possible, taxi for later, cancel, change, talk to a person, book without the assistant |
| `[sprint_my_bookings]` | customers | their own bookings |
| `[sprint_dashboard view="bookings" overview_url="/dispatch/" bookings_url="/dispatch/bookings/"]` | staff | dashboard with side menu; Overview and Bookings are always separate pages; the menu finds them automatically (or set the URLs) |
| `[sprint_dashboard_overview bookings_url="/bookings/"]` | staff | overview only |
| `[sprint_dashboard_bookings status="needs_action" per_page="20"]` | staff | bookings list only |

All dashboard shortcodes fill the browser's full width and height; add `fullscreen="no"` to keep one inside the page column.

**Dashboard pages:** Settings → Shortcodes → "Create the two dashboard pages" makes `/dashboard/` and `/dashboard/bookings/`, each holding `[sprint_dashboard]`; the side menu links between them. There are no `#overview` / `#bookings` views.

**Phone agent (Settings → Phone agent) and Test chat.** Twilio answers a UK number and hands the call to an ElevenLabs agent; the agent books through this plugin. Twilio account details go into ElevenLabs, not into WordPress. In Settings you set the greeting the agent reads, the operator number, a blocked-numbers list and the agent ID, switch the agent on, and make the secret the agent must send. Only a hash of the secret is stored; it is shown once.

| Endpoint (secret required) | Use |
|---|---|
| `GET /wp-json/sprint-booking/v1/voice/config?caller=…` | greeting, operator number, `blocked`, services, cars |
| `POST /wp-json/sprint-booking/v1/voice/bookings` | create a booking; addresses may be plain text |

Phone bookings use the same pricing, limits and emails as the website form, are marked "phone" in the booking and the office email, and reject blocked callers. **Taxi Bookings → Test chat** is a scripted stand-in for the agent (service, addresses, via stops, time, passengers, car, pets, contact details, fare, book) so the flow can be tried without a call. Also available to the agent: `POST /voice/manage` (cancel or change a booking given its reference and the email it was made with) and `POST /voice/events` (report `transferred` to the operator or `bypass`). Settings → Phone agent opens with a six-step setup guide (Twilio number, ElevenLabs agent, connecting them, the four tools, switching on, optional Google), with links to the places where the IDs and keys live and a copyable set of starting instructions for the agent. The Twilio Account SID and Auth Token and any ElevenLabs API key are entered in those services, not in WordPress.

**Daily report:** the dashboard Overview has a "Calls and chats today" card (taken, booked, cancelled, changed, passed to an operator, skipped the assistant, blocked; last 7 days). Only outcomes and the last four digits of a caller's number are stored, and rows older than 90 days are deleted.

**Google:** Settings → Booking rules → Address lookup can use the Google Geocoding API (UK addresses and postcodes) with your key; the key is never sent to visitors.

Not built yet: the AI conversation itself (that lives in ElevenLabs), a customer-facing "my bookings" inside the chat, and changing addresses on an existing booking (cancel and rebook instead).

**Email:** Settings → Email sends a test message and lists the last 30 booking emails with any failure reason.

The dashboard shortcodes show a sign-in form to visitors and a "No access" notice to signed-in users whose role is not ticked; the data itself is only served to staff by the REST API.

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
php tests/dashboard-test.php          # filters, overview numbers, row presentation
php tests/catalogue-test.php          # Settings -> Shortcodes matches registered shortcodes
php tests/voice-test.php              # phone numbers, blocked list, secret
# dashboard-test.php also covers cancel/change rules and the daily report
```

Browser tests of the form (`e2e.js`), dashboard (`dashboard-e2e.js`) and chat (`chat-e2e.js`) are in `tests/e2e/` (see its README).

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
