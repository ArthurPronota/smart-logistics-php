<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Application;

use RuntimeException;
use SmartLogistics\Notifications\Domain\MessageBroker;
use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationPriority;
use SmartLogistics\Notifications\Domain\NotificationProvider;
use SmartLogistics\Notifications\Domain\NotificationRepository;
use SmartLogistics\Notifications\Domain\NotificationStatus;

final readonly class ProcessNotificationJob
{
    private const MAX_ATTEMPTS = 3;

    /**
     * @param list<NotificationProvider> $providers
     */
    // Получает repository, broker и список доступных провайдеров отправки.
    public function __construct(
        private NotificationRepository $repository,
        private MessageBroker $broker,
        private array $providers,
    ) {
    }

    // Обрабатывает одну задачу отправки уведомления.
    public function handle(string $notificationId, int $attempt = 0): void
    {
        $notification = $this->repository->findNotification($notificationId);

        if ($notification === null) {
            return;
        }

        if ($notification['status'] === NotificationStatus::Delivered->value) {
            return;
        }

        $attempts = $this->repository->incrementAttempts($notificationId);
        $channel = NotificationChannel::from($notification['channel']);
        $provider = $this->providerFor($channel);

        try {
            // Вызов провайдера изолирован здесь, чтобы временные ошибки повторять через RabbitMQ.
            $result = $provider->send($notification['recipient_id'], $notification['message']);
        } catch (RuntimeException $exception) {
            if ($attempts < self::MAX_ATTEMPTS) {
                $this->broker->publishNotification(
                    $notificationId,
                    NotificationPriority::from($notification['priority']),
                    $attempt + 1,
                );
                return;
            }

            $this->repository->markDropped($notificationId, $exception->getMessage());
            return;
        }

        if (!$result->delivered) {
            $this->repository->markDropped($notificationId, $result->error ?? 'Provider rejected message.');
            return;
        }

        $this->repository->markSent($notificationId, $result->providerMessageId ?? $notificationId);
        $this->repository->markDelivered($notificationId);
    }

    // Находит провайдера, который поддерживает канал уведомления.
    private function providerFor(NotificationChannel $channel): NotificationProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($channel)) {
                return $provider;
            }
        }

        throw new RuntimeException("No provider configured for {$channel->value}.");
    }
}
