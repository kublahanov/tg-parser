#!/bin/bash

# Информация о медиа: bash .local/commands/show-media.sh <ID> [info|path]

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec -T parser php scripts/show-media.php "$@"
