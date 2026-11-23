#!/bin/bash

# Без аргументов показывает число дат с отклонением.
# Для исправления: bash .local/commands/fix-message-timestamps.sh --apply

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec -T parser php scripts/fix-message-timestamps.php "$@"
