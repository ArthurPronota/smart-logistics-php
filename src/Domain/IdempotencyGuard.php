<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

interface IdempotencyGuard
{
    // Пытается зарезервировать ключ идемпотентности.
    public function reserve(string $idempotencyKey): bool;
}
