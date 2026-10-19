#!/bin/sh
# Code coverage for the README's badge. Runs inside the FrontAccounting CI
# image like tools/ci.sh, but only the in-process suites (unit, integration):
# the http suite's requests run in Apache, where nothing is measured. Writes
# build/coverage.xml (Clover) for Codecov, and a text summary. Arguments go to
# phpunit.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
export FA_MCP_URL="${FA_URL%/}/modules/mcp/"
export FA_DB_DSN="mysql:host=${FA_DB_HOST};dbname=${FA_DB_NAME};charset=utf8mb4"

echo "==> config"
sh tools/init.sh

echo "==> phpunit with coverage"
# The image has xdebug installed but not enabled; it is loaded for phpunit
# alone. An extra ini scan directory, rather than php -d, reaches the tests
# phpunit runs in separate processes (composer inherits it, hence
# COMPOSER_ALLOW_XDEBUG). Develop mode too, because FrontAccounting's
# db_profile() calls xdebug_call_file() whenever xdebug is loaded, which
# without it warns on every query: enough stderr to block an isolated test.
ini="$(mktemp -d)"
trap 'rm -rf "$ini"' EXIT
echo 'zend_extension=xdebug' > "$ini/xdebug.ini"
rm -f "$FA_ROOT/tmp/faillog.php"
COMPOSER_ALLOW_XDEBUG=1 COMPOSER_PROCESS_TIMEOUT=0 \
    PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$ini" XDEBUG_MODE=coverage,develop \
    composer run test:coverage -- --testsuite unit,integration "$@"
