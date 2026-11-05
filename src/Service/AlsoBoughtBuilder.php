<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * „Kunden kauften auch“ aus den eigenen Bestellungen.
 *
 * Zählt, welche Artikel zusammen in einer Bestellung gekauft wurden (stornierte Bestellungen nicht,
 * Varianten zählen beim Hauptartikel), und legt je Artikel ein Shopware-Cross-Selling „Kunden kauften auch“
 * an (Typ „Manuelle Zuordnung“, nach Häufigkeit sortiert). Die Anzeige übernimmt Shopware selbst –
 * mit allen Regeln für Sichtbarkeit, Abverkauf und z. B. einem Fahrzeugfilter.
 *
 * Das Cross-Selling hat eine feste, aus der Artikel-ID abgeleitete ID: Ein Lauf ersetzt die Zuordnungen,
 * Artikel ohne Treffer verlieren ihr Cross-Selling, eigene Cross-Sellings bleiben unberührt.
 */
class AlsoBoughtBuilder
{
    private const PREFIX = 'NovaSearch.config.';
    private const BATCH = 100;

    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingCollection> $crossSellingRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $crossSellingRepository,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function crossSellingId(string $productId): string
    {
        return Uuid::fromStringToHex('nova-search-also-bought-' . strtolower($productId));
    }

    /**
     * @return array{enabled: bool, products: int, removed: int}
     */
    public function rebuild(): array
    {
        $context = Context::createDefaultContext();
        $enabled = $this->config->get(self::PREFIX . 'alsoBought') === true;

        $groups = $enabled ? $this->compute() : [];
        $removed = $this->removeStale(array_keys($groups), $context);

        $title = trim($this->config->getString(self::PREFIX . 'alsoBoughtTitle'));
        $title = $title !== '' ? $title : 'Kunden kauften auch';
        $limit = max(1, $this->config->getInt(self::PREFIX . 'alsoBoughtMax') ?: 12);

        foreach (array_chunk($groups, self::BATCH, true) as $chunk) {
            $ids = array_map(static fn (string $source): string => self::crossSellingId($source), array_keys($chunk));
            // Alte Zuordnungen ersetzen statt anhängen
            $this->connection->executeStatement(
                'DELETE FROM product_cross_selling_assigned_products WHERE cross_selling_id IN (:ids)',
                ['ids' => Uuid::fromHexToBytesList($ids)],
                ['ids' => ArrayParameterType::BINARY]
            );

            $payload = [];
            foreach ($chunk as $source => $targets) {
                $crossSellingId = self::crossSellingId($source);
                $assigned = [];
                foreach ($targets as $position => $target) {
                    $assigned[] = [
                        'id' => Uuid::fromStringToHex($crossSellingId . '-' . $target),
                        'productId' => $target,
                        'position' => $position + 1,
                    ];
                }
                $payload[] = [
                    'id' => $crossSellingId,
                    'productId' => $source,
                    'name' => $title,
                    'position' => 100,
                    'type' => 'productList',
                    'active' => true,
                    'limit' => $limit,
                    'assignedProducts' => $assigned,
                ];
            }
            $this->crossSellingRepository->upsert($payload, $context);
        }

        if ($enabled) {
            $this->logger->info('Nova Suche: „Kunden kauften auch“ aktualisiert', ['products' => \count($groups), 'removed' => $removed]);
        }

        return ['enabled' => $enabled, 'products' => \count($groups), 'removed' => $removed];
    }

    /**
     * Hauptartikel-ID → nach Häufigkeit sortierte Artikel-IDs (je Hauptartikel die meistgekaufte Variante).
     *
     * @return array<string, list<string>>
     */
    private function compute(): array
    {
        $months = max(1, $this->config->getInt(self::PREFIX . 'alsoBoughtMonths') ?: 36);
        $minOrders = max(1, $this->config->getInt(self::PREFIX . 'alsoBoughtMinOrders') ?: 1);
        $max = max(1, $this->config->getInt(self::PREFIX . 'alsoBoughtMax') ?: 12);
        $exclude = $this->config->get(self::PREFIX . 'alsoBoughtExclude');
        $exclude = \is_array($exclude) ? array_values(array_filter($exclude, static fn ($id): bool => \is_string($id) && Uuid::isValid($id))) : [];

        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $since = (new \DateTimeImmutable('-' . $months . ' months'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        // Je Bestellung und Hauptartikel eine Zeile (die gekaufte Variante als Vertreter).
        // Zwei Kopien, weil MySQL eine temporäre Tabelle nicht zweimal in einer Abfrage öffnen kann.
        foreach (['nova_also_bought_a', 'nova_also_bought_b'] as $table) {
            $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
            $this->connection->executeStatement('CREATE TEMPORARY TABLE `' . $table . '` (
                order_id BINARY(16) NOT NULL, pid BINARY(16) NOT NULL, prod BINARY(16) NOT NULL,
                PRIMARY KEY (order_id, pid)) ENGINE=InnoDB');
        }

        $excludeSql = '';
        $params = ['live' => $live, 'since' => $since];
        $types = [];
        if ($exclude !== []) {
            $excludeSql = ' AND COALESCE(p.parent_id, p.id) NOT IN (:exclude)';
            $params['exclude'] = Uuid::fromHexToBytesList($exclude);
            $types['exclude'] = ArrayParameterType::BINARY;
        }

        try {
            $this->connection->executeStatement(
                'INSERT IGNORE INTO nova_also_bought_a (order_id, pid, prod)
                 SELECT oli.order_id, COALESCE(p.parent_id, p.id), p.id
                 FROM order_line_item oli
                 INNER JOIN `order` o ON o.id = oli.order_id AND o.version_id = oli.order_version_id
                 INNER JOIN state_machine_state s ON s.id = o.state_id
                 INNER JOIN product p ON p.id = oli.product_id AND p.version_id = :live
                 WHERE oli.order_version_id = :live
                   AND oli.type = \'product\'
                   AND oli.product_id IS NOT NULL
                   AND o.order_date_time >= :since
                   AND s.technical_name <> \'cancelled\'' . $excludeSql,
                $params,
                $types
            );
            $this->connection->executeStatement('INSERT INTO nova_also_bought_b SELECT * FROM nova_also_bought_a');

            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(a.pid)) AS source, LOWER(HEX(b.pid)) AS target_parent, LOWER(HEX(b.prod)) AS target, COUNT(*) AS orders
                 FROM nova_also_bought_a a
                 INNER JOIN nova_also_bought_b b ON b.order_id = a.order_id AND b.pid <> a.pid
                 GROUP BY a.pid, b.pid, b.prod'
            );
        } finally {
            $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS nova_also_bought_a');
            $this->connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS nova_also_bought_b');
        }

        return self::rank($rows, $minOrders, $max);
    }

    /**
     * @param list<array<string, mixed>> $rows source, target_parent, target, orders
     *
     * @return array<string, list<string>>
     */
    public static function rank(array $rows, int $minOrders, int $max): array
    {
        /** @var array<string, array<string, array{orders: int, best: string, bestOrders: int}>> $pairs */
        $pairs = [];
        foreach ($rows as $row) {
            $source = (string) $row['source'];
            $parent = (string) $row['target_parent'];
            $target = (string) $row['target'];
            $orders = (int) $row['orders'];
            $pair = $pairs[$source][$parent] ?? ['orders' => 0, 'best' => $target, 'bestOrders' => 0];
            $pair['orders'] += $orders;
            if ($orders > $pair['bestOrders']) {
                $pair['best'] = $target;
                $pair['bestOrders'] = $orders;
            }
            $pairs[$source][$parent] = $pair;
        }

        $result = [];
        foreach ($pairs as $source => $targets) {
            $targets = array_filter($targets, static fn (array $t): bool => $t['orders'] >= $minOrders);
            if ($targets === []) {
                continue;
            }
            // Häufigste zuerst, bei Gleichstand stabil nach ID
            uksort($targets, static fn (string $a, string $b): int => $targets[$b]['orders'] <=> $targets[$a]['orders'] ?: strcmp($a, $b));
            $result[$source] = array_values(array_map(static fn (array $t): string => $t['best'], \array_slice($targets, 0, $max, true)));
        }

        return $result;
    }

    /**
     * Entfernt eigene Cross-Sellings von Artikeln, die keine Treffer mehr haben.
     *
     * @param list<string> $keep Hauptartikel-IDs mit aktuellem Ergebnis
     */
    private function removeStale(array $keep, Context $context): int
    {
        $keep = array_fill_keys($keep, true);
        $removed = 0;
        $offset = 0;
        do {
            $productIds = $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(id)) FROM product WHERE parent_id IS NULL AND version_id = :live ORDER BY auto_increment LIMIT 1000 OFFSET ' . $offset,
                ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );
            $offset += 1000;

            $candidates = [];
            foreach ($productIds as $productId) {
                if (!isset($keep[(string) $productId])) {
                    $candidates[] = self::crossSellingId((string) $productId);
                }
            }
            if ($candidates === []) {
                continue;
            }
            $existing = $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(id)) FROM product_cross_selling WHERE id IN (:ids)',
                ['ids' => Uuid::fromHexToBytesList($candidates)],
                ['ids' => ArrayParameterType::BINARY]
            );
            if ($existing !== []) {
                $this->crossSellingRepository->delete(array_map(static fn ($id): array => ['id' => (string) $id], $existing), $context);
                $removed += \count($existing);
            }
        } while (\count($productIds) === 1000);

        return $removed;
    }
}
