<?php

declare(strict_types=1);

namespace SmartLogistics\Notifications\Domain;

use RuntimeException;

final class RateLimitExceeded extends RuntimeException
{
}
