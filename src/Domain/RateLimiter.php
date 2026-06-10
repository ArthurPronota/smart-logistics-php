<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

interface RateLimiter
{
    /**
     * @param list<string> $recipientIds
     */
    // Проверяет лимит отправки для получателей.
    public function assertAllowed(
        NotificationChannel $channel,
        NotificationPriority $priority,
        array $recipientIds,
    ): void;
}
