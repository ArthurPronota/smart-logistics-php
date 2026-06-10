<?php

use Illuminate\Support\Facades\Artisan;

// Диагностическая команда показывает, что сервис подключен через Laravel.
Artisan::command('about:notifications', function (): void {
    $this->info('Smart Logistics notification service is wired through Laravel.');
})->purpose('Show notification service information');
