<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SmartLogistics\Notifications\Application\ProcessNotificationJob;
use SmartLogistics\Notifications\Application\StartBulkNotification;
use SmartLogistics\Notifications\Domain\IdempotencyGuard;
use SmartLogistics\Notifications\Domain\MessageBroker;
use SmartLogistics\Notifications\Domain\NotificationBatchResult;
use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationPriority;
use SmartLogistics\Notifications\Domain\NotificationProvider;
use SmartLogistics\Notifications\Domain\NotificationRepository;
use SmartLogistics\Notifications\Domain\NotificationStatus;
use SmartLogistics\Notifications\Domain\ProviderResult;
use SmartLogistics\Notifications\Domain\RateLimitExceeded;
use SmartLogistics\Notifications\Domain\RateLimiter;

final class NotificationFlowTest extends TestCase
{
    // Проверяет полный путь: создание рассылки, публикация задачи и доставка.
    public function testDispatchAndProcessNotification(): void
    {
        $repository = new InMemoryNotificationRepository();
        $broker = new InMemoryBroker();
        $provider = new RecordingProvider(NotificationChannel::Sms);

        $dispatch = new StartBulkNotification($repository, $broker);
        $result = $dispatch->handle(
            'request-1',
            'sms',
            'transactional',
            'Route changed',
            ['subscriber-1'],
        );

        self::assertFalse($result->duplicate);
        self::assertCount(1, $broker->published);
        self::assertSame(10, $broker->published[0]['priority']);

        $processor = new ProcessNotificationJob($repository, $broker, [$provider]);
        $processor->handle($broker->published[0]['notification_id']);

        $history = $repository->findByRecipient('subscriber-1');
        self::assertSame(NotificationStatus::Delivered->value, $history[0]['status']);
        self::assertSame([['subscriber-1', 'Route changed']], $provider->sent);
    }

    // Проверяет, что повторный ключ идемпотентности не публикует задачи повторно.
    public function testIdempotencyReturnsExistingBatchWithoutRepublishing(): void
    {
        $repository = new InMemoryNotificationRepository();
        $broker = new InMemoryBroker();
        $dispatch = new StartBulkNotification($repository, $broker);

        $first = $dispatch->handle('same-key', 'email', 'marketing', 'Sale', ['client@example.com']);
        $second = $dispatch->handle('same-key', 'email', 'marketing', 'Sale', ['client@example.com']);

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame($first->batchId, $second->batchId);
        self::assertCount(1, $broker->published);
    }

    // Проверяет, что Redis-защита идемпотентности вызывается до запуска рассылки.
    public function testRedisIdempotencyGuardIsCalledBeforeDispatch(): void
    {
        $repository = new InMemoryNotificationRepository();
        $broker = new InMemoryBroker();
        $idempotencyGuard = new RecordingIdempotencyGuard();
        $dispatch = new StartBulkNotification($repository, $broker, $idempotencyGuard);

        $dispatch->handle('redis-key-1', 'sms', 'normal', 'Hello', ['subscriber-1']);

        self::assertSame(['redis-key-1'], $idempotencyGuard->reservedKeys);
    }

    // Проверяет, что лимит отправки останавливает создание рассылки и публикацию.
    public function testRateLimitPreventsBatchCreationAndPublishing(): void
    {
        $repository = new InMemoryNotificationRepository();
        $broker = new InMemoryBroker();
        $dispatch = new StartBulkNotification(
            $repository,
            $broker,
            null,
            new BlockingRateLimiter(),
        );

        $this->expectException(RateLimitExceeded::class);

        try {
            $dispatch->handle('limited-key', 'sms', 'marketing', 'Sale', ['subscriber-1']);
        } finally {
            self::assertSame([], $broker->published);
            self::assertSame([], $repository->findByRecipient('subscriber-1'));
        }
    }
}

final class InMemoryBroker implements MessageBroker
{
    /** @var list<array{notification_id: string, priority: int, attempt: int}> */
    public array $published = [];

    // Запоминает опубликованную задачу вместо отправки в RabbitMQ.
    public function publishNotification(string $notificationId, NotificationPriority $priority, int $attempt = 0): void
    {
        $this->published[] = [
            'notification_id' => $notificationId,
            'priority' => $priority->queuePriority(),
            'attempt' => $attempt,
        ];
    }

    // Передает сохраненные сообщения тестовому обработчику.
    public function consumeNotifications(callable $handler): void
    {
        foreach ($this->published as $message) {
            $handler(['notification_id' => $message['notification_id'], 'attempt' => $message['attempt']]);
        }
    }
}

final class InMemoryNotificationRepository implements NotificationRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $batchesByIdempotencyKey = [];

    /** @var array<string, array<string, mixed>> */
    private array $notifications = [];

    // Создает рассылку в памяти или возвращает существующую по ключу идемпотентности.
    public function createBatchIfAbsent(
        string $idempotencyKey,
        NotificationChannel $channel,
        NotificationPriority $priority,
        string $message,
        array $recipientIds,
    ): NotificationBatchResult {
        if (isset($this->batchesByIdempotencyKey[$idempotencyKey])) {
            $batch = $this->batchesByIdempotencyKey[$idempotencyKey];

            return new NotificationBatchResult($batch['id'], $batch['notification_ids'], true);
        }

        $batchId = 'batch-' . count($this->batchesByIdempotencyKey);
        $notificationIds = [];

        foreach ($recipientIds as $recipientId) {
            $notificationId = 'notification-' . count($this->notifications);
            $notificationIds[] = $notificationId;
            $this->notifications[$notificationId] = [
                'id' => $notificationId,
                'batch_id' => $batchId,
                'recipient_id' => $recipientId,
                'channel' => $channel->value,
                'priority' => $priority->value,
                'message' => $message,
                'status' => NotificationStatus::Queued->value,
                'attempts' => 0,
                'provider_message_id' => null,
                'error' => null,
            ];
        }

        $this->batchesByIdempotencyKey[$idempotencyKey] = [
            'id' => $batchId,
            'notification_ids' => $notificationIds,
        ];

        return new NotificationBatchResult($batchId, $notificationIds, false);
    }

    // Ищет уведомление в памяти по id.
    public function findNotification(string $notificationId): ?array
    {
        return $this->notifications[$notificationId] ?? null;
    }

    // Возвращает уведомления получателя из in-memory хранилища.
    public function findByRecipient(string $recipientId): array
    {
        return array_values(array_filter(
            $this->notifications,
            static fn (array $notification): bool => $notification['recipient_id'] === $recipientId,
        ));
    }

    // Отмечает уведомление как отправленное в in-memory хранилище.
    public function markSent(string $notificationId, string $providerMessageId): void
    {
        $this->notifications[$notificationId]['status'] = NotificationStatus::Sent->value;
        $this->notifications[$notificationId]['provider_message_id'] = $providerMessageId;
    }

    // Отмечает уведомление как доставленное в in-memory хранилище.
    public function markDelivered(string $notificationId): void
    {
        $this->notifications[$notificationId]['status'] = NotificationStatus::Delivered->value;
    }

    // Отмечает уведомление как отклоненное в in-memory хранилище.
    public function markDropped(string $notificationId, string $error): void
    {
        $this->notifications[$notificationId]['status'] = NotificationStatus::Dropped->value;
        $this->notifications[$notificationId]['error'] = $error;
    }

    // Увеличивает счетчик попыток в in-memory хранилище.
    public function incrementAttempts(string $notificationId): int
    {
        return ++$this->notifications[$notificationId]['attempts'];
    }
}

final class RecordingProvider implements NotificationProvider
{
    /** @var list<array{string, string}> */
    public array $sent = [];

    // Фиксирует канал, который поддерживает тестовый провайдер.
    public function __construct(private readonly NotificationChannel $channel)
    {
    }

    // Проверяет поддержку канала тестовым провайдером.
    public function supports(NotificationChannel $channel): bool
    {
        return $channel === $this->channel;
    }

    // Запоминает отправку и возвращает успешный результат.
    public function send(string $recipientId, string $message): ProviderResult
    {
        $this->sent[] = [$recipientId, $message];

        return ProviderResult::delivered('provider-message-1');
    }
}

final class RecordingIdempotencyGuard implements IdempotencyGuard
{
    /** @var list<string> */
    public array $reservedKeys = [];

    // Запоминает ключи, которые приложение пыталось зарезервировать.
    public function reserve(string $idempotencyKey): bool
    {
        $this->reservedKeys[] = $idempotencyKey;

        return true;
    }
}

final class BlockingRateLimiter implements RateLimiter
{
    // Всегда выбрасывает ошибку лимита для проверки остановки запуска рассылки.
    public function assertAllowed(
        NotificationChannel $channel,
        NotificationPriority $priority,
        array $recipientIds,
    ): void {
        throw new RateLimitExceeded('Rate limit exceeded for test.');
    }
}
