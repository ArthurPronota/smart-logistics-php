# Сервис уведомдений: документация

[Тестовое задание](./Тестовое%20задание%20на%20бэкенд%20разработчика%20Умная%20логистика.pdf)

## 1. Сборка и запуск проекта

Перейти в корневой директорий проекта.

Сбока и запуск проекта
```bash
docker compose up --build
```

Только сбока проекта
```bash
docker compose build
```

Только запуск проекта
```
docker compose up
```

Запуск проекта из произвольного директория
```
docker compose -f C:\Users\user\work\PHP\PhpTest\docker-compose.yml up
```
Где `C:\Users\user\work\PHP\PhpTest\docker-compose.yml` - путь к конфигурационному файлу, для запуска многоконтейнерного приложения.

## 2. API для тестирования

Базовый URL при запуске через Docker Compose:

```text
http://localhost:8080
```

### 2.1. Интерактивная Swagger-документация

[Ссылка на Swagger (OpenAPI)](http://localhost:8080/swagger.html)

```text
http://localhost:8080/swagger.html
```

Эта страница показывает все основные API-методы проекта:

- `GET /health`
- `POST /notifications/bulk`
- `GET /subscribers/{recipientId}/notifications`

На странице Swagger UI можно раскрыть метод, посмотреть параметры, JSON-схемы запросов и ответов, нажать `Try it out` и выполнить запрос прямо из браузера.

Файлы документации:

- `docs/openapi.yaml` - основной OpenAPI-файл для хранения и редактирования спецификации.
- `public/openapi.yaml` - копия OpenAPI-файла, доступная браузеру по URL `http://localhost:8080/openapi.yaml`.
- `public/swagger.html` - статическая страница Swagger UI.

Важно: `public/swagger.html` загружает CSS и JavaScript Swagger UI через CDN `unpkg.com`, поэтому для отображения интерфейса Swagger браузеру нужен доступ к интернету. Сам API при этом работает локально на `http://localhost:8080`.

### 2.2. Проверка работоспособности

```http
GET /health
```

Пример вызова в `cmd.exe`:

```cmd
curl http://localhost:8080/health
```

Успешный ответ:

```json
{
    "status": "ok"
}
```

Поля ответа:

- `status` - строка состояния сервиса. Значение `ok` означает, что HTTP-приложение запущено и отвечает.

### 2.3. Запуск массовой рассылки

```http
POST /notifications/bulk
Content-Type: application/json
```

Тело запроса:

```json
{
    "idempotency_key": "test-1",
    "channel": "sms",
    "priority": "transactional",
    "message": "Route changed",
    "recipient_ids": ["79001112233"]
}
```

Поля запроса:

- `idempotency_key` - обязательная строка. Уникальный ключ запроса, защищает от повторного создания одной и той же рассылки.
- `channel` - обязательная строка. Канал доставки. Допустимые значения: `sms`, `email`.
- `priority` - строка. Тип срочности рассылки. Если поле не передано, используется `normal`.
- `message` - обязательная строка. Текст сообщения.
- `recipient_ids` - обязательный массив строк. Идентификаторы получателей. Для SMS это может быть номер телефона, для Email - email-адрес.

Допустимые значения `priority`:

- `transactional` - самый высокий приоритет, используется для критичных сообщений: коды доступа, срочные изменения маршрутов.
- `normal` - обычный приоритет.
- `marketing` - низкий приоритет, используется для маркетинговых рассылок.

Внутреннее соответствие приоритетов RabbitMQ:

```text
transactional => 10
normal        => 5
marketing     => 1
```

Пример SMS-рассылки в `cmd.exe`:

```cmd
curl -X POST http://localhost:8080/notifications/bulk -H "Content-Type: application/json" -d "{\"idempotency_key\":\"sms-test-1\",\"channel\":\"sms\",\"priority\":\"transactional\",\"message\":\"Route changed\",\"recipient_ids\":[\"79001112233\",\"79004445566\"]}"
```

Пример Email-рассылки в `cmd.exe`:

```cmd
curl -X POST http://localhost:8080/notifications/bulk -H "Content-Type: application/json" -d "{\"idempotency_key\":\"email-test-1\",\"channel\":\"email\",\"priority\":\"marketing\",\"message\":\"Hello from notification service\",\"recipient_ids\":[\"client1@example.com\",\"client2@example.com\"]}"
```

Успешный ответ при новом запросе:

```json
{
    "batch_id": "0b06a888-a936-45d5-b2bf-9b1b6af38fbf",
    "notification_ids": [
        "db6f6cd2-86ce-4f7a-97a6-c3767917ec78"
    ],
    "duplicate": false
}
```

HTTP-статус:

- `202 Accepted` - новый batch создан, уведомления поставлены в очередь.
- `200 OK` - найден существующий batch с таким же `idempotency_key`, повторная публикация в очередь не выполнялась.
- `422 Unprocessable Entity` - ошибка валидации запроса.
- `429 Too Many Requests` - превышен лимит отправки для канала/получателя.
- `500 Internal Server Error` - внутренняя ошибка сервиса.

Поля ответа:

- `batch_id` - UUID созданной или найденной массовой рассылки.
- `notification_ids` - массив UUID уведомлений, созданных в рамках batch.
- `duplicate` - признак дедубликации. `false` означает новый запрос, `true` означает повторный запрос с уже использованным `idempotency_key`.

Пример ответа для повторного `idempotency_key`:

```json
{
    "batch_id": "0b06a888-a936-45d5-b2bf-9b1b6af38fbf",
    "notification_ids": [
        "db6f6cd2-86ce-4f7a-97a6-c3767917ec78"
    ],
    "duplicate": true
}
```

### 2.4. История и текущий статус уведомлений подписчика

```http
GET /subscribers/{recipientId}/notifications
```

Endpoint возвращает все уведомления конкретного подписчика, включая текущий статус каждого уведомления.

Пример для SMS-получателя:

```cmd
curl http://localhost:8080/subscribers/79001112233/notifications
```

Пример для Email-получателя:

```cmd
curl http://localhost:8080/subscribers/client1@example.com/notifications
```

Успешный ответ:

```json
{
    "items": [
        {
            "id": "db6f6cd2-86ce-4f7a-97a6-c3767917ec78",
            "batch_id": "0b06a888-a936-45d5-b2bf-9b1b6af38fbf",
            "recipient_id": "79001112233",
            "channel": "sms",
            "priority": "transactional",
            "message": "Route changed",
            "status": "delivered",
            "attempts": 1,
            "provider_message_id": "sms_ab12cd34ef56",
            "error": null,
            "created_at": "2026-06-09 10:00:00+00",
            "updated_at": "2026-06-09 10:00:03+00"
        }
    ]
}
```

Поля ответа:

- `items` - массив уведомлений подписчика.
- `id` - UUID конкретного уведомления.
- `batch_id` - UUID batch, в рамках которого создано уведомление.
- `recipient_id` - идентификатор подписчика.
- `channel` - канал доставки: `sms` или `email`.
- `priority` - тип срочности: `transactional`, `normal`, `marketing`.
- `message` - текст сообщения.
- `status` - текущий статус уведомления.
- `attempts` - количество попыток обработки worker-ом.
- `provider_message_id` - идентификатор сообщения у fake-провайдера. Заполняется после успешной отправки.
- `error` - текст ошибки, если уведомление отброшено.
- `created_at` - дата и время создания уведомления.
- `updated_at` - дата и время последнего изменения уведомления.

Статусы уведомлений:

- `queued` - уведомление принято и ожидает отправки.
- `sent` - уведомление передано шлюзу/провайдеру.
- `delivered` - доставка подтверждена провайдером.
- `dropped` - уведомление отброшено из-за ошибки доставки, невалидного номера/email или исчерпания retry.

### 2.5. Просмотр состояния очередей (RabbitMQ)

Для просмотра откройте в браузере [ссылку](http://localhost:15672/):
```
http://localhost:15672/
```
Логин:
```
guest
```
Пароль:
```
guest
```
Там можно посмотреть очередь notifications.outbound (исходящие уведомления).


## 3. Структура проекта

Ниже описаны основные директории и файлы проекта

```text
Work/
  app/
  bin/
  bootstrap/
  config/
  database/
  docs/
  public/
  routes/
  src/
  storage/
  tests/
  artisan
  composer.json
  Dockerfile
  docker-compose.yml
  phpunit.xml
  README.md
  README2.md
  ReadMe.txt
  Тестовое задание на бэкенд разработчика Умная логистика.pdf
  .dockerignore
```
### Laravel-слой

- `artisan` - консольная точка входа Laravel. Через нее запускается команда worker-а `php artisan notifications:work`.
- `app/Console/Commands/NotificationWorkerCommand.php` - Artisan-команда, которая запускает чтение задач из RabbitMQ и передает их в `ProcessNotificationJob`.
- `app/Http/Controllers/NotificationController.php` - Laravel-контроллер API. Принимает HTTP-запросы и вызывает обработчики приложения.
- `app/Providers/NotificationServiceProvider.php` - связывает старую бизнес-логику с контейнером Laravel: создает `PDO`, Redis-клиент, RabbitMQ-подключение, repository, broker и обработчики приложения.
- `bootstrap/app.php` - конфигурация Laravel-приложения: маршруты, консольные команды, промежуточные обработчики и обработка исключений.
- `bootstrap/providers.php` - список Laravel service providers, которые нужно загрузить при старте приложения.
- `routes/api.php` - HTTP-маршруты API: `/health`, `/notifications/bulk`, `/subscribers/{recipientId}/notifications`.
- `routes/console.php` - дополнительные консольные команды Laravel.
- `public/index.php` - HTTP-точка входа Laravel. Все запросы к API проходят через этот файл.

### Бизнес-логика

- `src/Domain/NotificationChannel.php` - enum каналов доставки: `sms`, `email`.
- `src/Domain/NotificationPriority.php` - enum срочности рассылки и соответствие приоритетам RabbitMQ.
- `src/Domain/NotificationStatus.php` - enum статусов уведомления: `queued`, `sent`, `delivered`, `dropped`.
- `src/Domain/NotificationBatchResult.php` - объект результата создания или повторного получения рассылки.
- `src/Domain/ProviderResult.php` - объект результата отправки через провайдера.
- `src/Domain/NotificationRepository.php` - интерфейс хранилища batch-ов и уведомлений.
- `src/Domain/MessageBroker.php` - интерфейс брокера сообщений.
- `src/Domain/NotificationProvider.php` - интерфейс провайдера отправки SMS/Email.
- `src/Domain/IdempotencyGuard.php` - интерфейс защиты от коротких повторов запроса.
- `src/Domain/RateLimiter.php` - интерфейс проверки лимитов отправки.
- `src/Domain/RateLimitExceeded.php` - исключение при превышении лимита.
- `src/Application/StartBulkNotification.php` - основной сценарий запуска массовой рассылки: проверка входных данных, Redis-дедубликация, лимиты, запись в БД и публикация задач.
- `src/Application/ProcessNotificationJob.php` - сценарий обработки одной задачи воркером: поиск уведомления, вызов провайдера, повторные попытки и обновление статуса.
- `src/Application/GetRecipientNotifications.php` - сценарий чтения истории уведомлений конкретного получателя.

### Инфраструктура

- `src/Infrastructure/Repository/PdoNotificationRepository.php` - PostgreSQL-реализация `NotificationRepository` через PDO.
- `src/Infrastructure/Queue/RabbitMqMessageBroker.php` - RabbitMQ-реализация `MessageBroker`, объявляет очередь `notifications.outbound` и публикует задачи.
- `src/Infrastructure/Provider/FakeSmsProvider.php` - тестовый SMS-провайдер для демонстрации отправки без внешнего сервиса.
- `src/Infrastructure/Provider/FakeEmailProvider.php` - тестовый Email-провайдер для демонстрации отправки без внешнего сервиса.
- `src/Infrastructure/Idempotency/RedisIdempotencyGuard.php` - Redis-защита от коротких повторов через атомарную команду `SET NX`.
- `src/Infrastructure/RateLimit/RedisRateLimiter.php` - Redis-контроль лимитов по каналу, приоритету и получателю.