<?php declare(strict_types=1);

namespace Nova\Search\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Gelernte Korrekturen: Sucht ein Besucher „vergser“, klickt nichts und findet direkt danach mit „vergaser“
 * ein Produkt, wird das Paar gezählt. Ab einer Mindestanzahl wird „vergser“ künftig als „vergaser“ gesucht.
 */
class Migration1793300000NovaSearchCorrections extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793300000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nova_search_refinement` (
                `sales_channel_id` BINARY(16) NOT NULL,
                `term` VARCHAR(100) NOT NULL,
                `target` VARCHAR(100) NOT NULL,
                `count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`sales_channel_id`, `term`, `target`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin
        ');

        // Herkunft einer Umschreibung: NULL = Claude, „learned“ = aus dem Verhalten der Besucher gelernt
        $exists = $connection->fetchOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'nova_search_rewrite\' AND COLUMN_NAME = \'source\''
        );
        if ($exists === false) {
            $connection->executeStatement('ALTER TABLE `nova_search_rewrite` ADD COLUMN `source` VARCHAR(16) NULL AFTER `status`');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
