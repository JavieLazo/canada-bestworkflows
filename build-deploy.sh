#!/usr/bin/env bash
#
# build-deploy.sh — package this app for the cPanel host.
#
# The host has no shell, so nothing is built there. This script builds
# everything here and leaves you two zip files to upload by hand.
#
#   bestworkflows-app.zip     -> extracts into /home/CPANEL_USER/bestworkflows
#   bestworkflows-public.zip  -> extracts into /home/CPANEL_USER/bestworkflows/public
#
# IT NEVER TOUCHES THE SERVER. It only writes those two files.
#
# WHAT IT DOES
#   [1/7] build the front-end assets      [5/7] zip the app
#   [2/7] install production dependencies [6/7] zip the docroot
#   [3/7] check the dependency platform   [7/7] put the dev dependencies back
#   [4/7] clear every local cache         then: verify the zips
#
# USAGE
#   ./build-deploy.sh            zips land in ../deploy-bestworkflows
#   ./build-deploy.sh /some/dir  zips land in /some/dir
#
# BEFORE YOU RUN
#   - Commit first. This packages your WORKING TREE as it is, uncommitted
#     edits and stray files included. It does not package a git commit.
#   - Get `composer test` and `./test-on-target-php.sh` green.
#   - Check the VITE_* values in your local .env are the ones the HOST should
#     get. They are compiled into public/build here and cannot be changed on
#     the server afterwards.
#
# IF IT STOPS PARTWAY
#   Step 2 strips vendor/ down to production-only; step 7 puts the dev tools
#   back. Stopping in between leaves you with no Pest, Pint or Larastan, and
#   `composer test` will fail with an error that points nowhere near here.
#   The fix is `composer install`. The script warns you when this happens.
#
# Run it from the project root.

# Stop at the first failing command, treat unset variables as errors, and let a
# failure anywhere in a pipeline count. There is no cleanup trap — see
# "IF IT STOPS PARTWAY" above; the trap below only warns, it restores nothing.
set -euo pipefail

# Warn if we die between the two composer installs. PRINT ONLY — no cleanup, no
# composer, no rm: what to do about a half-built tree is the operator's call.
# NO_DEV must be assigned before the trap is installed, or an early failure
# makes the trap itself die under `set -u` and destroys the real exit code.
NO_DEV=0
warn_if_vendor_stripped() {
  rc=$?
  if [ "${NO_DEV:-0}" = 1 ]; then
    echo                                                                    >&2
    echo "WARNING: this run stopped while vendor/ was production-only."      >&2
    echo "         Pest, Pint and Larastan are gone. Run: composer install"  >&2
  fi
  return "$rc"
}
trap warn_if_vendor_stripped EXIT

# Running this from the wrong directory would package the wrong tree and write
# the zips somewhere unexpected, and you would not find out until the end.
if [ ! -f artisan ]; then
  echo "STOP: run this from the project root (no ./artisan here)." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Where the output goes
# ---------------------------------------------------------------------------

APP_ZIP='bestworkflows-app.zip'
PUBLIC_ZIP='bestworkflows-public.zip'

# Default: a sibling of the project. It is deliberately OUTSIDE the working
# tree — output written inside the repo would be swept into the next zip, and
# would show up in `git status`. Absolute, because step 6 zips from inside
# public/ and a relative path would resolve under there.
OUT="${1:-$(cd .. && pwd)/deploy-bestworkflows}"

STEPS=7
step() { echo; echo "==> [$1/$STEPS] $2"; }

mkdir -p "$OUT"

# zip APPENDS to an existing archive instead of replacing it. Without this, a
# file you just deleted — or just added to the exclude list — would still ship
# out of the previous build's zip.
rm -f "$OUT/$APP_ZIP" "$OUT/$PUBLIC_ZIP"

# ---------------------------------------------------------------------------
# What must NOT go into the app zip
#
# THIS LIST AND THE CHECKS BELOW IT CHANGE TOGETHER. Add something here that
# must never reach the host, and add a matching check to MUST_NOT_SHIP.
#
# The pairing is deliberately NOT one-to-one: tools/, .github/ and the lint
# configs are excluded with no check, because they are clutter rather than a
# hazard. A missing check is not automatically a bug.
#
# These are ZIP patterns, not shell globs. zip's `*` crosses `/`; the shell's
# does not. They stay single-quoted so the shell never expands them first — an
# unquoted pattern freezes to whatever files happen to exist at that instant.
# ---------------------------------------------------------------------------
EXCLUDE_APP=(
  # -- Secrets and local machine state.
  #    zip does not read .gitignore, so every ignored file has to be repeated
  #    here by hand. Do not delete these as "already ignored": they are
  #    untracked but they are sitting right there on disk, and they would ship.
  '.env'                                     # the real credentials. NEVER ships.
  '.env.*'                                   # .env.example and any local variant
  '.git/*'

  # -- Local-only agent tooling and internal docs.
  #    Both describe the host, the release procedure and this codebase's weak
  #    spots, and none of it is code the app runs. The docs/ runbooks name the
  #    host path and the account user, so they must never reach the server.
  'AGENTS.md'
  'CLAUDE.md'
  'CLAUDE.local.md'
  '*/AGENTS.md'                              # zip's * crosses /, so this catches nested copies
  '*/CLAUDE.md'
  '.claude/*'
  '.agents/*'
  '.codex/*'
  'skills-lock.json'
  '.mcp.json'
  'docs/*'
  'DEPLOY*.md'                               # fallback for a runbook left at the root
  'ARCHITECTURE.md'                          # local-only system notes; gitignored like docs/

  # -- Development-only trees and configs. Nothing here runs in production.
  'node_modules/*'
  'tests/*'
  'tools/*'                                  # TS parity tools; they import from the sibling repo
  '.github/*'
  'phpunit*.xml'
  '.phpunit.result.cache'                    # PHPUnit's local run caches
  '.phpunit.cache/*'
  'TESTING.md'
  'phpstan.neon'
  'pint.json'
  'build-deploy.sh'                          # this script
  'test-on-target-php.sh'
  'database/database.sqlite'                 # starter-kit leftover, never wanted in production
  'storage/framework/testing/*'              # test fixtures. Deliberately the whole directory.

  # -- Uploaded and locally generated files. Restore only the tracked root
  #    placeholders after zipping, so local files never overwrite host data.
  'storage/app/*'

  # -- Runtime cache CONTENTS — and only the contents.
  #    EXCLUDE THE FILES, NEVER THE DIRECTORY. `storage/framework/views/*`
  #    would also drop that directory's .gitignore, and zip stores no entry at
  #    all for a directory whose every member was excluded. The app then dies
  #    on the first request with "Please provide a valid cache path".
  #    Excluding only the cache files, and shipping each .gitignore, keeps the
  #    tree intact. The three shapes below differ because Laravel names files
  #    differently in each directory — do not unify them.
  'storage/logs/*.log'
  'storage/framework/cache/data/[a-z0-9]*'   # lowercase-hex cache buckets
  'storage/framework/sessions/[a-zA-Z0-9]*'  # mixed-case session ids
  'storage/framework/views/*.php'            # compiled Blade
  'bootstrap/cache/*.php'                    # config/route/package caches

  # -- OS noise.
  '.DS_Store'
  '*/.DS_Store'

  # -- Ships in the other zip.
  'public/*'
)

# The docroot zip is small and ships almost everything in public/.
# `storage` appears twice on purpose: public/storage is a local symlink, and
# zip follows symlinks by default. Ship it and the host gets a real directory
# where the symlink belongs, which makes `artisan storage:link` fail there.
EXCLUDE_PUBLIC=(
  '.DS_Store'
  '*/.DS_Store'
  'storage/*'                                # whatever the symlink points at
  'storage'                                  # and the symlink entry itself
  'hot'                                      # local Vite development-server URL
  'fonts-manifest.dev.json'                   # development-only font paths
)

# ---------------------------------------------------------------------------
# What the checks at the end verify
# ---------------------------------------------------------------------------

# Only these tracked placeholders may ship under storage/app/. Explicitly
# adding them keeps both upload roots present without packaging local files.
STORAGE_APP_PLACEHOLDERS=(
  'storage/app/.gitignore'
  'storage/app/private/.gitignore'
  'storage/app/public/.gitignore'
)

MUST_SHIP_PUBLIC=(
  '.htaccess'
  'index.php'
  'build/manifest.json'
)

# Runtime directories that MUST be inside the app zip. One of these going
# missing is the "Please provide a valid cache path" failure described above,
# and this is the check that catches it.
MUST_SHIP_DIRS=(
  'storage/framework/views'
  'storage/framework/sessions'
  'storage/framework/cache/data'
  'storage/logs'
  'bootstrap/cache'
)

# Things that must NOT be inside the app zip. Everything left of :: is the
# label you read in the report; everything right of it is an extended regex
# matched against the archive's file list.
MUST_NOT_SHIP=(
  'the real .env file::\.env'
  'the test suite::^tests/'
  'test database configuration::^phpunit.*\.xml$'
  'a baked config or route cache::bootstrap/cache/.*\.php'
  'the local sqlite database::\.sqlite$'
  'internal docs::^docs/'
  'a stray deploy runbook::^DEPLOY.*\.md'
  'local-only architecture notes::^ARCHITECTURE\.md$'
  'agent instruction files::(^|/)(AGENTS|CLAUDE)[^/]*\.md$'
  'the .claude tooling directory::^\.claude/'
  'the .agents directory::^\.agents/'
  'the .codex directory::^\.codex/'
  'the skills lockfile::^skills-lock\.json$'
  'the MCP server config::^\.mcp\.json$'
  'the docroot, which ships separately::^public/'
)

MUST_NOT_SHIP_PUBLIC=(
  'the local storage link or files::^storage(/|$)'
  'the Vite development-server marker::^hot$'
  'the development font manifest::^fonts-manifest\.dev\.json$'
)

# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

echo "Output directory: $OUT"

step 1 "front-end assets  (npm ci + build - this is the slow one)"
# Must run BEFORE vendor/ is stripped: resources/css/app.css imports Flux's CSS
# out of vendor/. It also means an npm failure aborts while vendor/ is still
# intact and your test suite still works.
npm ci
npm run build

step 2 "production dependencies  (vendor/ goes production-only here)"
NO_DEV=1
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

step 3 "dependency platform check"
# Checks the installed packages against the PHP version pinned in
# composer.json. It is not proof that the host's PHP parses our code —
# ./test-on-target-php.sh and CI are the only things that do that.
composer check-platform-reqs --no-dev

step 4 "clearing local caches"
# MANDATORY. A bootstrap/cache/config.php baked from your local .env points
# production at 127.0.0.1 with the dev APP_KEY and APP_DEBUG=true, and Laravel
# PREFERS that cache over the host's real .env — the single most common cause
# of "it 500s on the host".
php artisan optimize:clear

step 5 "app zip"
# The source argument stays a literal `.` — `*` would be expanded by the shell,
# which skips dotfiles, and every runtime directory's .gitignore would vanish.
zip -rq "$OUT/$APP_ZIP" . -x "${EXCLUDE_APP[@]}"
zip -q "$OUT/$APP_ZIP" "${STORAGE_APP_PLACEHOLDERS[@]}"

step 6 "docroot zip"
# Built from INSIDE public/ so the archive's paths are docroot-relative, with
# no public/ prefix. That is what lets it extract straight into the docroot.
# The subshell keeps the rest of the script at the project root.
( cd public && zip -rq "$OUT/$PUBLIC_ZIP" . -x "${EXCLUDE_PUBLIC[@]}" )

step 7 "restoring dev dependencies"
composer install --no-interaction
NO_DEV=0

# ---------------------------------------------------------------------------
# Verify what we just built
# ---------------------------------------------------------------------------

# Read each archive's file list ONCE; every check below uses these listings.
if ! LISTING=$(unzip -Z1 "$OUT/$APP_ZIP"); then
  echo "STOP: built $APP_ZIP but cannot read it back." >&2
  exit 1
fi
if ! PUBLIC_LISTING=$(unzip -Z1 "$OUT/$PUBLIC_ZIP"); then
  echo "STOP: built $PUBLIC_ZIP but cannot read it back." >&2
  exit 1
fi

# grep exits 1 when nothing matches, which is the NORMAL case for most checks
# here. The `|| true` is what stops `set -e` killing the script on a clean run.
count_matching() { printf '%s\n' "$1" | grep -cE "$2" || true; }

failed=0
checks=0

echo
echo "Built:"
ls -lh "$OUT"/bestworkflows-*.zip
printf '  %s holds %s entries\n' "$APP_ZIP" "$(printf '%s\n' "$LISTING" | wc -l | tr -d ' ')"

echo
echo "Sanity checks:"

echo
echo "  Must be PRESENT — runtime directories:"
for dir in "${MUST_SHIP_DIRS[@]}"; do
  checks=$((checks + 1))
  n=$(count_matching "$LISTING" "^$dir/")
  if [ "$n" -gt 0 ]; then
    printf '    %-38s %s\n' "$dir" 'present OK'
  else
    printf '    %-38s %s\n' "$dir" 'MISSING - app will 500'
    failed=$((failed + 1))
  fi
done

echo
echo "  Must be ABSENT — secrets and local-only files:"
for entry in "${MUST_NOT_SHIP[@]}"; do
  checks=$((checks + 1))
  label="${entry%%::*}"
  regex="${entry#*::}"
  n=$(count_matching "$LISTING" "$regex")
  if [ "$n" -eq 0 ]; then
    printf '    %-38s %s\n' "$label" 'absent OK'
  else
    printf '    %-38s %s\n' "$label" "$n PRESENT - REVIEW"
    printf '%s\n' "$LISTING" | grep -E "$regex" | head -3 | sed 's/^/        /' || true
    failed=$((failed + 1))
  fi
done

echo
echo "  Must be PRESENT — upload placeholders and docroot files:"
for file in "${STORAGE_APP_PLACEHOLDERS[@]}"; do
  checks=$((checks + 1))
  if printf '%s\n' "$LISTING" | grep -Fx "$file" >/dev/null; then
    printf '    %-38s %s\n' "$file" 'present OK'
  else
    printf '    %-38s %s\n' "$file" 'MISSING'
    failed=$((failed + 1))
  fi
done
for file in "${MUST_SHIP_PUBLIC[@]}"; do
  checks=$((checks + 1))
  if printf '%s\n' "$PUBLIC_LISTING" | grep -Fx "$file" >/dev/null; then
    printf '    %-38s %s\n' "$file" 'present OK'
  else
    printf '    %-38s %s\n' "$file" 'MISSING'
    failed=$((failed + 1))
  fi
done

echo
echo "  Must be ABSENT — local storage contents and docroot development files:"
checks=$((checks + 1))
storage_files=$(printf '%s\n' "$LISTING" | grep -E '^storage/app/' | grep -Ev '^storage/app/((private|public)/)?\.gitignore$' || true)
if [ -z "$storage_files" ]; then
  printf '    %-38s %s\n' 'local storage/app files' 'absent OK'
else
  printf '    %-38s %s\n' 'local storage/app files' 'PRESENT - REVIEW'
  printf '%s\n' "$storage_files" | head -3 | sed 's/^/        /' || true
  failed=$((failed + 1))
fi
for entry in "${MUST_NOT_SHIP_PUBLIC[@]}"; do
  checks=$((checks + 1))
  label="${entry%%::*}"
  regex="${entry#*::}"
  n=$(count_matching "$PUBLIC_LISTING" "$regex")
  if [ "$n" -eq 0 ]; then
    printf '    %-38s %s\n' "$label" 'absent OK'
  else
    printf '    %-38s %s\n' "$label" "$n PRESENT - REVIEW"
    printf '%s\n' "$PUBLIC_LISTING" | grep -E "$regex" | head -3 | sed 's/^/        /' || true
    failed=$((failed + 1))
  fi
done

echo
if [ "$failed" -eq 0 ]; then
  echo "  RESULT: all $checks checks passed. The zips are ready to upload."
  echo
  echo "  Next: upload and extract both zips, then run artisan from a temporary"
  echo "  cron. The procedure is in docs/DEPLOY.md. Nothing has touched the"
  echo "  server yet."
else
  echo "  RESULT: $failed of $checks checks FAILED. DO NOT UPLOAD THESE ZIPS."
  echo
  echo "  Each failing line above names what got in. Fix the exclude list in"
  echo "  this script, then run it again."
fi
echo

# Refuse a failed package check so callers cannot treat it as a release.
[ "$failed" -eq 0 ]
