<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Infrastructure\Provider;

use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationProvider;
use SmartLogistics\Notifications\Domain\ProviderResult;

final class FakeSmsProvider implements NotificationProvider
{
    // Сообщает, что провайдер обслуживает SMS-канал.
    public function supports(NotificationChannel $channel): bool
    {
        return $channel === NotificationChannel::Sms;
    }

    // Имитирует отправку SMS без реального внешнего сервиса.
    public function send(string $recipientId, string $message): ProviderResult
    {
        if (str_starts_with($recipientId, 'invalid')) {
            return ProviderResult::dropped('Invalid phone number.');
        }

        return ProviderResult::delivered('sms_' . bin2hex(random_bytes(6)));
    }
}
