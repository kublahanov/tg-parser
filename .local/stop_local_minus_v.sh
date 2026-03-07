#!/bin/bash

# Скрипт для остановки стека с полным удалением данных (томов, образов, кеша)

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit
echo "🚀  Running from: $(pwd)"
docker compose down -v --rmi all --remove-orphans
docker builder prune -f
