<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

interface NotificationProvider
{
    // Проверяет, поддерживает ли провайдер указанный канал.
    public function supports(NotificationChannel $channel): bool;

    // Отправляет сообщение получателю через конкретный провайдер.
    public function send(string $recipientId, string $message): ProviderResult;
}
