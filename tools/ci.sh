#!/bin/sh
# mcp's CI. Runs inside the FrontAccounting CI image (docker/ci in
# cambell-prince/frontaccounting), from this module's directory, on the demo
# dataset, with graphql and sgw_sales activated alongside: lint, coding
# standard and analysis, then the unit, integration and http suites.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci/plugin-test.sh)}"
# The http suite's endpoint, and PdoStoreTest's database (the image's own
# FA_DB_USER and FA_DB_PASSWORD are already in the environment).
export FA_MCP_URL="${FA_URL%/}/modules/mcp/"
export FA_DB_DSN="mysql:host=${FA_DB_HOST};dbname=${FA_DB_NAME};charset=utf8mb4"

echo "==> quality"
composer run quality

echo "==> config"
sh tools/init.sh

echo "==> phpunit"
composer run test -- --fail-on-skipped
echo "==> all checks passed"
