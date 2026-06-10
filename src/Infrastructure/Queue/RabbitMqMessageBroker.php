<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Infrastructure\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use SmartLogistics\Notifications\Domain\MessageBroker;
use SmartLogistics\Notifications\Domain\NotificationPriority;

final class RabbitMqMessageBroker implements MessageBroker
{
    private const QUEUE = 'notifications.outbound';

    private AMQPChannel $channel;

    // Открывает канал RabbitMQ и объявляет очередь уведомлений.
    public function __construct(private readonly AMQPStreamConnection $connection)
    {
        $this->channel = $this->connection->channel();
        // Долговечная приоритетная очередь сохраняет задачи после перезапуска RabbitMQ.
        $this->channel->queue_declare(
            self::QUEUE,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable(['x-max-priority' => 10]),
        );
    }

    // Публикует задачу отправки уведомления в приоритетную очередь.
    public function publishNotification(string $notificationId, NotificationPriority $priority, int $attempt = 0): void
    {
        $message = new AMQPMessage(
            json_encode(['notification_id' => $notificationId, 'attempt' => $attempt], JSON_THROW_ON_ERROR),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority->queuePriority(),
                'content_type' => 'application/json',
            ],
        );

        $this->channel->basic_publish($message, '', self::QUEUE);
    }

    // Слушает очередь и передает каждое сообщение во внешний обработчик.
    public function consumeNotifications(callable $handler): void
    {
        // Обрабатываем одно сообщение за раз, чтобы повторы и подтверждения были предсказуемыми.
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($handler): void {
                try {
                    $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $handler([
                        'notification_id' => (string) $payload['notification_id'],
                        'attempt' => (int) ($payload['attempt'] ?? 0),
                    ]);
                    $message->ack();
                } catch (\Throwable $throwable) {
                    // Ошибочные задачи отклоняются без повторной постановки; повторы публикуются явно.
                    $message->nack(false, false);
                    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
                }
            },
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }
}
