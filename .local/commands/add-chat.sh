#!/bin/bash

# Добавление нового чата: bash .local/commands/add-chat.sh <username, ссылка или ID>

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec parser php scripts/add-chat.php "$@"
