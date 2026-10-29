<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zählt Suchen, Klicks, Warenkorb-Aktionen und Käufe je Suchbegriff (anonym, ohne Personenbezug)
 * und liefert daraus beliebte Suchanfragen und die „gelernten“ Produkte für einen Begriff.
 */
class SearchStatistics
{
    public const TYPE_CLICK = 'click';
    public const TYPE_CART = 'cart';
    public const TYPE_ORDER = 'order';

    private const COLUMNS = [
        self::TYPE_CLICK => 'clicks',
        self::TYPE_CART => 'carts',
        self::TYPE_ORDER => 'orders',
    ];

    /** Gewichtung der Signale: Kauf > Warenkorb > Klick */
    private const WEIGHTS = ['clicks' => 1, 'carts' => 3, 'orders' => 5];

    /** „Beliebt“ erst, wenn an so vielen verschiedenen Tagen gesucht */
    public const POPULAR_MIN_DAYS = 3;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function recordSearch(string $salesChannelId, string $term, int $hits): void
    {
        $term = TermNormalizer::normalize($term);
        if (!TermNormalizer::isTrackable($term)) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO `nova_search_term` (`sales_channel_id`, `term`, `searches`, `last_hits`, `created_at`, `updated_at`)
             VALUES (:sc, :term, 1, :hits, :now, :now)
             ON DUPLICATE KEY UPDATE `searches` = `searches` + 1, `last_hits` = VALUES(`last_hits`), `updated_at` = VALUES(`updated_at`)',
            [
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'term' => $term,
                'hits' => max(0, min($hits, 1_000_000)),
                'now' => $this->now(),
            ]
        );

        // Tageswert für die Auswertung
        $this->connection->executeStatement(
            'INSERT INTO `nova_search_term_day` (`sales_channel_id`, `day`, `term`, `searches`, `zero_results`)
             VALUES (:sc, :day, :term, 1, :zero)
             ON DUPLICATE KEY UPDATE `searches` = `searches` + 1, `zero_results` = `zero_results` + VALUES(`zero_results`)',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'day' => $this->today(), 'term' => $term, 'zero' => $hits === 0 ? 1 : 0]
        );
    }

    public function recordProduct(string $salesChannelId, string $term, string $productId, string $type): void
    {
        $term = TermNormalizer::normalize($term);
        $column = self::COLUMNS[$type] ?? null;
        if ($column === null || !TermNormalizer::isTrackable($term) || !Uuid::isValid($productId)) {
            return;
        }

        $params = [
            'sc' => Uuid::fromHexToBytes($salesChannelId),
            'term' => $term,
            'product' => Uuid::fromHexToBytes($productId),
            'now' => $this->now(),
        ];

        // $column stammt ausschließlich aus der festen Liste oben
        $this->connection->executeStatement(
            "INSERT INTO `nova_search_term_product` (`sales_channel_id`, `term`, `product_id`, `{$column}`, `created_at`, `updated_at`)
             VALUES (:sc, :term, :product, 1, :now, :now)
             ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + 1, `updated_at` = VALUES(`updated_at`)",
            $params
        );

        $this->connection->executeStatement(
            "INSERT INTO `nova_search_term` (`sales_channel_id`, `term`, `{$column}`, `created_at`, `updated_at`)
             VALUES (:sc, :term, 1, :now, :now)
             ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + 1, `updated_at` = VALUES(`updated_at`)",
            $params
        );

        // Tageswerte für die Auswertung
        $day = ['sc' => $params['sc'], 'day' => $this->today(), 'term' => $term, 'product' => $params['product']];
        $this->connection->executeStatement(
            "INSERT INTO `nova_search_term_day` (`sales_channel_id`, `day`, `term`, `{$column}`)
             VALUES (:sc, :day, :term, 1)
             ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + 1",
            $day
        );
        $this->connection->executeStatement(
            "INSERT INTO `nova_search_product_day` (`sales_channel_id`, `day`, `product_id`, `{$column}`)
             VALUES (:sc, :day, :product, 1)
             ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + 1",
            $day
        );
    }

    /**
     * Produkte, die bei genau diesem Begriff oft geklickt, in den Warenkorb gelegt oder gekauft wurden.
     *
     * @return array<string, int> Produkt-ID (hex) => Lernwert, absteigend sortiert
     */
    public function learnedProducts(string $salesChannelId, string $term, int $minScore, int $limit = 20): array
    {
        $term = TermNormalizer::normalize($term);
        if ($term === '') {
            return [];
        }

        $score = \sprintf(
            '(`clicks` * %d + `carts` * %d + `orders` * %d)',
            self::WEIGHTS['clicks'],
            self::WEIGHTS['carts'],
            self::WEIGHTS['orders']
        );

        /** @var list<array{id: string, score: string|int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(`product_id`)) AS id, {$score} AS score
             FROM `nova_search_term_product`
             WHERE `sales_channel_id` = :sc AND `term` = :term AND `verified` = 1 AND {$score} >= :min
             ORDER BY score DESC
             LIMIT " . max(1, min($limit, 50)),
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => $term, 'min' => max(1, $minScore)]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = (int) $row['score'];
        }

        return $result;
    }

    /**
     * Häufigste Suchbegriffe der letzten 90 Tage – nur mit serverseitig geprüften Treffern (verified_hits,
     * nicht die Angabe aus dem Browser) und an mindestens POPULAR_MIN_DAYS verschiedenen Tagen gesucht.
     *
     * @param list<string> $blocklist
     *
     * @return list<string>
     */
    public function popularTerms(string $salesChannelId, int $minSearches, int $limit, array $blocklist = []): array
    {
        /** @var list<string> $terms */
        $terms = $this->connection->fetchFirstColumn(
            'SELECT t.`term` FROM `nova_search_term` t
             WHERE t.`sales_channel_id` = :sc AND t.`verified_hits` > 0 AND t.`searches` >= :min AND t.`updated_at` >= :since
               AND (SELECT COUNT(*) FROM `nova_search_term_day` d
                    WHERE d.`sales_channel_id` = t.`sales_channel_id` AND d.`day` >= :sinceDay AND d.`term` = t.`term` AND d.`searches` > 0) >= :days
             ORDER BY t.`searches` DESC, t.`term` ASC
             LIMIT 50',
            [
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'min' => max(1, $minSearches),
                'since' => (new \DateTimeImmutable('-90 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'sinceDay' => (new \DateTimeImmutable('-90 days'))->format('Y-m-d'),
                'days' => self::POPULAR_MIN_DAYS,
            ]
        );

        $blocklist = array_values(array_filter(array_map(TermNormalizer::normalize(...), $blocklist)));
        $terms = array_filter($terms, static function (string $term) use ($blocklist): bool {
            foreach ($blocklist as $blocked) {
                if (str_contains($term, $blocked)) {
                    return false;
                }
            }

            return true;
        });

        return \array_slice(array_values($terms), 0, $limit);
    }

    /**
     * Löscht Einträge, die seit über einem Jahr nicht mehr vorkamen (Datensparsamkeit).
     */
    public function cleanup(): void
    {
        $before = (new \DateTimeImmutable('-365 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->executeStatement('DELETE FROM `nova_search_term_product` WHERE `updated_at` < :before', ['before' => $before]);
        $this->connection->executeStatement('DELETE FROM `nova_search_term` WHERE `updated_at` < :before', ['before' => $before]);
        // Tageswerte: 2 Jahre (für den Vorjahresvergleich)
        $beforeDay = (new \DateTimeImmutable('-730 days'))->format('Y-m-d');
        $this->connection->executeStatement('DELETE FROM `nova_search_term_day` WHERE `day` < :before', ['before' => $beforeDay]);
        $this->connection->executeStatement('DELETE FROM `nova_search_product_day` WHERE `day` < :before', ['before' => $beforeDay]);
    }

    /**
     * Begriffe, deren Trefferzahl serverseitig (neu) geprüft werden soll: oft gesucht, noch nie oder
     * seit einem Tag nicht geprüft.
     *
     * @return list<array{sc: string, term: string}>
     */
    public function termsToVerify(int $limit): array
    {
        /** @var list<array{sc: string, term: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term` FROM `nova_search_term`
             WHERE `searches` >= 2 AND `updated_at` >= :since AND (`verified_at` IS NULL OR `verified_at` < :stale)
             ORDER BY `searches` DESC LIMIT ' . max(1, $limit),
            [
                'since' => (new \DateTimeImmutable('-90 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'stale' => (new \DateTimeImmutable('-1 day'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        return $rows;
    }

    public function setVerifiedHits(string $salesChannelId, string $term, int $hits): void
    {
        $this->connection->executeStatement(
            'UPDATE `nova_search_term` SET `verified_hits` = :hits, `verified_at` = :now WHERE `sales_channel_id` = :sc AND `term` = :term',
            ['hits' => max(0, min($hits, 1_000_000)), 'now' => $this->now(), 'sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => $term]
        );
    }

    /**
     * Begriff × Produkt-Paare, bei denen noch nicht (oder seit 30 Tagen nicht) geprüft wurde, ob die
     * Suche das Produkt bei diesem Begriff überhaupt findet – stärkste Signale zuerst.
     *
     * @return list<array{sc: string, term: string, productId: string}>
     */
    public function productsToVerify(int $limit): array
    {
        /** @var list<array{sc: string, term: string, productId: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term`, LOWER(HEX(`product_id`)) AS productId
             FROM `nova_search_term_product`
             WHERE `verified_at` IS NULL OR `verified_at` < :stale
             ORDER BY (`clicks` + 3 * `carts` + 5 * `orders`) DESC LIMIT ' . max(1, $limit),
            ['stale' => (new \DateTimeImmutable('-30 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );

        return $rows;
    }

    public function setProductVerified(string $salesChannelId, string $term, string $productId, bool $verified): void
    {
        $this->connection->executeStatement(
            'UPDATE `nova_search_term_product` SET `verified` = :verified, `verified_at` = :now
             WHERE `sales_channel_id` = :sc AND `term` = :term AND `product_id` = :product',
            [
                'verified' => $verified ? 1 : 0,
                'now' => $this->now(),
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'term' => $term,
                'product' => Uuid::fromHexToBytes($productId),
            ]
        );
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<string> nur die IDs, die als Produkt existieren
     */
    public function existingProductIds(array $productIds): array
    {
        $productIds = array_values(array_filter($productIds, Uuid::isValid(...)));
        if ($productIds === []) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(`id`)) FROM `product` WHERE `id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($productIds)],
            ['ids' => ArrayParameterType::BINARY]
        );

        return $ids;
    }

    /**
     * Welche dieser Produkte hat der Kunde in den letzten Stunden tatsächlich bestellt?
     * (Prüfung für Käufe, die die App meldet – nur das Ergebnis wird verwendet, nichts gespeichert.)
     *
     * @param list<string> $productIds
     *
     * @return list<string>
     */
    public function recentlyOrderedProductIds(string $customerId, array $productIds, int $hours): array
    {
        $productIds = array_values(array_filter($productIds, Uuid::isValid(...)));
        if ($productIds === [] || !Uuid::isValid($customerId)) {
            return [];
        }

        $since = (new \DateTimeImmutable(sprintf('-%d hours', max(1, $hours))))->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(li.`product_id`))
             FROM `order_line_item` li
             INNER JOIN `order` o ON o.`id` = li.`order_id` AND o.`version_id` = li.`order_version_id`
             INNER JOIN `order_customer` oc ON oc.`order_id` = o.`id` AND oc.`order_version_id` = o.`version_id`
             WHERE oc.`customer_id` = :customer
               AND o.`version_id` = :version
               AND o.`created_at` >= :since
               AND li.`product_id` IN (:products)',
            [
                'customer' => Uuid::fromHexToBytes($customerId),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'since' => $since,
                'products' => Uuid::fromHexToBytesList($productIds),
            ],
            ['products' => ArrayParameterType::BINARY]
        );

        return $ids;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
