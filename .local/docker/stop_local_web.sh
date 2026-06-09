#!/bin/bash

# Скрипт для остановки стека

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit
echo "🚀  Running from: $(pwd)"
docker compose -f ../docker-compose-web.yml down && echo "✅ Success!" || echo "❌ Failed!"
