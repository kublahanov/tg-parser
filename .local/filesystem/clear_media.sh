#!/bin/bash

# Очистка папки media

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MEDIA_DIR="$PROJECT_DIR/media"

echo "🔧 Clearing media directory: $MEDIA_DIR"
echo "📁 Project root: $PROJECT_DIR"
echo "👤 Current user: $(whoami):$(id -gn)"

# Проверка существования папки media
if [[ ! -d "$MEDIA_DIR" ]]; then
    echo "❌ Directory $MEDIA_DIR does not exist."
    exit 1
fi

# Вывод содержимого для подтверждения
echo
echo "📦 Contents of $MEDIA_DIR:"
find "$MEDIA_DIR" -mindepth 1 -exec basename {} \; | sed 's/^/   📄 /'
echo

# Подтверждение удаления
read -p "⚠️  Continue? (y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "👋 Cancelled by user."
    exit 0
fi

# Смена владельца на текущего пользователя
echo "🔄 Changing ownership of $MEDIA_DIR to $(whoami):$(id -gn)..."
sudo chown -R "$(whoami):$(id -gn)" "$MEDIA_DIR" || { echo "❌ Failed to change ownership."; exit 1; }

# Удаление всего, кроме .gitkeep
echo "🗑️ Removing all files and directories except .gitkeep..."
find "$MEDIA_DIR" -mindepth 1 ! -name '.gitkeep' -exec rm -rf {} + && echo "✅ Cleared!" || { echo "❌ Failed to remove files."; exit 1; }

echo "✨ Media directory cleaned successfully."
