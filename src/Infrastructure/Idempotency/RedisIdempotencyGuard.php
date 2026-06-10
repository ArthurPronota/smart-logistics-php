<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Infrastructure\Idempotency;

use Predis\ClientInterface;
use SmartLogistics\Notifications\Domain\IdempotencyGuard;

final readonly class RedisIdempotencyGuard implements IdempotencyGuard
{
    // Получает Redis-клиент и время жизни короткой дедубликации.
    public function __construct(
        private ClientInterface $redis,
        private int $ttlSeconds = 600,
    ) {
    }

    // Резервирует ключ идемпотентности в Redis.
    public function reserve(string $idempotencyKey): bool
    {
        // Команда SET NX атомарно резервирует ключ, а время жизни удаляет короткоживущую метку.
        $result = $this->redis->executeRaw([
            'SET',
            $this->key($idempotencyKey),
            '1',
            'EX',
            (string) $this->ttlSeconds,
            'NX',
        ]);

        return (string) $result === 'OK';
    }

    // Формирует безопасный Redis-ключ без хранения исходного значения.
    private function key(string $idempotencyKey): string
    {
        return 'dedup:notification:' . hash('sha256', $idempotencyKey);
    }
}
