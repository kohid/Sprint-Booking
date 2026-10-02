#!/usr/bin/env bash
# Builds dist/sprint-booking.zip from the committed files (HEAD), with the plugin in a
# top-level "sprint-booking/" folder so WordPress installs and updates it in place.
#
# Usage: bin/build-release.sh
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
	echo "Uncommitted changes: the zip is built from HEAD, so commit first." >&2
	exit 1
fi

version=$(sed -nE 's/^ \* Version:[[:space:]]*([0-9][^[:space:]]*).*/\1/p' sprint-booking.php | head -1)
const=$(sed -nE "s/.*define\( 'SB_VERSION', *'([^']+)'.*/\1/p" sprint-booking.php | head -1)
if [ -z "$version" ] || [ "$version" != "$const" ]; then
	echo "Version mismatch: header '$version' vs SB_VERSION '$const'." >&2
	exit 1
fi

echo "Checking PHP and running tests..."
for f in sprint-booking.php includes/*.php templates/*.php; do php -l "$f" >/dev/null; done
php tests/pricing-test.php >/dev/null
php tests/rest-validation-test.php >/dev/null
php tests/updater-test.php >/dev/null

mkdir -p dist
rm -f dist/sprint-booking.zip
git archive --format=zip --prefix=sprint-booking/ -o dist/sprint-booking.zip HEAD

# The zip must carry everything the plugin needs at runtime.
for must in sprint-booking/sprint-booking.php sprint-booking/assets/vendor/leaflet/leaflet.js sprint-booking/assets/js/booking-form.js sprint-booking/templates/booking-form.php; do
	unzip -l dist/sprint-booking.zip | grep -q " $must\$" || { echo "Missing from zip: $must" >&2; exit 1; }
done

echo "Built dist/sprint-booking.zip (version $version, $(du -h dist/sprint-booking.zip | cut -f1))"
