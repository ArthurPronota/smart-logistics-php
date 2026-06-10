# Базовый образ: официальный образ PHP 8.3 в версии CLI (без веб-сервера)
#   базовый образ, на основе которого строится текущий образ
FROM php:8.3-cli

# Устанавливаем рабочую директорию внутри контейнера
WORKDIR /app

# Устанавливаем переменные окружения для Composer:
#    - COMPOSER_IPRESOLVE=4 - форсирует IPv4 (решает проблемы с DNS)
#    - COMPOSER_PROCESS_TIMEOUT=600 - таймаут процессов Composer (600 сек)
ENV COMPOSER_IPRESOLVE=4 \
    COMPOSER_PROCESS_TIMEOUT=600

# Выполняем команды в контейнере (обновление пакетов, установка зависимостей, очистка)
RUN apt-get update \
    # Устанавливаем необходимые системные пакеты (git, unzip, расширения PostgreSQL, регулярные выражения)
    #   Composer использует git для установки пакетов из репозиториев GitHub
    #   libpq-dev — это файлы разработчика для libpq (PostgreSQL)
    #   libonig-dev — это библиотека для работы с регулярными выражениями
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev \
    # Устанавливаем PHP-расширения: PDO, PostgreSQL PDO, сокеты, многобайтовые строки
    #   PDO (PHP Data Objects) — это расширение PHP универсальный интерфейс для работы с различными базами данных
    #   pdo_pgsql - это расширение для PostgreSQL
    #   sockets - это расширение для работаты с сокетами
    #   mbstring  - это расширение для работаты со строками UTF-8
    && docker-php-ext-install pdo pdo_pgsql sockets mbstring \
    # Очищаем кэш пакетов, чтобы уменьшить размер образа
    && rm -rf /var/lib/apt/lists/*

# Копируем исполняемый файл Composer из официального образа Composer в текущий образ
#   Бинарник Composer в образе composer:2 (свежая 2.x.x версия) — это официально упакованный PHAR (PHP Archive) -файл
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Копируем только файл composer.json (для кэширования слоя зависимостей)
#   Это сделано для ускорения посоедующих сборок.
#   После run идён новый слой.
COPY composer.json composer.json

# Устанавливаем зависимости Composer с повторными попытками (до 3) в случае сбоя
#   Устанавливает глобальную настройку Composer (timeout).
RUN composer config --global process-timeout 600 \
    # Цикл из 3 попыток: если успешно — выходим, иначе sleep и повтор
    && for attempt in 1 2 3; do \
        # устанавливает все зависимости проекта
        #   --no-interaction - Нет никаких вопросов
        #   --prefer-dist - Скачивать ZIP-архивы вместо клонирования Git-репозиториев
        #   --optimize-autoloader - оптимизированный автозагрузчик для улучшения производительности в продакшене
        #   break - выход из цикла в случае удачи composer install
        composer install --no-interaction --prefer-dist --optimize-autoloader && break; \
        # Если третья попытка не удалась — выходим с ошибкой
        if [ "$attempt" = "3" ]; then exit 1; fi; \
        sleep 10; \
    done

# Копируем весь остальной код проекта в рабочую директорию
#   Копируется весьпроекткроме того что указанов файле .dockerignore
#   Не копируется: Зависимости (vendor/), секреты (.env), Git-история, ...
#   Внутри контейнера появляется полная копия приложения.
COPY . .

# Заново генерируем оптимизированный автозагрузчик Composer
#   composer install уже создал автозагрузчик но после него было ещё одно копирование
RUN composer dump-autoload --optimize

# Сообщаем Docker, что контейнер будет слушать порт 8080 (документация)
EXPOSE 8080

# Команда по умолчанию: запуск встроенного PHP-сервера Laravel на всех интерфейсах (0.0.0.0), порт 8080
#   php - Исполняемый файл
#   artisan - Консольная утилита Laravel
#   serve - Команда Artisan для запуска встроенного PHP-сервера
#   --host=0.0.0.0 — привязывает сервер ко всем сетевым интерфейсам, без этого 127.0.0.1
#   --port=8080 — использует порт 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
