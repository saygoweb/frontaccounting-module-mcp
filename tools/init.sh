#!/bin/sh
# Prepares this module in the FrontAccounting CI image after activation:
#  - config_mcp.php for the image (base_url from FA_URL, plain HTTP allowed,
#    claude.ai as the one other origin): never for production. Rewritten on
#    each run while it is the one this script wrote; a hand-written one is kept.
#  - the GraphQL module's config_graphql.php, by its own tools/init.sh, when
#    graphql has none yet.
# Run by tools/ci.sh, and by the CI package's development environments after
# they activate this module.
set -eu
: "${FA_ROOT:?run this inside the FrontAccounting CI image (docker/ci)}"
: "${FA_URL:?run this inside the FrontAccounting CI image (docker/ci)}"
here="$(cd "$(dirname "$0")/.." && pwd)"
marker='Written by tools/init.sh'

if [ ! -f "$here/config_mcp.php" ] || grep -q "$marker" "$here/config_mcp.php"; then
    printf "<?php\n\n/* %s for the CI image. Not for production:\n    see config_mcp.example.php. */\n\nreturn [\n    'base_url' => '%s',\n    'require_tls' => false,\n    'allowed_origins' => ['https://claude.ai'],\n    'debug' => true,\n];\n" \
        "$marker" "${FA_URL%/}/modules/mcp" > "$here/config_mcp.php"
    echo "wrote config_mcp.php (base_url ${FA_URL%/}/modules/mcp)"
fi

graphql="$here/../graphql"
if [ ! -f "$graphql/config_graphql.php" ]; then
    (cd "$graphql" && sh tools/init.sh)
fi
