<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Produkt-IDs für „Aktuelle Highlights“ und Empfehlungen bei 0 Treffern.
 *
 * Modi: manual (von Hand gewählt), bestseller (in den meisten Bestellungen, 90 Tage), popular (in der Suche am
 * häufigsten geklickt/gekauft, 90 Tage), newest (zuletzt angelegt). Ergebnisse werden eine Stunde
 * zwischengespeichert; Sichtbarkeit und Verfügbarkeit prüft anschließend die normale Produktsuche.
 */
class HighlightProvider
{
    public const MODES = ['manual', 'bestseller', 'popular', 'newest'];

    private const LIMIT = 24;
    private const DAYS = 90;
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param list<string> $manualIds
     *
     * @return list<string> hex, beste zuerst
     */
    public function ids(string $mode, string $salesChannelId, array $manualIds): array
    {
        if ($mode === 'manual' || !\in_array($mode, self::MODES, true)) {
            return $manualIds;
        }

        // Nur die automatische Liste wird zwischengespeichert – die gewählten Produkte kommen immer frisch dazu
        $item = $this->cache->getItem('nova_search_highlights_' . $mode . '_' . $salesChannelId);
        if ($item->isHit() && \is_array($item->get())) {
            /** @var list<string> $automatic */
            $automatic = $item->get();
        } else {
            try {
                $automatic = match ($mode) {
                    'bestseller' => $this->bestsellers($salesChannelId),
                    'popular' => $this->popular($salesChannelId),
                    default => $this->newest(),
                };
            } catch (\Throwable) {
                // Fehlende Tabellen o. Ä. – dann nur die von Hand gewählten Produkte
                return $manualIds;
            }

            $item->set($automatic);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        // Von Hand gewählte Produkte zuerst, dann die automatischen
        return array_values(array_unique(array_merge($manualIds, $automatic)));
    }

    /**
     * @return list<string>
     */
    private function bestsellers(string $salesChannelId): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(oli.`product_id`))
             FROM `order_line_item` oli
             INNER JOIN `order` o ON o.`id` = oli.`order_id` AND o.`version_id` = oli.`order_version_id`
             WHERE oli.`version_id` = :version AND oli.`product_id` IS NOT NULL AND oli.`type` = :type
               AND o.`sales_channel_id` = :sc AND o.`order_date_time` >= :since
             GROUP BY oli.`product_id`
             -- Anzahl Bestellungen zählt, nicht die Stückzahl: 100 Schrauben in einer Bestellung sind ein Kauf
             ORDER BY COUNT(DISTINCT oli.`order_id`) DESC, SUM(oli.`quantity`) DESC
             LIMIT ' . self::LIMIT,
            [
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'type' => 'product',
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'since' => $this->since(),
            ]
        ));
    }

    /**
     * @return list<string>
     */
    private function popular(string $salesChannelId): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(`product_id`))
             FROM `nova_search_term_product`
             WHERE `sales_channel_id` = :sc AND `verified` = 1 AND COALESCE(`updated_at`, `created_at`) >= :since
             GROUP BY `product_id`
             ORDER BY SUM(`clicks` + 3 * `carts` + 5 * `orders`) DESC
             LIMIT ' . self::LIMIT,
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'since' => $this->since()]
        ));
    }

    /**
     * @return list<string>
     */
    private function newest(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(`id`)) FROM `product`
             WHERE `version_id` = :version AND `parent_id` IS NULL AND `active` = 1
             ORDER BY `created_at` DESC
             LIMIT ' . self::LIMIT,
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        ));
    }

    private function since(): string
    {
        return (new \DateTimeImmutable('-' . self::DAYS . ' days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
