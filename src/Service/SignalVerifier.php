<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Nova\Search\Rewrite\RewriteService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;

/**
 * Prüft Lernsignale aus dem Browser im Hintergrund gegen die echte Produktsuche:
 *  - Trefferzahl der häufigen Suchbegriffe (Grundlage für „Beliebte Suchanfragen“)
 *  - ob ein angeklicktes Produkt bei dem Begriff überhaupt gefunden wird (Grundlage für Ranking und Highlights)
 * Erst geprüfte Signale wirken öffentlich – erfundene Begriffe oder Klicks bleiben wirkungslos.
 */
class SignalVerifier
{
    private const TERMS_PER_RUN = 100;
    private const PRODUCTS_PER_RUN = 300;

    public function __construct(
        private readonly RewriteService $rewriteService,
        private readonly SearchStatistics $statistics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{terms: int, products: int}
     */
    public function run(): array
    {
        return ['terms' => $this->verifyTerms(), 'products' => $this->verifyProducts()];
    }

    private function verifyTerms(): int
    {
        $count = 0;
        foreach ($this->statistics->termsToVerify(self::TERMS_PER_RUN) as $row) {
            try {
                // Wie die Besucher es sehen: inklusive gespeicherter Umschreibung
                $this->statistics->setVerifiedHits($row['sc'], $row['term'], $this->rewriteService->total($row['sc'], $row['term'], null, true));
                ++$count;
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Suche: Trefferprüfung fehlgeschlagen', ['exception' => $e]);
            }
        }

        return $count;
    }

    private function verifyProducts(): int
    {
        $count = 0;
        foreach ($this->statistics->productsToVerify(self::PRODUCTS_PER_RUN) as $row) {
            try {
                $criteria = new Criteria();
                $criteria->addFilter(new OrFilter([
                    new EqualsFilter('product.id', $row['productId']),
                    new EqualsFilter('product.parentId', $row['productId']),
                ]));
                $found = $this->rewriteService->total($row['sc'], $row['term'], $criteria, true) > 0;
                $this->statistics->setProductVerified($row['sc'], $row['term'], $row['productId'], $found);
                ++$count;
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Suche: Produktprüfung fehlgeschlagen', ['exception' => $e]);
            }
        }

        return $count;
    }
}
