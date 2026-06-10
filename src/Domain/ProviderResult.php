<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

final readonly class ProviderResult
{
    // Создает результат вызова провайдера.
    private function __construct(
        public bool $delivered,
        public ?string $providerMessageId,
        public ?string $error,
    ) {
    }

    // Возвращает успешный результат доставки.
    public static function delivered(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, null);
    }

    // Возвращает результат отказа провайдера.
    public static function dropped(string $error): self
    {
        return new self(false, null, $error);
    }
}
