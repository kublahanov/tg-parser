#!/bin/bash

# Пример: bash .local/commands/retry-media.sh --limit=100 --after-id=0

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec -T parser php scripts/retry-media.php "$@"
