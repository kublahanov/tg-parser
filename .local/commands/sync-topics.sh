#!/bin/bash

# Синхронизация тем для форумных чатов

# Синхронизировать темы для всех форумных чатов
# Один чат: bash .local/commands/sync-topics.sh --chat=-1001432413295

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec -T parser php scripts/sync-topics.php "$@"
