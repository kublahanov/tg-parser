#!/bin/bash

# Синхронизация сообщений, авторов и тем форумов

# По умолчанию синхронизируются все активные чаты.
# Один чат: bash .local/commands/sync.sh --chat=-1001432413295

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec -T parser php -d memory_limit=1024M scripts/sync.php "$@"
