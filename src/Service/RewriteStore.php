<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Speichert Umschreibungen für Suchbegriffe ohne Treffer dauerhaft – so muss jeder Begriff
 * nur ein einziges Mal von Claude ausgewertet werden.
 *
 * Status: pending (wartet auf Auswertung), done (Umschreibung vorhanden),
 *         none (keine sinnvolle Umschreibung gefunden), error (mehrfach fehlgeschlagen),
 *         review (gelernte Korrektur wird neu geprüft, bis dahin inaktiv),
 *         disabled (deaktiviert – im Admin oder weil die Prüfung fehlschlug; wird nicht neu gelernt)
 */
class RewriteStore
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_NONE = 'none';
    public const STATUS_ERROR = 'error';
    public const STATUS_REVIEW = 'review';
    public const STATUS_DISABLED = 'disabled';

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

    /**
     * Wie markPending(), aber nur für neue Begriffe und mit Mengenbegrenzung (Schutz der Claude-Warteschlange).
     * Ohne Begrenzer wird nichts vorgemerkt.
     */
    public function queue(string $salesChannelId, string $term, ?TrafficLimiter $limiter): void
    {
        if ($limiter === null || !TermNormalizer::isTrackable(TermNormalizer::normalize($term))
            || $this->isKnown($salesChannelId, $term)
            || !$limiter->allow(TrafficLimiter::PENDING, $salesChannelId)) {
            return;
        }

        $this->markPending($salesChannelId, $term);
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
        // Häufig gesuchte Begriffe zuerst – einmalige Zufallseingaben kommen erst danach dran
        /** @var list<array{sc: string, term: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(r.`sales_channel_id`)) AS sc, r.`term` FROM `nova_search_rewrite` r
             LEFT JOIN `nova_search_term` t ON t.`sales_channel_id` = r.`sales_channel_id` AND t.`term` = r.`term`
             WHERE r.`status` = :status ORDER BY COALESCE(t.`searches`, 0) DESC, r.`created_at` ASC LIMIT 500',
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
     * Umschreibungen, die seit 30 Tagen nicht geprüft wurden (das Sortiment kann sich geändert haben),
     * und gelernte Korrekturen, die neu geprüft werden müssen (sofort).
     *
     * @return list<array{sc: string, term: string, status: string, rewrite: string|null, source: string|null}>
     */
    public function dueForRecheck(int $limit): array
    {
        /** @var list<array{sc: string, term: string, status: string, rewrite: string|null, source: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term`, `status`, `rewrite`, `source` FROM `nova_search_rewrite`
             WHERE `status` = :review OR (`status` IN (:done, :none) AND COALESCE(`updated_at`, `created_at`) < :before)
             ORDER BY `status` = :review DESC, COALESCE(`updated_at`, `created_at`) ASC LIMIT ' . max(1, $limit),
            [
                'review' => self::STATUS_REVIEW,
                'done' => self::STATUS_DONE,
                'none' => self::STATUS_NONE,
                'before' => (new \DateTimeImmutable('-30 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]
        );

        return $rows;
    }

    /** Bestandene Neuprüfung einer gelernten Korrektur: wieder aktiv */
    public function confirm(string $salesChannelId, string $term, int $hits): void
    {
        $this->update($salesChannelId, $term, ['status' => self::STATUS_DONE, 'hits' => $hits]);
    }

    /** Umschreibung abschalten; der Begriff wird danach weder neu gelernt noch an Claude gegeben. */
    public function disable(string $salesChannelId, string $term): void
    {
        $this->update($salesChannelId, $term, ['status' => self::STATUS_DISABLED]);
    }

    /**
     * Gespeicherte Umschreibungen für die Administration (aktive, in Prüfung, deaktivierte).
     *
     * @return list<array{salesChannelId: string, term: string, rewrite: string|null, status: string, source: string, hits: int, updatedAt: string}>
     */
    public function listRewrites(?string $salesChannelId, int $limit = 200): array
    {
        $where = '`status` IN (:statuses)';
        $params = ['statuses' => [self::STATUS_DONE, self::STATUS_REVIEW, self::STATUS_DISABLED]];
        $types = ['statuses' => ArrayParameterType::STRING];
        if ($salesChannelId !== null) {
            $where .= ' AND `sales_channel_id` = :sc';
            $params['sc'] = Uuid::fromHexToBytes($salesChannelId);
        }

        /** @var list<array{sc: string, term: string, rewrite: string|null, status: string, source: string|null, hits: int|string, changed: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term`, `rewrite`, `status`, `source`, `hits`,
                    COALESCE(`updated_at`, `created_at`) AS changed
             FROM `nova_search_rewrite` WHERE ' . $where . '
             ORDER BY `status` = :disabled ASC, changed DESC LIMIT ' . max(1, min($limit, 500)),
            $params + ['disabled' => self::STATUS_DISABLED],
            $types
        );

        return array_map(static fn (array $row): array => [
            'salesChannelId' => $row['sc'],
            'term' => $row['term'],
            'rewrite' => $row['rewrite'],
            'status' => $row['status'],
            'source' => $row['source'] === self::SOURCE_LEARNED ? self::SOURCE_LEARNED : 'claude',
            'hits' => (int) $row['hits'],
            'updatedAt' => $row['changed'],
        ], $rows);
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
     * Zählt eine beobachtete Korrektur („vergser“ ohne Klick → „vergaser“ mit Klick) – je Besucher und
     * Tag höchstens einmal (prüft der Aufrufer) – und an wie vielen verschiedenen Tagen sie vorkam.
     * Übernommen wird sie erst im Hintergrund nach einer Trefferprüfung (siehe learnCandidates()).
     *
     * @return int bisherige Anzahl dieses Paares
     */
    public function recordRefinement(string $salesChannelId, string $term, string $target): int
    {
        $params = [
            'sc' => Uuid::fromHexToBytes($salesChannelId),
            'term' => TermNormalizer::normalize($term),
            'target' => TermNormalizer::normalize($target),
            'today' => (new \DateTimeImmutable())->format('Y-m-d'),
            'now' => $this->now(),
        ];

        // `days` vor `last_day` setzen: MySQL wertet die Zuweisungen von links nach rechts aus
        $this->connection->executeStatement(
            'INSERT INTO `nova_search_refinement` (`sales_channel_id`, `term`, `target`, `count`, `days`, `last_day`, `created_at`)
             VALUES (:sc, :term, :target, 1, 1, :today, :now)
             ON DUPLICATE KEY UPDATE `count` = `count` + 1, `days` = `days` + IF(`last_day` <=> :today, 0, 1),
                                     `last_day` = :today, `updated_at` = :now',
            $params
        );

        return (int) $this->connection->fetchOne(
            'SELECT `count` FROM `nova_search_refinement` WHERE `sales_channel_id` = :sc AND `term` = :term AND `target` = :target',
            $params
        );
    }

    /**
     * Beobachtete Korrekturen, die oft genug und an genug verschiedenen Tagen vorkamen und seit der
     * letzten Beobachtung nicht geprüft wurden – häufigste zuerst.
     *
     * @return list<array{sc: string, term: string, target: string, count: int}>
     */
    public function learnCandidates(int $minCount, int $minDays, int $limit): array
    {
        /** @var list<array{sc: string, term: string, target: string, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS sc, `term`, `target`, `count` FROM `nova_search_refinement`
             WHERE `count` >= :count AND `days` >= :days
               AND (`checked_at` IS NULL OR `checked_at` < COALESCE(`updated_at`, `created_at`))
             ORDER BY `count` DESC LIMIT ' . max(1, $limit),
            ['count' => max(1, $minCount), 'days' => max(1, $minDays)]
        );

        return array_map(static fn (array $row): array => [
            'sc' => $row['sc'],
            'term' => $row['term'],
            'target' => $row['target'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    public function markRefinementChecked(string $salesChannelId, string $term, string $target): void
    {
        $this->connection->executeStatement(
            'UPDATE `nova_search_refinement` SET `checked_at` = :now
             WHERE `sales_channel_id` = :sc AND `term` = :term AND `target` = :target',
            ['now' => $this->now(), 'sc' => Uuid::fromHexToBytes($salesChannelId), 'term' => $term, 'target' => $target]
        );
    }

    /**
     * Gelernte Korrektur als Umschreibung speichern. Eine von Claude gefundene oder eine deaktivierte
     * Umschreibung bleibt bestehen.
     */
    public function saveLearned(string $salesChannelId, string $term, string $target, int $hits = 0): void
    {
        $term = TermNormalizer::normalize($term);
        $target = mb_substr(TermNormalizer::normalize($target), 0, TermNormalizer::MAX_LENGTH);
        $sc = Uuid::fromHexToBytes($salesChannelId);

        $row = $this->connection->fetchAssociative(
            'SELECT `status`, `source` FROM `nova_search_rewrite` WHERE `sales_channel_id` = :sc AND `term` = :term',
            ['sc' => $sc, 'term' => $term]
        );
        if ($row !== false && ($row['status'] === self::STATUS_DISABLED
            || ($row['status'] === self::STATUS_DONE && $row['source'] !== self::SOURCE_LEARNED))) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO `nova_search_rewrite` (`sales_channel_id`, `term`, `rewrite`, `status`, `source`, `hits`, `created_at`)
             VALUES (:sc, :term, :rewrite, :status, :source, :hits, :now)
             ON DUPLICATE KEY UPDATE `rewrite` = :rewrite, `status` = :status, `source` = :source, `hits` = :hits, `updated_at` = :now',
            ['sc' => $sc, 'term' => $term, 'rewrite' => $target, 'status' => self::STATUS_DONE, 'source' => self::SOURCE_LEARNED, 'hits' => max(0, $hits), 'now' => $this->now()]
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

    /**
     * Datensparsamkeit und Schutz vor Füllen der Tabellen: alte Beobachtungen und nie gelöste Begriffe entfernen.
     */
    public function cleanup(): void
    {
        $before = (new \DateTimeImmutable('-180 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->executeStatement(
            'DELETE FROM `nova_search_refinement` WHERE COALESCE(`updated_at`, `created_at`) < :before',
            ['before' => $before]
        );
        $this->connection->executeStatement(
            'DELETE FROM `nova_search_rewrite` WHERE `status` IN (:statuses) AND COALESCE(`updated_at`, `created_at`) < :before',
            ['statuses' => [self::STATUS_PENDING, self::STATUS_NONE, self::STATUS_ERROR], 'before' => $before],
            ['statuses' => ArrayParameterType::STRING]
        );
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
