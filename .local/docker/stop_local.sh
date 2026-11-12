#!/bin/bash

echo "Скрипт устарел!"
exit

# Скрипт для остановки стека

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit
echo "🚀  Running from: $(pwd)"
docker compose down && echo "✅ Success!" || echo "❌ Failed!"
