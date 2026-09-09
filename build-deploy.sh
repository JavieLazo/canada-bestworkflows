#!/usr/bin/env bash
#
# Package the app for cPanel. Produces two zips, one per destination:
#
#   bestworkflows-app.zip     -> extracts into /home/CPANEL_USER/bestworkflows
#   bestworkflows-public.zip  -> extracts into /home/CPANEL_USER/bestworkflows/public
#
# Run from the project root:  ./build-deploy.sh
#
# Leaves the working tree back in DEV state (dev dependencies restored) so the
# test suite still runs afterwards.
set -euo pipefail

OUT="${1:-$(cd .. && pwd)/deploy-bestworkflows}"
mkdir -p "$OUT"
rm -f "$OUT/bestworkflows-app.zip" "$OUT/bestworkflows-public.zip"

echo "==> front-end assets"
npm ci
npm run build

echo "==> production dependencies"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

echo "==> platform gate (must pass, or the host will fatal at autoload time)"
composer check-platform-reqs --no-dev

# MANDATORY. A config.php baked from the local .env points production at
# 127.0.0.1 with the dev APP_KEY and APP_DEBUG=true, and Laravel PREFERS the
# cache over the real .env — the single most common "500s on the host" cause.
echo "==> clearing caches"
php artisan optimize:clear

echo "==> app zip"
# Exclude the CONTENTS of the runtime storage dirs but KEEP the directories.
# Excluding 'storage/framework/views/*' also removes its .gitignore, leaving the
# directory empty — and zip does not store an entry for a directory whose every
# member was excluded. The app then dies on the first request with
# "Please provide a valid cache path". Excluding only the cache FILES, and
# shipping each .gitignore, keeps the tree intact.
#
# database.sqlite: starter-kit leftover, never wanted in production.
# storage/framework/testing: test fixtures, must not reach a production host.
# docs/: internal documentation, local only. It is gitignored, but zip does not
#   read .gitignore — it must be excluded here too. The runbooks in it name the
#   host path and the SSH user, so they must never reach the server.
#   DEPLOY*.md is kept as a fallback for a stray runbook left at the root.
# public/*: ships in the other zip.
zip -rq "$OUT/bestworkflows-app.zip" . \
  -x '.git/*' '.env' '.env.*' 'node_modules/*' 'tests/*' 'tools/*' '.github/*' \
     'public/*' \
     'storage/logs/*.log' \
     'storage/framework/cache/data/[a-z0-9]*' \
     'storage/framework/sessions/[a-zA-Z0-9]*' \
     'storage/framework/views/*.php' \
     'storage/framework/testing/*' \
     'bootstrap/cache/*.php' '.DS_Store' '*/.DS_Store' \
     'phpunit.xml' 'phpstan.neon' 'pint.json' 'build-deploy.sh' \
     'test-on-target-php.sh' 'docs/*' 'DEPLOY*.md' \
     'database/database.sqlite'

echo "==> docroot zip"
# storage/ is EXCLUDED: it is a symlink locally, and zip would follow it into a
# real directory that then makes `artisan storage:link` fail on the host.
( cd public && zip -rq "$OUT/bestworkflows-public.zip" . -x '.DS_Store' '*/.DS_Store' 'storage/*' 'storage' )

echo "==> restoring dev dependencies"
composer install --no-interaction

echo
echo "Built:"
ls -lh "$OUT"/bestworkflows-*.zip
echo
echo "Sanity checks:"
for d in storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs bootstrap/cache; do
  n=$(unzip -Z1 "$OUT/bestworkflows-app.zip" | grep -c "^$d/" || true)
  printf '  %-34s %s\n' "$d" "$([ "$n" -gt 0 ] && echo 'present OK' || echo 'MISSING - app will 500')"
done
for pat in '\.env' '^tests/' 'bootstrap/cache/.*\.php' '\.sqlite$' '^docs/' '^DEPLOY.*\.md'; do
  n=$(unzip -Z1 "$OUT/bestworkflows-app.zip" | grep -cE "$pat" || true)
  printf '  %-28s %s\n' "$pat" "$([ "$n" -eq 0 ] && echo 'absent OK' || echo "$n PRESENT - REVIEW")"
done
