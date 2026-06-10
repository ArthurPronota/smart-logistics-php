<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// Быстрая проверка, что HTTP-приложение запущено.
Route::get('/health', static fn () => response()->json(['status' => 'ok']));

// Запускает массовую рассылку через Laravel-контроллер.
Route::post('/notifications/bulk', [NotificationController::class, 'startBulk']);

// Возвращает историю и текущие статусы уведомлений получателя.
Route::get('/subscribers/{recipientId}/notifications', [NotificationController::class, 'recipientNotifications'])
    ->where('recipientId', '.*');
