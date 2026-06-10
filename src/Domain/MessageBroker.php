<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

interface MessageBroker
{
    // Публикует уведомление в очередь обработки.
    public function publishNotification(string $notificationId, NotificationPriority $priority, int $attempt = 0): void;

    /**
     * @param callable(array{notification_id: string, attempt: int}): void $handler
     */
    // Запускает чтение уведомлений из очереди.
    public function consumeNotifications(callable $handler): void;
}
