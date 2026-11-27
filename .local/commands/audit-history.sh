#!/bin/bash

# Сверка истории Telegram с БД без загрузки медиа.
# Все чаты: bash .local/commands/audit-history.sh
# Пробный запуск: bash .local/commands/audit-history.sh --max-pages=5
# Один чат: bash .local/commands/audit-history.sh --chat=-1001432413295

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

exec 9>/tmp/tg-parser-sync.lock

if ! flock -n 9; then
    echo "Синхронизация или сверка уже выполняется."
    exit 1
fi

docker compose -f docker-compose-web.yml exec -T parser php scripts/audit-history.php "$@"
