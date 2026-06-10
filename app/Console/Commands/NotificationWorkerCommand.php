<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SmartLogistics\Notifications\Application\ProcessNotificationJob;
use SmartLogistics\Notifications\Infrastructure\Queue\RabbitMqMessageBroker;

final class NotificationWorkerCommand extends Command
{
    protected $signature = 'notifications:work';

    protected $description = 'Consume RabbitMQ notification jobs.';

    // Запускает постоянное чтение задач из RabbitMQ.
    public function handle(RabbitMqMessageBroker $broker, ProcessNotificationJob $processor): int
    {
        $this->info('Notification worker started.');

        $broker->consumeNotifications(
            static function (array $payload) use ($processor): void {
                // Сообщение RabbitMQ содержит только id задачи и счетчик повторов.
                $processor->handle($payload['notification_id'], $payload['attempt']);
            },
        );

        return self::SUCCESS;
    }
}
