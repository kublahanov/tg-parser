#!/bin/bash

# Синхронизация сообщений в чатах (обновление, загрузка новых)

# По умолчанию: новые сообщения, без лимита, все чаты
docker compose exec parser php -d memory_limit=1024M scripts/sync.php

# Загрузить только старые сообщения для всех чатов (без лимита)
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php --mode=old

# Загрузить старые сообщения для конкретного чата, лимит 5000
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php --mode=old --limit=5000 --chat=-1001269953813

# Загрузить новые сообщения для конкретного чата
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php --mode=new --chat=-1001432413295

# Загрузить старые сообщения, ограничившись 10000 сообщений
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php --mode=old --limit=10000
