<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Application;

use InvalidArgumentException;
use SmartLogistics\Notifications\Domain\IdempotencyGuard;
use SmartLogistics\Notifications\Domain\MessageBroker;
use SmartLogistics\Notifications\Domain\NotificationBatchResult;
use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationPriority;
use SmartLogistics\Notifications\Domain\NotificationRepository;
use SmartLogistics\Notifications\Domain\RateLimiter;

final readonly class StartBulkNotification
{
    // Получает зависимости для создания рассылки, публикации задач и Redis-проверок.
    public function __construct(
        private NotificationRepository $repository,
        private MessageBroker $broker,
        private ?IdempotencyGuard $idempotencyGuard = null,
        private ?RateLimiter $rateLimiter = null,
    ) {
    }

    /**
     * @param list<string> $recipientIds
     */
    // Проверяет входные данные, создает рассылку и публикует задачи в очередь.
    public function handle(
        string $idempotencyKey,
        string $channel,
        string $priority,
        string $message,
        array $recipientIds,
    ): NotificationBatchResult {
        $channelEnum = NotificationChannel::tryFrom($channel)
            ?? throw new InvalidArgumentException('Unsupported channel.');
        $priorityEnum = NotificationPriority::tryFrom($priority)
            ?? throw new InvalidArgumentException('Unsupported priority.');

        if ($idempotencyKey === '' || trim($message) === '' || $recipientIds === []) {
            throw new InvalidArgumentException('idempotency_key, message and recipient_ids are required.');
        }

        $recipientIds = array_values(array_unique(array_map('strval', $recipientIds)));
        // Лимит отправки и короткая дедубликация проверяются до записи в БД и публикации в очередь.
        $this->rateLimiter?->assertAllowed($channelEnum, $priorityEnum, $recipientIds);
        $this->idempotencyGuard?->reserve($idempotencyKey);

        $result = $this->repository->createBatchIfAbsent(
            $idempotencyKey,
            $channelEnum,
            $priorityEnum,
            $message,
            $recipientIds,
        );

        if (!$result->duplicate) {
            // В очередь публикуется только новая рассылка; повторный запрос возвращает существующие id.
            foreach ($result->notificationIds as $notificationId) {
                $this->broker->publishNotification($notificationId, $priorityEnum);
            }
        }

        return $result;
    }
}
