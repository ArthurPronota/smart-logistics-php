<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PDO;
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

final class NotificationServiceProvider extends ServiceProvider
{
    // Регистрирует зависимости приложения в контейнере Laravel.
    public function register(): void
    {
        // Контейнер Laravel связывает старое ядро приложения с сервисами времени выполнения.
        $this->app->singleton(PDO::class, static fn (): PDO => new PDO(
            getenv('DATABASE_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=notifications',
            getenv('DATABASE_USER') ?: 'notifications',
            getenv('DATABASE_PASSWORD') ?: 'notifications',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ));

        $this->app->singleton(RedisClient::class, static fn (): RedisClient => new RedisClient([
            'scheme' => 'tcp',
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        ]));

        $this->app->singleton(AMQPStreamConnection::class, static fn (): AMQPStreamConnection => new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: '127.0.0.1',
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
        ));

        $this->app->singleton(PdoNotificationRepository::class, static fn ($app): PdoNotificationRepository => new PdoNotificationRepository(
            $app->make(PDO::class),
        ));

        $this->app->singleton(RabbitMqMessageBroker::class, static fn ($app): RabbitMqMessageBroker => new RabbitMqMessageBroker(
            $app->make(AMQPStreamConnection::class),
        ));

        $this->app->singleton(RedisIdempotencyGuard::class, static fn ($app): RedisIdempotencyGuard => new RedisIdempotencyGuard(
            $app->make(RedisClient::class),
        ));

        $this->app->singleton(RedisRateLimiter::class, static fn ($app): RedisRateLimiter => new RedisRateLimiter(
            $app->make(RedisClient::class),
        ));

        // HTTP и воркер получают эти обработчики из контейнера Laravel.
        $this->app->singleton(StartBulkNotification::class, static fn ($app): StartBulkNotification => new StartBulkNotification(
            $app->make(PdoNotificationRepository::class),
            $app->make(RabbitMqMessageBroker::class),
            $app->make(RedisIdempotencyGuard::class),
            $app->make(RedisRateLimiter::class),
        ));

        $this->app->singleton(GetRecipientNotifications::class, static fn ($app): GetRecipientNotifications => new GetRecipientNotifications(
            $app->make(PdoNotificationRepository::class),
        ));

        $this->app->singleton(ProcessNotificationJob::class, static fn ($app): ProcessNotificationJob => new ProcessNotificationJob(
            $app->make(PdoNotificationRepository::class),
            $app->make(RabbitMqMessageBroker::class),
            [new FakeSmsProvider(), new FakeEmailProvider()],
        ));
    }
}
