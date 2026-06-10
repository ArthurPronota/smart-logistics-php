<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Dropped = 'dropped';
}
