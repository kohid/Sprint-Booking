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

**Payments (Settings → Payments).** Customers can pay the driver (the default) or pay online with Stripe (card) or PayPal. They pay on the provider's own page, so card details never reach your site; the plugin only learns the outcome.

- **Where to put the keys:** Stripe: a secret key (`sk_test_…` for sandbox, `sk_live_…` for live; restricted `rk_` keys work) and, for the webhook, its signing secret. PayPal: a client ID and secret from a sandbox or live app. Each gateway has a Sandbox switch and separate boxes for sandbox and live keys; a key of the wrong kind switches that gateway off. Saved keys are never shown again (only the last four characters), a blank box keeps what is saved, and "Remove saved keys" clears them. **Test connection** checks a key without charging anything.
- **Stripe webhook:** add `…/wp-json/sprint-booking/v1/pay/stripe-webhook` in Stripe for `checkout.session.completed` and `checkout.session.async_payment_succeeded`. Payments are also confirmed when the customer comes back to your site, so the webhook mainly catches customers who close the tab. PayPal needs no webhook: the order is captured and checked on return.
- **How a payment is trusted:** a booking is marked paid only after Stripe or PayPal confirms it to the server (a signed webhook, or the plugin asking the provider directly), for the exact fare and currency stored on the booking, once only. The browser's word is never enough. Every booking has a random pay token (only its hash is stored); a payment link without it does nothing.
- **Where the buttons are:** the booking form (last step: pay the driver, pay now by card, pay now with PayPal), the website chat and Test chat (after the fare), and the phone agent (`payment` is `driver`, `stripe` or `paypal`; a secure link is in the confirmation email, because the agent must never take card numbers). Confirmation emails carry "Pay now" links for anyone who chose the driver but changes their mind. Quote requests have no fare, so no payment.
- **After payment:** the customer gets a receipt, the office gets a note, the dashboard shows a Paid / Awaiting payment badge, and the CSV has a Payment column. Cancelling a paid booking alerts the office that a refund is due; refunds themselves are made in Stripe or PayPal.
- Tested with simulated Stripe and PayPal servers (`tests/payment-flow-test.php`). Run a sandbox payment before going live: the plugin cannot prove the providers' live responses match.

**Return journeys (v0.10).** A return is stored as two bookings, each with its own reference: the way out and the return. Either reference can be used to cancel or change that journey alone; the customer's email, the booking form and the phone and chat assistants show both. One payment covers both journeys and each records its own share. On the Bookings page each leg is its own row with a Way out / Return badge, the partner reference beneath, and a Journeys filter. The Journey column lists pickup, via stops and drop-off one to a line. Bookings made before v0.10 stay as single rows.

**Editing a booking (v0.10).** Open a booking and choose Edit to change the pickup time, route, car, party, contact details, notes or fare at the customer's request. "Recalculate fare" previews the new price (worked out again on the server with your tariff, or type a fare to override it). Contact details are copied to the other leg of a return, a return cannot be set before its way out, every change is kept in the booking's History, and the customer can be emailed the changes. If the fare changes on a paid booking the amount paid is kept and the difference is flagged. Completed and cancelled bookings cannot be edited.

**Calendar.** The date filters on the Bookings page and the edit form use the same calendar as the booking form.

**WhatsApp (v0.11).** Settings → WhatsApp has a seven-step set-up guide, the keys, and a connect-and-test panel. Two providers: **Twilio** (the same account as the phone line; its sandbox lets you try it today) or the **Meta Cloud API**. Two uses, each on its own switch:

- *Booking updates.* The booking form shows "Send my booking updates on WhatsApp" (unticked, never assumed). For those customers we message: booking received, confirmed, driver assigned, cancelled, changed by staff, payment received. Customers reply STOP to stop and START to resume; only a fingerprint of their number is kept. Outside WhatsApp's 24-hour window an approved template is required, so you enter its name (Meta) or Content SID (Twilio) and the text goes in its one variable.
- *Booking assistant.* Customers message the number and get a menu: taxi now, taxi for later, cancel, change a pickup time, talk to a person. Bookings are made with the same checks, prices and emails as the website form (including returns with two references). Cancel and change use the same reference-plus-email rules as the website chat. Blocked numbers are ignored, a number is limited to 40 messages per ten minutes, and each provider message is processed once. Bookings made this way are badged WhatsApp in the dashboard, and the daily report counts them.

Every incoming message is checked against the provider's signature (Twilio token or Meta app secret) before anything is read. Paste the webhook address from "Connect and test" into Twilio ("When a message comes in", POST) or Meta (Callback URL, plus the verify token it shows). It must be https. Keys are never shown again after saving, only their last four characters. WhatsApp, Twilio and Meta charge and approve business numbers on their own terms: check their current prices and rules first. Not yet built: tap-to-reply buttons and lists (replies are numbered text), and an AI free-text agent.

**Demo data (Settings → Demo).** "Generate 60 demo bookings" makes 10 for each of the six services, one service at a time with progress, using your tariff and routing service. Customers are made up (reserved 07700 900xxx numbers, example.com emails), nothing is emailed and no payment is taken. Demo bookings carry a Demo badge, count in dashboard figures, and "Delete demo data" removes only rows marked demo.

**Email:** Settings → Email sends a test message and lists the last 30 booking emails with any failure reason.

The dashboard shortcodes show a sign-in form to visitors and a "No access" notice to signed-in users whose role is not ticked; the data itself is only served to staff by the REST API.

## The booking form

- **Return journey:** tick "I also need a return journey" and the route splits into two tabs, Journey and Return journey. On the return tab, "The return follows the same route in reverse, with the same via stops" is ticked by default and fills the return route from the way out (read-only, updating as the way out changes). Untick it and the return route is empty: the customer enters their own pickup, via stops and drop-off, and the fare is worked out from that route's own distance.
- **Name:** first name and last name are separate fields (stored together as one name).
- **Map:** zooms with the mouse wheel, so the page does not scroll while the pointer is over the map.
- **Width:** `.sb-app` fills whatever column it is put in, so an Elementor section or container set to Full Width or Boxed decides the width.

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
php tests/payment-test.php            # amounts, webhook signatures, redirect safety
php tests/payment-flow-test.php       # the Payments class against simulated Stripe/PayPal
php tests/whatsapp-rules-test.php     # WhatsApp: numbers, signatures, message parsing, "tomorrow 2pm"
php tests/whatsapp-flow-test.php      # whole booking / cancel / change conversations
php tests/whatsapp-webhook-test.php   # the WhatsApp class against simulated Twilio and Meta
php tests/settings-whatsapp-test.php  # WhatsApp settings: cleaning, kept keys
php tests/demo-test.php               # demo data plan
# dashboard-test.php also covers cancel/change rules and the daily report
```

Browser tests of the form (`e2e.js`), dashboard (`dashboard-e2e.js`), chat (`chat-e2e.js`), demo and the WhatsApp settings (`whatsapp-admin-e2e.js`) are in `tests/e2e/` (see its README).

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
