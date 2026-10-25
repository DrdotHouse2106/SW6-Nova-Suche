<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Speichert Umschreibungen für Suchbegriffe ohne Treffer dauerhaft – so muss jeder Begriff
 * nur ein einziges Mal von Claude ausgewertet werden.
 *
 * Status: pending (wartet auf Auswertung), done (Umschreibung vorhanden),
 *         none (keine sinnvolle Umschreibung gefunden), error (mehrfach fehlgeschlagen)
 */
class RewriteStore
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_NONE = 'none';
    public const STATUS_ERROR = 'error';

    public const MAX_ATTEMPTS = 3;

    /** Umschreibung aus dem Verhalten der Besucher gelernt (statt von Claude) */
    public const SOURCE_LEARNED = 'learned';

    /** @var array<string, string|null> Zwischenspeicher pro Request */
    private array $memo = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $salesChannelId, string $term): ?string
    {
        $term = TermNormalizer::normalize($term);
        $key = $salesChannelId . '|' . $term;

        if (!\array_key_exists($key, $this->memo)) {
            $rewrite = $this->connection->fetchOne(
                'SELECT `rewrite` FROM `nova_search_rewrite` WHERE `sales_channel_id` = :sc AND `term` = :term AND `status` = :status',
                ['sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => $term, 'status' => self::STATUS_DONE]
            );
            $this->memo[$key] = \is_string($rewrite) && $rewrite !== '' ? $rewrite : null;
        }

        return $this->memo[$key];
    }

    /** Merkt einen Begriff ohne Treffer für die nächste Auswertung vor (bereits bekannte bleiben unverändert). */
    public function markPending(string $salesChannelId, string $term): void
    {
        $term = TermNormalizer::normalize($term);
        if (!TermNormalizer::isTrackable($term)) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT IGNORE INTO `nova_search_rewrite` (`sales_channel_id`, `term`, `status`, `created_at`)
             VALUES (:sc, :term, :status, :now)',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => $term, 'status' => self::STATUS_PENDING, 'now' => $this->now()]
        );
    }

    public function isKnown(string $salesChannelId, string $term): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM `nova_search_rewrite` WHERE `sales_channel_id` = :sc AND `term` = :term',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => TermNormalizer::normalize($term)]
        ) !== false;
    }

    /**
     * @return array<string, list<string>> Verkaufskanal-ID (hex) => offene Begriffe
     */
    public function pending(int $limitPerSalesChannel): array
    {
        /** @var list<array{sc: string, term: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term` FROM `nova_search_rewrite`
             WHERE `status` = :status ORDER BY `created_at` ASC LIMIT 500',
            ['status' => self::STATUS_PENDING]
        );

        $grouped = [];
        foreach ($rows as $row) {
            if (\count($grouped[$row['sc']] ?? []) < $limitPerSalesChannel) {
                $grouped[$row['sc']][] = $row['term'];
            }
        }

        return $grouped;
    }

    /**
     * Umschreibungen, die seit 30 Tagen nicht geprüft wurden (das Sortiment kann sich geändert haben).
     *
     * @return list<array{sc: string, term: string, status: string}>
     */
    public function dueForRecheck(int $limit): array
    {
        /** @var list<array{sc: string, term: string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term`, `status` FROM `nova_search_rewrite`
             WHERE `status` IN (:done, :none) AND COALESCE(`updated_at`, `created_at`) < :before
               AND (`source` IS NULL OR `source` <> :learned)
             ORDER BY COALESCE(`updated_at`, `created_at`) ASC LIMIT ' . max(1, $limit),
            [
                'done' => self::STATUS_DONE,
                'none' => self::STATUS_NONE,
                'learned' => self::SOURCE_LEARNED,
                'before' => (new \DateTimeImmutable('-30 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        return $rows;
    }

    public function saveRewrite(string $salesChannelId, string $term, string $rewrite, int $hits): void
    {
        $this->update($salesChannelId, $term, [
            'rewrite' => mb_substr(TermNormalizer::normalize($rewrite), 0, TermNormalizer::MAX_LENGTH),
            'status' => self::STATUS_DONE,
            'hits' => $hits,
        ]);
    }

    public function markNone(string $salesChannelId, string $term): void
    {
        $this->update($salesChannelId, $term, ['rewrite' => null, 'status' => self::STATUS_NONE, 'hits' => 0]);
    }

    /** Fehlversuch zählen; nach MAX_ATTEMPTS wird der Begriff nicht mehr angefragt. */
    public function markFailed(string $salesChannelId, string $term): void
    {
        $this->connection->executeStatement(
            'UPDATE `nova_search_rewrite`
             SET `attempts` = `attempts` + 1,
                 `status` = IF(`attempts` >= :max, :error, `status`),
                 `updated_at` = :now
             WHERE `sales_channel_id` = :sc AND `term` = :term',
            [
                'max' => self::MAX_ATTEMPTS,
                'error' => self::STATUS_ERROR,
                'now' => $this->now(),
                'sc' => Uuid::fromHexToBytes($salesChannelId),
                'term' => TermNormalizer::normalize($term),
            ]
        );
    }

    /**
     * Zählt eine beobachtete Korrektur („vergser“ ohne Klick → „vergaser“ mit Klick).
     *
     * @return int bisherige Anzahl dieses Paares
     */
    public function recordRefinement(string $salesChannelId, string $term, string $target): int
    {
        $params = [
            'sc' => Uuid::fromHexToBytes($salesChannelId),
            'term' => TermNormalizer::normalize($term),
            'target' => TermNormalizer::normalize($target),
            'now' => $this->now(),
        ];

        $this->connection->executeStatement(
            'INSERT INTO `nova_search_refinement` (`sales_channel_id`, `term`, `target`, `count`, `created_at`)
             VALUES (:sc, :term, :target, 1, :now)
             ON DUPLICATE KEY UPDATE `count` = `count` + 1, `updated_at` = :now',
            $params
        );

        return (int) $this->connection->fetchOne(
            'SELECT `count` FROM `nova_search_refinement` WHERE `sales_channel_id` = :sc AND `term` = :term AND `target` = :target',
            $params
        );
    }

    /**
     * Gelernte Korrektur als Umschreibung speichern. Eine von Claude gefundene Umschreibung bleibt bestehen.
     */
    public function saveLearned(string $salesChannelId, string $term, string $target): void
    {
        $term = TermNormalizer::normalize($term);
        $target = mb_substr(TermNormalizer::normalize($target), 0, TermNormalizer::MAX_LENGTH);
        $sc = Uuid::fromHexToBytes($salesChannelId);

        $row = $this->connection->fetchAssociative(
            'SELECT `status`, `source` FROM `nova_search_rewrite` WHERE `sales_channel_id` = :sc AND `term` = :term',
            ['sc' => $sc, 'term' => $term]
        );
        if ($row !== false && $row['status'] === self::STATUS_DONE && $row['source'] !== self::SOURCE_LEARNED) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO `nova_search_rewrite` (`sales_channel_id`, `term`, `rewrite`, `status`, `source`, `created_at`)
             VALUES (:sc, :term, :rewrite, :status, :source, :now)
             ON DUPLICATE KEY UPDATE `rewrite` = :rewrite, `status` = :status, `source` = :source, `updated_at` = :now',
            ['sc' => $sc, 'term' => $term, 'rewrite' => $target, 'status' => self::STATUS_DONE, 'source' => self::SOURCE_LEARNED, 'now' => $this->now()]
        );
        unset($this->memo[$salesChannelId . '|' . $term]);
    }

    /** Begriff findet inzwischen selbst Treffer → Umschreibung entfernen */
    public function delete(string $salesChannelId, string $term): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `nova_search_rewrite` WHERE `sales_channel_id` = :sc AND `term` = :term',
            ['sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => TermNormalizer::normalize($term)]
        );
        unset($this->memo[$salesChannelId . '|' . TermNormalizer::normalize($term)]);
    }

    public function touch(string $salesChannelId, string $term): void
    {
        $this->update($salesChannelId, $term, []);
    }

    /**
     * @param array<string, string|int|null> $values
     */
    private function update(string $salesChannelId, string $term, array $values): void
    {
        $values['updated_at'] = $this->now();
        $set = implode(', ', array_map(static fn (string $column): string => "`{$column}` = :{$column}", array_keys($values)));

        $this->connection->executeStatement(
            "UPDATE `nova_search_rewrite` SET {$set} WHERE `sales_channel_id` = :sc AND `term` = :term",
            $values + ['sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => TermNormalizer::normalize($term)]
        );

        unset($this->memo[$salesChannelId . '|' . TermNormalizer::normalize($term)]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
