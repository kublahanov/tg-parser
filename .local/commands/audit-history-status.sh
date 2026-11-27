#!/bin/bash

# Текущий прогресс сверки и число пропусков среди ранее сохранённой истории.

set -e
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

docker compose -f docker-compose-web.yml exec -T parser php scripts/audit-history-status.php
