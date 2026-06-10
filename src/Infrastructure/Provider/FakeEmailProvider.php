<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Infrastructure\Provider;

use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationProvider;
use SmartLogistics\Notifications\Domain\ProviderResult;

final class FakeEmailProvider implements NotificationProvider
{
    // Сообщает, что провайдер обслуживает Email-канал.
    public function supports(NotificationChannel $channel): bool
    {
        return $channel === NotificationChannel::Email;
    }

    // Имитирует отправку Email без реального внешнего сервиса.
    public function send(string $recipientId, string $message): ProviderResult
    {
        if (!str_contains($recipientId, '@')) {
            return ProviderResult::dropped('Invalid email address.');
        }

        return ProviderResult::delivered('email_' . bin2hex(random_bytes(6)));
    }
}
