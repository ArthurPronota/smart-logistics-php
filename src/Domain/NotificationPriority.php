<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

enum NotificationPriority: string
{
    case Transactional = 'transactional';
    case Normal = 'normal';
    case Marketing = 'marketing';

    // Возвращает числовой приоритет для RabbitMQ.
    public function queuePriority(): int
    {
        return match ($this) {
            self::Transactional => 10,
            self::Normal => 5,
            self::Marketing => 1,
        };
    }
}
