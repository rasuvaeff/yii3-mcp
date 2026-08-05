#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

# No PHP on the host — run the reflection pass in the composer:2 image, with
# the whole monorepo mounted so the sibling-resolution in reflect-api.php can
# see the bridge packages at ../<package> (monorepo layout).
docker run --rm -v "$(cd ../../.. && pwd)":/repo -w /repo/yii3-mcp/docs/scripts \
    composer:2 php reflect-api.php > api-snapshot.json
