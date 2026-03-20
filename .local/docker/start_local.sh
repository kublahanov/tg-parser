#!/bin/bash

# Скрипт для запуска стека в фоновом режиме

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit
echo "🚀  Running from: $(pwd)"

# Функция проверки свободного порта
is_port_free() {
    ! ss -tuln | grep -q ":$1 "
}

# Поиск свободного порта начиная с 8080
PORT=8080
while ! is_port_free $PORT; do
    echo "Порт $PORT занят, пробуем $((PORT+1))..."
    ((PORT++))
done

echo "Используем порт: $PORT"

# Запускаем docker-compose с переменной окружения
WEB_PORT=$PORT docker compose up -d && echo "✅ Success!" || echo "❌ Failed!"

echo "Web интерфейс доступен на http://localhost:$PORT"
