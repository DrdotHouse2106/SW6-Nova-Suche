<?php declare(strict_types=1);

namespace Nova\Search\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Absicherung des Lernens (1.12.0):
 *  - Korrekturen: Zählung über verschiedene Tage, Zeitpunkt der letzten Prüfung
 *  - Beliebte Suchanfragen: serverseitig geprüfte Trefferzahl statt der Angabe aus dem Browser
 *  - Gelernte Produkte: erst wirksam, wenn das Produkt bei dem Begriff wirklich gefunden wird
 *  - Bereits gelernte Korrekturen werden einmal neu geprüft (Status „review“, bis dahin inaktiv)
 */
class Migration1793400000NovaSearchVerification extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793400000;
    }

    public function update(Connection $connection): void
    {
        $this->ensureColumn($connection, 'nova_search_refinement', 'days', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `count`');
        $this->ensureColumn($connection, 'nova_search_refinement', 'last_day', 'DATE NULL AFTER `days`');
        $this->ensureColumn($connection, 'nova_search_refinement', 'checked_at', 'DATETIME(3) NULL');

        $this->ensureColumn($connection, 'nova_search_term', 'verified_hits', 'INT UNSIGNED NULL AFTER `last_hits`');
        $this->ensureColumn($connection, 'nova_search_term', 'verified_at', 'DATETIME(3) NULL');

        $this->ensureColumn($connection, 'nova_search_term_product', 'verified', 'TINYINT(1) NULL');
        $this->ensureColumn($connection, 'nova_search_term_product', 'verified_at', 'DATETIME(3) NULL');

        $connection->executeStatement(
            'UPDATE `nova_search_rewrite` SET `status` = \'review\' WHERE `status` = \'done\' AND `source` = \'learned\''
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function ensureColumn(Connection $connection, string $table, string $column, string $definition): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => $column]
        );
        if ($exists === false) {
            $connection->executeStatement(\sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }
}
