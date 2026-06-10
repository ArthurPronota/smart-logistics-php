<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;
use SmartLogistics\Notifications\Application\GetRecipientNotifications;
use SmartLogistics\Notifications\Application\ProcessNotificationJob;
use SmartLogistics\Notifications\Application\StartBulkNotification;
use SmartLogistics\Notifications\Infrastructure\Idempotency\RedisIdempotencyGuard;
use SmartLogistics\Notifications\Infrastructure\Provider\FakeEmailProvider;
use SmartLogistics\Notifications\Infrastructure\Provider\FakeSmsProvider;
use SmartLogistics\Notifications\Infrastructure\Queue\RabbitMqMessageBroker;
use SmartLogistics\Notifications\Infrastructure\RateLimit\RedisRateLimiter;
use SmartLogistics\Notifications\Infrastructure\Repository\PdoNotificationRepository;

require_once __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO(
    getenv('DATABASE_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=notifications',
    getenv('DATABASE_USER') ?: 'notifications',
    getenv('DATABASE_PASSWORD') ?: 'notifications',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('REDIS_PORT') ?: 6379),
]);

$amqp = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST') ?: '127.0.0.1',
    (int) (getenv('RABBITMQ_PORT') ?: 5672),
    getenv('RABBITMQ_USER') ?: 'guest',
    getenv('RABBITMQ_PASSWORD') ?: 'guest',
);

$repository = new PdoNotificationRepository($pdo);
$broker = new RabbitMqMessageBroker($amqp);
$idempotencyGuard = new RedisIdempotencyGuard($redis);
$rateLimiter = new RedisRateLimiter($redis);
$providers = [new FakeSmsProvider(), new FakeEmailProvider()];

return [
    'pdo' => $pdo,
    'redis' => $redis,
    'repository' => $repository,
    'broker' => $broker,
    'idempotency_guard' => $idempotencyGuard,
    'rate_limiter' => $rateLimiter,
    'start_bulk_notification' => new StartBulkNotification($repository, $broker, $idempotencyGuard, $rateLimiter),
    'get_recipient_notifications' => new GetRecipientNotifications($repository),
    'process_notification_job' => new ProcessNotificationJob($repository, $broker, $providers),
];
