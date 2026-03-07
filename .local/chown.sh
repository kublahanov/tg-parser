#!/bin/bash

# Скрипт для изменения владельца и группы всех файлов в проекте на текущего пользователя и группу

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CURRENT_USER=$(whoami)
CURRENT_GROUP=$(id -gn)

echo "Changing ownership of: $PROJECT_DIR."
echo "To: $CURRENT_USER:$CURRENT_GROUP."
echo

# Подтверждение (опционально)
read -p "Continue? (y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Operation cancelled."
    exit 1
fi

sudo chown -R $CURRENT_USER:$CURRENT_GROUP "$PROJECT_DIR" && echo "✅ Done!" || echo "❌ Failed!"
