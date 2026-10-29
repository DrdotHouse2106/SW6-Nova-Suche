<?php declare(strict_types=1);

namespace Nova\Search;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

/**
 * Nova Suche – Suchfenster, das aus dem Verhalten der Kunden lernt und Begriffe ohne Treffer
 * mit Claude dauerhaft verbessert.
 */
class NovaSearch extends Plugin
{
    /** Installiert das Anthropic-SDK (composer.json „require“) bei der Plugin-Installation */
    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_term_product`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_term`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_rewrite`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_claude_usage`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_claude_notice`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_number`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_term_day`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_product_day`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_synonym`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_redirect`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_import`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_hint`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_refinement`');
        $connection->executeStatement('DROP TABLE IF EXISTS `nova_search_feedback`');
    }
}
