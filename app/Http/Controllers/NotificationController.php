<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use SmartLogistics\Notifications\Application\GetRecipientNotifications;
use SmartLogistics\Notifications\Application\StartBulkNotification;
use SmartLogistics\Notifications\Domain\RateLimitExceeded;

final class NotificationController
{
    // Получает обработчики приложения из контейнера Laravel.
    public function __construct(
        private readonly StartBulkNotification $startBulkNotification,
        private readonly GetRecipientNotifications $getRecipientNotifications,
    ) {
    }

    // Запускает массовую рассылку и возвращает id рассылки и id уведомлений.
    public function startBulk(Request $request): JsonResponse
    {
        try {
            // Контроллер остается тонким: валидация и бизнес-правила находятся в слое Application.
            $result = $this->startBulkNotification->handle(
                (string) $request->input('idempotency_key', ''),
                (string) $request->input('channel', ''),
                (string) $request->input('priority', 'normal'),
                (string) $request->input('message', ''),
                $request->input('recipient_ids', []),
            );

            return response()->json([
                'batch_id' => $result->batchId,
                'notification_ids' => $result->notificationIds,
                'duplicate' => $result->duplicate,
            ], $result->duplicate ? 200 : 202);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (RateLimitExceeded $exception) {
            return response()->json(['error' => $exception->getMessage()], 429);
        }
    }

    // Возвращает историю уведомлений конкретного получателя.
    public function recipientNotifications(string $recipientId): JsonResponse
    {
        return response()->json([
            'items' => $this->getRecipientNotifications->handle($recipientId),
        ]);
    }
}
