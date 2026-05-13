#!/bin/bash

# Синхронизация тем для форумных чатов

# Синхронизировать темы для всех форумных чатов
docker compose exec parser php scripts/sync-topics.php

# Синхронизировать темы для конкретного чата
# docker compose exec parser php scripts/sync-topics.php --chat=-1001432413295
