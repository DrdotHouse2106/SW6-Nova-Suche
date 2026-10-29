<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sucht bei 0 Treffern zusätzlich in Schwestershops derselben Shopware-Installation
 * (Verkaufskanäle mit eigener Domain). Preise, Sichtbarkeit und Verfügbarkeit kommen aus dem
 * jeweiligen Shop; Links zeigen auf dessen Domain.
 */
class PartnerSearch
{
    public const MAX_PARTNERS = 3;

    /** Wie lange „nichts gefunden“ je Schwestershop und Begriff gemerkt wird */
    private const EMPTY_TTL = 600;

    private ?CacheItemPoolInterface $cache = null;

    private ?TrafficLimiter $limiter = null;

    public function __construct(
        private readonly AbstractProductSearchRoute $productSearchRoute,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function setCache(CacheItemPoolInterface $cache): void
    {
        $this->cache = $cache;
    }

    public function setLimiter(TrafficLimiter $limiter): void
    {
        $this->limiter = $limiter;
    }

    /**
     * Leere Ergebnisse werden kurz gemerkt, und je Client-IP gibt es eine Obergrenze – Zufallsbegriffe
     * lösen so nicht bei jedem Aufruf mehrere teure Schwestershop-Suchen aus.
     *
     * @param list<string> $partnerIds Verkaufskanal-IDs (hex)
     *
     * @return list<array{name: string, url: string, searchUrl: string, total: int, products: ProductCollection}>
     */
    public function search(string $term, SalesChannelContext $current, array $partnerIds, int $limit = 4): array
    {
        $results = [];
        if ($this->limiter !== null && !$this->limiter->allow(TrafficLimiter::PARTNER, $current->getSalesChannelId())) {
            return $results;
        }

        foreach (\array_slice(array_values(array_unique($partnerIds)), 0, self::MAX_PARTNERS) as $partnerId) {
            if ($partnerId === $current->getSalesChannelId() || !Uuid::isValid($partnerId)) {
                continue;
            }

            try {
                $domain = $this->domain($partnerId, $current->getLanguageId());
                if ($domain === null) {
                    continue;
                }

                $emptyKey = 'nova_search_partner_empty_' . hash('sha256', $partnerId . '|' . $domain['languageId'] . '|' . mb_strtolower($term));
                if ($this->isCachedEmpty($emptyKey)) {
                    continue;
                }

                $context = $this->contextFactory->create(Uuid::randomHex(), $partnerId, array_filter([
                    SalesChannelContextService::LANGUAGE_ID => $domain['languageId'],
                    SalesChannelContextService::CURRENCY_ID => $domain['currencyId'],
                ]));

                $request = new Request(['search' => $term, 'limit' => $limit, 'p' => 1], [
                    'manufacturer-filter' => false,
                    'price-filter' => false,
                    'property-filter' => false,
                    'rating-filter' => false,
                    'shipping-free-filter' => false,
                    'no-aggregations' => true,
                ]);
                $criteria = new Criteria();
                $criteria->setTitle('nova-search::partner');
                $criteria->addAssociation('cover.media');

                $listing = $this->productSearchRoute->load($request, $context, $criteria)->getListingResult();
                if ($listing->getTotal() === 0) {
                    $this->rememberEmpty($emptyKey);
                    continue;
                }

                $base = rtrim($domain['url'], '/');
                $results[] = [
                    'name' => self::displayName($base),
                    'url' => $base,
                    'searchUrl' => $base . '/search?search=' . rawurlencode($term),
                    'total' => $listing->getTotal(),
                    'products' => $listing->getEntities(),
                ];
            } catch (\Throwable $e) {
                // z. B. Suchbegriff nur aus Stoppwörtern oder Shop falsch eingerichtet – Hauptsuche bleibt unberührt
                $this->logger->info('Nova Suche: Schwestershop-Suche übersprungen', ['salesChannelId' => $partnerId, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function isCachedEmpty(string $key): bool
    {
        try {
            return $this->cache?->getItem($key)->isHit() ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function rememberEmpty(string $key): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $item = $this->cache->getItem($key);
            $item->set(true);
            $item->expiresAfter(self::EMPTY_TTL);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Cache ist nur eine Abkürzung
        }
    }

    /**
     * „https://www.example-shop.de/en“ → „example-shop.de“
     */
    public static function displayName(string $url): string
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);

        return $host !== '' ? (string) preg_replace('/^www\./', '', $host) : $url;
    }

    /**
     * Domain des Schwestershops – bevorzugt in der Sprache des aktuellen Shops und mit https.
     *
     * @return array{url: string, languageId: ?string, currencyId: ?string}|null
     */
    private function domain(string $salesChannelId, string $languageId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT d.`url`, LOWER(HEX(d.`language_id`)) AS language_id, LOWER(HEX(d.`currency_id`)) AS currency_id
             FROM `sales_channel_domain` d
             INNER JOIN `sales_channel` sc ON sc.`id` = d.`sales_channel_id` AND sc.`active` = 1
             WHERE d.`sales_channel_id` = :sc
             ORDER BY (d.`language_id` = :language) DESC, (d.`url` LIKE \'https://%\') DESC, d.`created_at`
             LIMIT 1',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'language' => Uuid::fromHexToBytes($languageId)]
        );

        if ($row === false || !\is_string($row['url']) || !preg_match('#^https?://#', $row['url'])) {
            return null;
        }

        return [
            'url' => $row['url'],
            'languageId' => \is_string($row['language_id']) ? $row['language_id'] : null,
            'currencyId' => \is_string($row['currency_id']) ? $row['currency_id'] : null,
        ];
    }
}
