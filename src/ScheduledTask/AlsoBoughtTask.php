<?php declare(strict_types=1);

namespace Nova\Search\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Täglich: „Kunden kauften auch“ aus den Bestellungen neu berechnen.
 */
class AlsoBoughtTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_search.also_bought';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
