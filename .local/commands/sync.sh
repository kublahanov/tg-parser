#!/bin/bash

# Синхронизация сообщений в чатах

# По умолчанию: все чаты, сообщения с лимитом по-умолчанию
docker compose exec parser php -d memory_limit=1024M scripts/sync.php

# Конкретный чат, сообщения с лимитом по-умолчанию
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php --chat=-1001432413295

# Конкретный чат, сообщения с лимитом 10000
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php --chat=-1001432413295 --limit=10000
