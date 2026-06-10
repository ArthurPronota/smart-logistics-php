<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

final readonly class NotificationBatchResult
{
    /**
     * @param list<string> $notificationIds
     */
    // Хранит результат создания или повторного получения рассылки.
    public function __construct(
        public string $batchId,
        public array $notificationIds,
        public bool $duplicate,
    ) {
    }
}
