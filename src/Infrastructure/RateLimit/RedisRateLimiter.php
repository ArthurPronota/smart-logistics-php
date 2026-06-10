<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Infrastructure\RateLimit;

use Predis\ClientInterface;
use SmartLogistics\Notifications\Domain\NotificationChannel;
use SmartLogistics\Notifications\Domain\NotificationPriority;
use SmartLogistics\Notifications\Domain\RateLimitExceeded;
use SmartLogistics\Notifications\Domain\RateLimiter;

final readonly class RedisRateLimiter implements RateLimiter
{
    // Получает Redis-клиент для счетчиков лимитов.
    public function __construct(private ClientInterface $redis)
    {
    }

    // Проверяет, не превышены ли лимиты для списка получателей.
    public function assertAllowed(
        NotificationChannel $channel,
        NotificationPriority $priority,
        array $recipientIds,
    ): void {
        if ($priority === NotificationPriority::Transactional) {
            return;
        }

        [$limit, $ttlSeconds, $window] = $this->rule($channel, $priority);

        foreach ($recipientIds as $recipientId) {
            // Для каждого получателя ведется отдельный счетчик текущего временного окна.
            $key = sprintf(
                'rate:%s:%s:%s:%s',
                $channel->value,
                $priority->value,
                hash('sha256', $recipientId),
                $window,
            );

            $count = (int) $this->redis->incr($key);
            if ($count === 1) {
                $this->redis->expire($key, $ttlSeconds);
            }

            if ($count > $limit) {
                throw new RateLimitExceeded(sprintf(
                    'Rate limit exceeded for %s recipient %s.',
                    $channel->value,
                    $recipientId,
                ));
            }
        }
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    // Возвращает лимит, время жизни и идентификатор временного окна.
    private function rule(NotificationChannel $channel, NotificationPriority $priority): array
    {
        if ($priority === NotificationPriority::Marketing) {
            return [100, 86400, gmdate('Ymd')];
        }

        return match ($channel) {
            NotificationChannel::Sms => [10, 3600, gmdate('YmdH')],
            NotificationChannel::Email => [50, 3600, gmdate('YmdH')],
        };
    }
}
