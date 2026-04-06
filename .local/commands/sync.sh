#!/bin/bash

# Синхронизация сообщений в чатах (обновление, загрузка новых)

# По умолчанию: новые сообщения, без лимита, все чаты
#docker compose exec parser php -d memory_limit=1024M scripts/sync.php

# Загрузить старые сообщения для конкретного чата, лимит 5000
docker compose exec parser php -d memory_limit=1024M scripts/sync.php --chat=-1001432413295
