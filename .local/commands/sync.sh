#!/bin/bash

# Синхронизация сообщений в чатах (обновление, загрузка новых)

docker compose exec parser php scripts/sync.php
