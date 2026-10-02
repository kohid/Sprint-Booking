# Browser test for the booking form

Drives the real `templates/booking-form.php`, CSS and JS in Chromium, with the REST
endpoints mocked (no WordPress needed). The place names and coordinates in the mock are
fake test data.

```bash
# 1. Build a static page from the real template and copy the assets next to it
mkdir -p /tmp/sb-e2e && cd /tmp/sb-e2e
php /path/to/sprint-booking/tests/e2e/build.php > index.html
cp -r /path/to/sprint-booking/assets/vendor/leaflet .
cp /path/to/sprint-booking/assets/css/booking-form.css /path/to/sprint-booking/assets/js/booking-form.js .

# 2. Serve it, then run the test (needs Playwright: npm i -g playwright)
python3 -m http.server 8123 --bind 127.0.0.1 &
CHROME_PATH=/path/to/chrome NODE_PATH=$(npm root -g) node /path/to/sprint-booking/tests/e2e/e2e.js
```

Screenshots are written to `tests/e2e/out/` (ignored by git).
