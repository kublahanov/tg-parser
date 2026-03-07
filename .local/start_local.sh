#!/bin/bash

# Скрипт для запуска стека в фоновом режиме

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit
echo "🚀  Running from: $(pwd)"
docker compose up -d && echo "✅ Success!" || echo "❌ Failed!"
