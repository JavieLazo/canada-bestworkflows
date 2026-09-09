#!/usr/bin/env bash
#
# Run the test suite against the PHP version PRODUCTION runs, not the one this
# machine happens to have.
#
# Why this exists: composer.json pins config.platform.php to the host's version,
# but that pin only governs which PACKAGE versions Composer resolves. It does
# nothing about how PHP parses OUR OWN code. A trait property collision that is
# a fatal on 8.4 and legal on 8.5 shipped to production precisely because the
# suite only ever ran on the developer's 8.5.
#
# Run this before packaging a deploy.
#
#   ./test-on-target-php.sh
#
set -euo pipefail

# Keep in step with config.platform.php in composer.json.
TARGET_MINOR="8.4"

BIN="$(brew --prefix "php@${TARGET_MINOR}" 2>/dev/null || true)/bin/php"

if [ ! -x "$BIN" ]; then
  echo "PHP ${TARGET_MINOR} not found."
  echo "Install it with:  brew install php@${TARGET_MINOR}"
  exit 1
fi

echo "==> $("$BIN" -v | head -1)"
echo

# A parse-time incompatibility is a FATAL, not an exception, so it can hide from
# a suite that never loads the offending class. Lint everything first.
echo "==> linting every source file on PHP ${TARGET_MINOR}"
fail=0
while IFS= read -r f; do
  "$BIN" -l "$f" > /dev/null 2>&1 || { echo "  SYNTAX: $f"; fail=1; }
done < <(find app database routes config tests -name '*.php')
[ "$fail" -eq 0 ] && echo "  all files parse" || { echo "  lint failed"; exit 1; }
echo

echo "==> test suite on PHP ${TARGET_MINOR}"
"$BIN" ./vendor/bin/pest "$@"
