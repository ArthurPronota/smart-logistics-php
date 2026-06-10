<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
