<?php

declare(strict_types=1);

use SmartLogistics\Notifications\Application\ProcessNotificationJob;
use SmartLogistics\Notifications\Domain\MessageBroker;

$container = require __DIR__ . '/../config/bootstrap.php';

/** @var MessageBroker $broker */
$broker = $container['broker'];
/** @var ProcessNotificationJob $processor */
$processor = $container['process_notification_job'];

fwrite(STDOUT, 'Notification worker started.' . PHP_EOL);

$broker->consumeNotifications(
    static function (array $payload) use ($processor): void {
        $processor->handle($payload['notification_id'], $payload['attempt']);
    },
);
