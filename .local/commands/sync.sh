#!/bin/bash

# Синхронизация сообщений в чатах (обновление, загрузка новых)

docker compose exec parser php -d memory_limit=1024M scripts/sync.php
