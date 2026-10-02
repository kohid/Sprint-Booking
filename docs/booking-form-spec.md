# Booking form — design spec (v0.1)

Replaces the booking form on inverness-taxis.com. Flow modelled on the Airport Taxis
Inverness booking process, with the additions requested for Inverness Taxis.

## Flow

1. **Journey Details**
   - Service (a select). **Airport Transfer** adds a Departure / Arrival select; choosing one puts
     Inverness Airport in the drop-off (departure) or pickup (arrival).
   - Pickup, up to 5 via stops, drop-off, with address suggestions while typing. **Add a via stop**
     sits on the route line between the pickup and the drop-off.
   - Date and time (flatpickr, styled after Metronic 8), optional return, and an optional
     "Vulnerable solo traveller" box with a Type select (Lone Female, Minor under 16, Disabled,
     Senior Citizen, Other).
   - "Calculate fare" asks the server for the route and price.
2. **Choose Your Car** — passengers, suitcases (paid beyond the free allowance), carry-on (free),
   then car cards with a picture and a price each. Cars that cannot seat the party or hold the bags
   are disabled.
3. **Passenger Details** — review card; radio choice of **Book as Guest**, **Register to manage your
   bookings on the go!** or **Sign in to book with your saved details**; title, name, email, mobile;
   password (register / sign in); flight number (Airport Transfer only); company (Corporate only);
   special instructions; consent.

Passengers and bags sit in step 2 rather than step 3 so that car availability and price
reflect them before the customer chooses.

### Accounts

- **Guest** — details are used for this booking only.
- **Register** — creates a low-privilege `sb_customer` account at booking time and signs the customer in.
- **Sign in** — email and password; only `sb_customer` accounts can sign in here (staff use wp-login.php,
  so any 2FA there cannot be bypassed). Name and mobile can be left blank to use the saved ones.
  Attempts are rate limited and failures never say whether the email exists.
- A signed-in customer books against their account without being asked again. `[sprint_my_bookings]` lists their bookings.

### Address suggestions

The browser asks the plugin (`/geocode`), which asks a Photon-compatible service, caches results and
rate limits. Nothing is searched under 3 characters, typing is debounced (280 ms) and stale requests are
cancelled. The public OpenStreetMap Nominatim service is not used because its policy forbids autocomplete.

## Services

Airport Transfer, Corporate Service, Golf Transfer, Wedding Cars, Minibus Service, Inverness Tours.
Wedding Cars and Inverness Tours are quote-only (no fare shown; the booking is stored as
`quote_requested`). Minibus Service lists minibuses only. Quote-only is a setting per service.

## Pricing (temporary tariff — Taxi Bookings → Settings)

```
fare      = (starting fee + miles x price per mile) x car multiplier, raised to the minimum fare
journey   = fare + via stops x via fee
return    = journey x (1 - return discount)
luggage   = (suitcases - free allowance) x fee per suitcase   (charged once)
total     = journey + return + luggage
```

Distance is the driving distance from pickup through every via stop to drop-off, from an
OSRM-compatible router. If the router is unreachable the server falls back to straight-line
distance x 1.3 and flags the fare as an estimate. The price is always recomputed on the server
when a booking is created; the browser's figure is never trusted.

## Privacy

The "vulnerable solo traveller" type (for example disabled, or a minor) is sensitive personal data. It is optional, stored
with the booking, shown to the dispatcher and in the booking emails, and covered by the consent tick. Add it to the
site's privacy policy and set a retention period before launch.

## Design

- Brand red `#E20A17` is the only accent. Ink `#101820`, slate `#5B6775`, mist `#F2F4F6`, line `#DCE1E6`.
- Controls follow Metronic 8's scale (radius .475rem, solid 1px borders, 600-weight labels).
  The plugin ships its own CSS, scoped under `.sb-app`; it does not bundle Metronic.
- Signature: the route is drawn as a dotted red line joining numbered stops, with the leg
  distance between each pair, mirrored by the same line on the map.
- Fonts inherit from the host theme. No third-party font requests.

## Not built yet

- Online payment (card/PayPal) and payment links. Everything is currently "pay the driver".
- Discount codes, password reset inside the form (use the normal WordPress "Lost your password"), editing or cancelling a booking from "My bookings".
- Editing car labels, seats and bags in settings (only the price multiplier and the photo are editable).
- Metronic-style dashboard, driver assignment, ElevenLabs phone booking.
- Production map services: the OSM tile server, the public Photon server and the OSRM demo server are for
  testing. Use commercial or self-hosted services before launch.
