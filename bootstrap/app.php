<?php

use App\Console\Commands\NotificationWorkerCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withCommands([
        NotificationWorkerCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Здесь можно подключать общие промежуточные обработчики Laravel.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Здесь можно централизованно настраивать обработку исключений.
    })
    ->create();
