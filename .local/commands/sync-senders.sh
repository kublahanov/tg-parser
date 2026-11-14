#!/bin/bash

# Ручное заполнение имён авторов для ранее сохранённых сообщений.
# Все чаты: bash .local/commands/sync-senders.sh
# Один чат: bash .local/commands/sync-senders.sh --chat=-1001432413295
# Пробный запуск: bash .local/commands/sync-senders.sh --max-pages=1

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec parser php scripts/sync-senders.php "$@"
