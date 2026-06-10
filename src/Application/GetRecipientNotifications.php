<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Application;

use SmartLogistics\Notifications\Domain\NotificationRepository;

final readonly class GetRecipientNotifications
{
    // Получает repository для чтения истории уведомлений.
    public function __construct(private NotificationRepository $repository)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    // Возвращает уведомления, связанные с конкретным получателем.
    public function handle(string $recipientId): array
    {
        return $this->repository->findByRecipient($recipientId);
    }
}
