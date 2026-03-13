#!/bin/bash

# Информация о медиа с ID=1
docker compose exec parser php scripts/show-media.php 1 info

# Получить полный путь к файлу
docker compose exec parser php scripts/show-media.php 1 path

# Открыть файл (на хосте)
docker compose exec parser php scripts/show-media.php 1 open
