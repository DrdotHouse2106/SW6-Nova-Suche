<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Schreibt erzeugte Cross-Sellings („Kunden kauften auch“, „Ähnliche Artikel“) mit fester, aus Präfix und
 * Artikel-ID abgeleiteter ID: Zuordnungen werden ersetzt, Artikel ohne Ergebnis verlieren ihr Cross-Selling,
 * eigene (von Hand angelegte) Cross-Sellings bleiben unberührt.
 */
class CrossSellingWriter
{
    private const BATCH = 100;

    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingCollection> $crossSellingRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $crossSellingRepository,
    ) {
    }

    public static function id(string $prefix, string $productId): string
    {
        return Uuid::fromStringToHex($prefix . strtolower($productId));
    }

    /**
     * @param array<string, list<string>> $groups Hauptartikel-ID → Artikel-IDs in Anzeigereihenfolge
     *
     * @return array{products: int, removed: int}
     */
    public function write(string $prefix, array $groups, string $title, int $position, int $limit): array
    {
        $context = Context::createDefaultContext();
        $removed = $this->removeStale($prefix, array_keys($groups), $context);

        foreach (array_chunk($groups, self::BATCH, true) as $chunk) {
            $ids = array_map(static fn (string $source): string => self::id($prefix, $source), array_keys($chunk));
            $this->connection->executeStatement(
                'DELETE FROM product_cross_selling_assigned_products WHERE cross_selling_id IN (:ids)',
                ['ids' => Uuid::fromHexToBytesList($ids)],
                ['ids' => ArrayParameterType::BINARY]
            );

            $payload = [];
            foreach ($chunk as $source => $targets) {
                $crossSellingId = self::id($prefix, $source);
                $assigned = [];
                foreach ($targets as $index => $target) {
                    $assigned[] = [
                        'id' => Uuid::fromStringToHex($crossSellingId . '-' . $target),
                        'productId' => $target,
                        'position' => $index + 1,
                    ];
                }
                $payload[] = [
                    'id' => $crossSellingId,
                    'productId' => $source,
                    'name' => $title,
                    'position' => $position,
                    'type' => 'productList',
                    'active' => true,
                    'limit' => $limit,
                    'assignedProducts' => $assigned,
                ];
            }
            $this->crossSellingRepository->upsert($payload, $context);
        }

        return ['products' => \count($groups), 'removed' => $removed];
    }

    /**
     * @param list<string> $keep Hauptartikel-IDs mit aktuellem Ergebnis
     */
    private function removeStale(string $prefix, array $keep, Context $context): int
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
                    $candidates[] = self::id($prefix, (string) $productId);
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
