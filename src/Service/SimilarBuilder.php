<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * „Ähnliche Artikel“: Artikel derselben Gruppe (Standard: Zusatzfeld „Teileart“, verglichen werden die ersten
 * N Ziffern – Standard 1, also die Hauptgruppe wie „6“ für alle 6xx) als Shopware-Cross-Selling, die
 * meistverkauften zuerst. Erscheint auf der Produktseite als weiterer
 * Reiter neben dem festen Cross-Selling; ein Fahrzeugfilter (z. B. Nova Fahrzeugauswahl) wirkt bei der Anzeige.
 */
class SimilarBuilder
{
    public const PREFIX_ID = 'nova-search-similar-';
    private const CONFIG = 'NovaSearch.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly CrossSellingWriter $writer,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{enabled: bool, products: int, removed: int}
     */
    public function rebuild(): array
    {
        $enabled = $this->config->get(self::CONFIG . 'similar') === true;
        $max = max(1, $this->config->getInt(self::CONFIG . 'similarMax') ?: 12);
        $title = trim($this->config->getString(self::CONFIG . 'similarTitle'));
        $title = $title !== '' ? $title : 'Ähnliche Artikel';
        $field = trim($this->config->getString(self::CONFIG . 'similarField'));
        $field = preg_match('/^[A-Za-z0-9_]+$/', $field) === 1 ? $field : 'nova_teileart';

        $digits = $this->config->getInt(self::CONFIG . 'similarDigits') ?: 1;
        $groups = $enabled ? self::group($this->rows($field), $max, $digits) : [];
        $result = $this->writer->write(self::PREFIX_ID, $groups, $title, 90, $max);

        if ($enabled) {
            $this->logger->info('Nova Suche: „Ähnliche Artikel“ aktualisiert', $result);
        }

        return ['enabled' => $enabled] + $result;
    }

    /**
     * @return list<array{id: string, code: string, sales: int}>
     */
    private function rows(string $field): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(p.id)) AS id,
                    MAX(JSON_UNQUOTE(JSON_EXTRACT(pt.custom_fields, :path))) AS code,
                    MAX(p.sales) AS sales
             FROM product p
             INNER JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id
             WHERE p.parent_id IS NULL AND p.version_id = :live AND p.active = 1
               AND JSON_EXTRACT(pt.custom_fields, :path) IS NOT NULL
             GROUP BY p.id',
            ['path' => '$."' . $field . '"', 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'code' => (string) $r['code'],
            'sales' => (int) $r['sales'],
        ], $rows);
    }

    /**
     * Gruppiert nach den ersten $digits führenden Ziffern des Merkmals (ohne Ziffern: ganzer Wert, kleingeschrieben).
     *
     * @param list<array{id: string, code: string, sales: int}> $rows
     *
     * @return array<string, list<string>>
     */
    public static function group(array $rows, int $max, int $digits = 1): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $code = preg_match('/^\s*(\d+)/', $row['code'], $m) === 1
                ? substr($m[1], 0, max(1, $digits))
                : mb_strtolower(trim($row['code']));
            if ($code === '' || $code === 'null') {
                continue;
            }
            $byCode[$code][] = $row;
        }

        $result = [];
        foreach ($byCode as $members) {
            if (\count($members) < 2) {
                continue;
            }
            usort($members, static fn (array $a, array $b): int => $b['sales'] <=> $a['sales'] ?: strcmp($a['id'], $b['id']));
            $ids = array_column($members, 'id');
            foreach ($ids as $id) {
                $others = array_values(array_filter($ids, static fn (string $other): bool => $other !== $id));
                $result[$id] = \array_slice($others, 0, $max);
            }
        }

        return $result;
    }
}
