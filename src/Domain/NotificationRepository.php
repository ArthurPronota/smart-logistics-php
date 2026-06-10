<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

interface NotificationRepository
{
    /**
     * @param list<string> $recipientIds
     */
    // Создает рассылку с уведомлениями или возвращает уже созданную рассылку.
    public function createBatchIfAbsent(
        string $idempotencyKey,
        NotificationChannel $channel,
        NotificationPriority $priority,
        string $message,
        array $recipientIds,
    ): NotificationBatchResult;

    // Ищет уведомление по id.
    public function findNotification(string $notificationId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    // Возвращает историю уведомлений получателя.
    public function findByRecipient(string $recipientId): array;

    // Отмечает уведомление как отправленное.
    public function markSent(string $notificationId, string $providerMessageId): void;

    // Отмечает уведомление как доставленное.
    public function markDelivered(string $notificationId): void;

    // Отмечает уведомление как отклоненное.
    public function markDropped(string $notificationId, string $error): void;

    // Увеличивает счетчик попыток обработки.
    public function incrementAttempts(string $notificationId): int;
}
