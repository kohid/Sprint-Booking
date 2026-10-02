# Booking form — design spec (v0.1)

Replaces the booking form on inverness-taxis.com. Flow modelled on the Airport Taxis
Inverness booking process, with the additions requested for Inverness Taxis.

## Flow

1. **Journey Details** — service, pickup, up to 5 via stops, drop-off, date and time, optional return.
   "Calculate fare" asks the server for the route and price.
2. **Choose Your Car** — passengers, suitcases (paid beyond the free allowance), carry-on (free),
   then car cards with a price each. Cars that cannot seat the party or hold the bags are disabled.
3. **Passenger Details** — review card, title, name, email, mobile, full pickup and drop-off
   address, flight number (Airport Transfer), company (Corporate), special instructions, consent.

Passengers and bags sit in step 2 rather than step 3 so that car availability and price
reflect them before the customer chooses.

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

## Design

- Brand red `#E20A17` is the only accent. Ink `#101820`, slate `#5B6775`, mist `#F2F4F6`, line `#DCE1E6`.
- Controls follow Metronic 8's scale (radius .475rem, solid 1px borders, 600-weight labels).
  The plugin ships its own CSS, scoped under `.sb-app`; it does not bundle Metronic.
- Signature: the route is drawn as a dotted red line joining numbered stops, with the leg
  distance between each pair, mirrored by the same line on the map.
- Fonts inherit from the host theme. No third-party font requests.

## Not built yet

- Online payment (card/PayPal) and payment links. Everything is currently "pay the driver".
- Customer accounts (register / sign in) and discount codes.
- Car photos (the reference shows them); add an image per car in settings.
- Editing car labels, seats and bags in settings (only the price multiplier is editable).
- Metronic-style dashboard, driver assignment, ElevenLabs phone booking.
- Production map services: the OSM tile server, Nominatim and the OSRM demo server are for
  testing. Use commercial or self-hosted services before launch.
