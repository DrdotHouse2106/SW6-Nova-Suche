<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Speicher für die „Rückmeldung zum Suchergebnis“ (Tabelle nova_search_feedback).
 *
 * Gespeichert werden nur Verkaufskanal, normalisierter Suchbegriff, serverseitig ermittelte Trefferzahl,
 * Bewertung, optionaler Freitext, Quelle und Datum – keine IP, keine Kunden- oder Sitzungsdaten.
 * Einträge werden nach 365 Tagen gelöscht.
 */
class SearchFeedback
{
    public const RETENTION_DAYS = 365;
    private const TERM_LIMIT = 100;
    private const TEXT_LIMIT = 200;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Legt eine Bewertung an und gibt die ID (hex) zurück */
    public function add(string $salesChannelId, string $term, string $rating, ?string $text, string $source, ?int $hits): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('nova_search_feedback', [
            'id' => Uuid::fromHexToBytes($id),
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'term' => mb_substr($term, 0, TermNormalizer::MAX_LENGTH),
            'rating' => $rating,
            'hits' => $hits !== null ? max(0, $hits) : null,
            'text' => $text,
            'source' => $source,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    /** Freitext nachreichen (nur bei „Daumen runter“ und nur, solange noch keiner gespeichert ist) */
    public function attachText(string $id, string $text): void
    {
        $this->connection->executeStatement(
            'UPDATE `nova_search_feedback` SET `text` = :text, `updated_at` = :now
             WHERE `id` = :id AND `rating` = :down AND `text` IS NULL',
            [
                'text' => $text,
                'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'id' => Uuid::fromHexToBytes($id),
                'down' => FeedbackText::RATING_DOWN,
            ]
        );
    }

    public function delete(string $id): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM `nova_search_feedback` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );
    }

    /**
     * Für die Auswertung: Bewertungen je Begriff und die Freitexte (neueste zuerst).
     *
     * @return array{up: int, down: int, texts: int, terms: list<array<string, mixed>>, entries: list<array<string, mixed>>}
     */
    public function overview(?string $salesChannelId, int $days): array
    {
        $days = max(1, min(self::RETENTION_DAYS, $days));
        $where = '`f`.`created_at` >= :since';
        $params = ['since' => (new \DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format(Defaults::STORAGE_DATE_TIME_FORMAT)];
        if ($salesChannelId !== null) {
            $where .= ' AND `f`.`sales_channel_id` = :sc';
            $params['sc'] = Uuid::fromHexToBytes($salesChannelId);
        }

        $totals = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(`rating` = \'up\'), 0) AS up, COALESCE(SUM(`rating` = \'down\'), 0) AS down,
                    COUNT(`text`) AS texts
             FROM `nova_search_feedback` `f` WHERE ' . $where,
            $params
        ) ?: [];

        // Trefferzahl: die der jüngsten Bewertung (das Sortiment ändert sich)
        $terms = $this->connection->fetchAllAssociative(
            'SELECT `f`.`term`, SUM(`f`.`rating` = \'up\') AS up, SUM(`f`.`rating` = \'down\') AS down,
                    COUNT(`f`.`text`) AS texts, MAX(`f`.`created_at`) AS last,
                    SUBSTRING_INDEX(GROUP_CONCAT(`f`.`hits` ORDER BY `f`.`created_at` DESC SEPARATOR \',\'), \',\', 1) AS hits
             FROM `nova_search_feedback` `f` WHERE ' . $where . '
             GROUP BY `f`.`term`
             ORDER BY down DESC, up DESC, last DESC LIMIT ' . self::TERM_LIMIT,
            $params
        );

        $entries = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`f`.`id`)) AS id, LOWER(HEX(`f`.`sales_channel_id`)) AS sales_channel_id,
                    COALESCE(`sct`.`name`, LOWER(HEX(`f`.`sales_channel_id`))) AS sales_channel,
                    `f`.`term`, `f`.`rating`, `f`.`hits`, `f`.`text`, `f`.`source`, `f`.`created_at`
             FROM `nova_search_feedback` `f`
             LEFT JOIN `sales_channel` `sc` ON `sc`.`id` = `f`.`sales_channel_id`
             LEFT JOIN `sales_channel_translation` `sct` ON `sct`.`sales_channel_id` = `sc`.`id` AND `sct`.`language_id` = `sc`.`language_id`
             WHERE ' . $where . ' AND `f`.`text` IS NOT NULL
             ORDER BY `f`.`created_at` DESC LIMIT ' . self::TEXT_LIMIT,
            $params
        );

        return [
            'up' => (int) ($totals['up'] ?? 0),
            'down' => (int) ($totals['down'] ?? 0),
            'texts' => (int) ($totals['texts'] ?? 0),
            'terms' => array_map(static fn (array $row): array => [
                'term' => (string) $row['term'],
                'up' => (int) $row['up'],
                'down' => (int) $row['down'],
                'texts' => (int) $row['texts'],
                'hits' => $row['hits'] !== null && $row['hits'] !== '' ? (int) $row['hits'] : null,
                'last' => (string) $row['last'],
            ], $terms),
            'entries' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'salesChannelId' => (string) $row['sales_channel_id'],
                'salesChannel' => (string) $row['sales_channel'],
                'term' => (string) $row['term'],
                'rating' => (string) $row['rating'],
                'hits' => $row['hits'] !== null ? (int) $row['hits'] : null,
                'text' => (string) $row['text'],
                'source' => (string) $row['source'],
                'createdAt' => (string) $row['created_at'],
            ], $entries),
        ];
    }

    /** Aufbewahrung: Rückmeldungen nach 365 Tagen löschen */
    public function cleanup(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `nova_search_feedback` WHERE `created_at` < :before',
            ['before' => (new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );
    }
}
