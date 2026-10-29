<?php declare(strict_types=1);

namespace Nova\Search\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Rückmeldung zum Suchergebnis (1.14.0): „Gefällt Ihnen das Suchergebnis?“ mit Daumen hoch/runter und
 * optionalem Text. Ohne IP, Kunden- oder Sitzungsdaten; Löschung nach 365 Tagen.
 */
class Migration1793500000NovaSearchFeedback extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793500000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nova_search_feedback` (
                `id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `term` VARCHAR(100) NOT NULL,
                `rating` VARCHAR(4) NOT NULL,
                `hits` INT UNSIGNED NULL,
                `text` VARCHAR(200) NULL,
                `source` VARCHAR(8) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.nova_search_feedback.created` (`created_at`),
                KEY `idx.nova_search_feedback.channel_created` (`sales_channel_id`, `created_at`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
