<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Infrastructure\Repository;

use PDO;
use SmartLogistics\Notifications\Domain\NotificationBatchResult;
use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationPriority;
use SmartLogistics\Notifications\Domain\NotificationRepository;
use SmartLogistics\Notifications\Domain\NotificationStatus;

final readonly class PdoNotificationRepository implements NotificationRepository
{
    // Получает PDO-подключение к PostgreSQL.
    public function __construct(private PDO $pdo)
    {
    }

    // Создает рассылку и уведомления, если ключ идемпотентности еще не использовался.
    public function createBatchIfAbsent(
        string $idempotencyKey,
        NotificationChannel $channel,
        NotificationPriority $priority,
        string $message,
        array $recipientIds,
    ): NotificationBatchResult {
        $this->pdo->beginTransaction();

        try {
            $existingBatch = $this->findBatchByIdempotencyKey($idempotencyKey);
            if ($existingBatch !== null) {
                // Уникальный ключ PostgreSQL является долговечной защитой от дублей.
                $this->pdo->commit();
                return new NotificationBatchResult(
                    $existingBatch['id'],
                    $this->notificationIdsByBatch($existingBatch['id']),
                    true,
                );
            }

            $batchId = self::uuid();
            $statement = $this->pdo->prepare(
                'INSERT INTO notification_batches (id, idempotency_key, channel, priority, message)
                 VALUES (:id, :idempotency_key, :channel, :priority, :message)'
            );
            $statement->execute([
                'id' => $batchId,
                'idempotency_key' => $idempotencyKey,
                'channel' => $channel->value,
                'priority' => $priority->value,
                'message' => $message,
            ]);

            $notificationIds = [];
            foreach ($recipientIds as $recipientId) {
                $notificationId = self::uuid();
                $notificationIds[] = $notificationId;
                // На каждого получателя создается отдельная строка для независимого статуса.
                $this->insertNotification($notificationId, $batchId, $recipientId, $channel, $priority, $message);
            }

            $this->pdo->commit();

            return new NotificationBatchResult($batchId, $notificationIds, false);
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    // Ищет одно уведомление по id для обработки воркером.
    public function findNotification(string $notificationId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM notifications WHERE id = :id');
        $statement->execute(['id' => $notificationId]);
        $notification = $statement->fetch(PDO::FETCH_ASSOC);

        return $notification === false ? null : $notification;
    }

    // Возвращает историю уведомлений для конкретного получателя.
    public function findByRecipient(string $recipientId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, batch_id, recipient_id, channel, priority, message, status, attempts,
                    provider_message_id, error, created_at, updated_at
             FROM notifications
             WHERE recipient_id = :recipient_id
             ORDER BY created_at DESC'
        );
        $statement->execute(['recipient_id' => $recipientId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    // Помечает уведомление как отправленное провайдеру.
    public function markSent(string $notificationId, string $providerMessageId): void
    {
        $this->updateStatus($notificationId, NotificationStatus::Sent, $providerMessageId);
    }

    // Помечает уведомление как доставленное.
    public function markDelivered(string $notificationId): void
    {
        $this->updateStatus($notificationId, NotificationStatus::Delivered);
    }

    // Помечает уведомление как отклоненное с текстом ошибки.
    public function markDropped(string $notificationId, string $error): void
    {
        $this->updateStatus($notificationId, NotificationStatus::Dropped, null, $error);
    }

    // Увеличивает счетчик попыток обработки уведомления.
    public function incrementAttempts(string $notificationId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET attempts = attempts + 1, updated_at = NOW()
             WHERE id = :id
             RETURNING attempts'
        );
        $statement->execute(['id' => $notificationId]);

        return (int) $statement->fetchColumn();
    }

    // Добавляет одну запись уведомления в рассылку.
    private function insertNotification(
        string $notificationId,
        string $batchId,
        string $recipientId,
        NotificationChannel $channel,
        NotificationPriority $priority,
        string $message,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (id, batch_id, recipient_id, channel, priority, message, status)
             VALUES (:id, :batch_id, :recipient_id, :channel, :priority, :message, :status)'
        );
        $statement->execute([
            'id' => $notificationId,
            'batch_id' => $batchId,
            'recipient_id' => $recipientId,
            'channel' => $channel->value,
            'priority' => $priority->value,
            'message' => $message,
            'status' => NotificationStatus::Queued->value,
        ]);
    }

    // Обновляет статус уведомления и связанные поля результата.
    private function updateStatus(
        string $notificationId,
        NotificationStatus $status,
        ?string $providerMessageId = null,
        ?string $error = null,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET status = :status,
                 provider_message_id = COALESCE(:provider_message_id, provider_message_id),
                 error = :error,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $notificationId,
            'status' => $status->value,
            'provider_message_id' => $providerMessageId,
            'error' => $error,
        ]);
    }

    // Ищет рассылку по ключу идемпотентности.
    private function findBatchByIdempotencyKey(string $idempotencyKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM notification_batches WHERE idempotency_key = :key');
        $statement->execute(['key' => $idempotencyKey]);
        $batch = $statement->fetch(PDO::FETCH_ASSOC);

        return $batch === false ? null : $batch;
    }

    /**
     * @return list<string>
     */
    // Возвращает id уведомлений, входящих в рассылку.
    private function notificationIdsByBatch(string $batchId): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM notifications WHERE batch_id = :batch_id ORDER BY created_at');
        $statement->execute(['batch_id' => $batchId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    // Генерирует UUID v4 без внешних зависимостей.
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
