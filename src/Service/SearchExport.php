<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Liest die gesammelten Suchdaten für den CSV-Export aus.
 *
 * Das Plugin speichert nur Suchbegriffe, Produkt-IDs und Zähler. Ausnahme: Der Freitext der
 * Rückmeldungen ist eine freie Eingabe und kann personenbezogene Daten enthalten.
 */
class SearchExport
{
    public const TYPE_TERMS = 'terms';
    public const TYPE_PRODUCTS = 'products';
    public const TYPE_REWRITES = 'rewrites';
    public const TYPE_CLAUDE_USAGE = 'claude-usage';
    public const TYPE_FEEDBACK = 'feedback';
    public const TYPES = [self::TYPE_TERMS, self::TYPE_PRODUCTS, self::TYPE_REWRITES, self::TYPE_CLAUDE_USAGE, self::TYPE_FEEDBACK];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * CSV mit UTF-8-BOM (Excel zeigt Umlaute korrekt); Kundeneingaben werden gegen Formel-Ausführung entschärft.
     *
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    public static function toCsv(array $columns, array $rows, string $delimiter = ';'): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Temporärer Speicher nicht verfügbar.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns, $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), array_values($row)), $delimiter, '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Suchbegriffe sind Kundeneingaben: Werte, die Excel als Formel ausführen würde
     * (=, +, -, @, Tab, CR), werden mit einem Apostroph entschärft.
     */
    private static function cell(mixed $value): string
    {
        $value = \is_scalar($value) ? (string) $value : '';

        if ($value !== '' && !is_numeric($value) && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param list<string> $salesChannelIds Leer = alle Verkaufskanäle
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function fetch(string $type, array $salesChannelIds = [], ?int $days = null, int $minSearches = 0): array
    {
        return match ($type) {
            self::TYPE_TERMS => $this->terms($salesChannelIds, $days, $minSearches),
            self::TYPE_PRODUCTS => $this->products($salesChannelIds, $days),
            self::TYPE_REWRITES => $this->rewrites($salesChannelIds, $days),
            self::TYPE_CLAUDE_USAGE => $this->claudeUsage($days),
            self::TYPE_FEEDBACK => $this->feedback($salesChannelIds, $days),
            default => throw new \InvalidArgumentException(\sprintf('Unbekannter Export-Typ „%s“', $type)),
        };
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function terms(array $salesChannelIds, ?int $days, int $minSearches): array
    {
        [$where, $params, $types] = $this->conditions('t', $salesChannelIds, $days);
        if ($minSearches > 0) {
            $where[] = 't.searches >= :minSearches';
            $params['minSearches'] = $minSearches;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $this->salesChannelColumns('t') . ',
                    t.term AS suchbegriff,
                    t.searches AS suchen,
                    t.last_hits AS treffer_zuletzt,
                    t.clicks AS klicks,
                    t.carts AS warenkorb,
                    t.orders AS kaeufe,
                    ROUND(IF(t.searches > 0, t.clicks / t.searches * 100, 0), 1) AS klickrate_prozent,
                    t.created_at AS erstmals,
                    COALESCE(t.updated_at, t.created_at) AS zuletzt
             FROM nova_search_term t
             ' . $this->salesChannelJoin('t') . '
             ' . $this->whereSql($where) . '
             ORDER BY verkaufskanal, t.searches DESC, t.term',
            $params,
            $types
        );

        return ['columns' => $this->columns($rows, ['verkaufskanal', 'verkaufskanal_id', 'suchbegriff', 'suchen', 'treffer_zuletzt', 'klicks', 'warenkorb', 'kaeufe', 'klickrate_prozent', 'erstmals', 'zuletzt']), 'rows' => $rows];
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function products(array $salesChannelIds, ?int $days): array
    {
        [$where, $params, $types] = $this->conditions('tp', $salesChannelIds, $days);
        $params['liveVersion'] = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        // Varianten erben den Namen vom Hauptprodukt
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $this->salesChannelColumns('tp') . ',
                    tp.term AS suchbegriff,
                    p.product_number AS produktnummer,
                    COALESCE(pt.name, ppt.name) AS produktname,
                    LOWER(HEX(tp.product_id)) AS produkt_id,
                    tp.clicks AS klicks,
                    tp.carts AS warenkorb,
                    tp.orders AS kaeufe,
                    COALESCE(tp.updated_at, tp.created_at) AS zuletzt
             FROM nova_search_term_product tp
             ' . $this->salesChannelJoin('tp') . '
             LEFT JOIN product p ON p.id = tp.product_id AND p.version_id = :liveVersion
             LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = sc.language_id
             LEFT JOIN product_translation ppt ON ppt.product_id = p.parent_id AND ppt.product_version_id = p.version_id AND ppt.language_id = sc.language_id
             ' . $this->whereSql($where) . '
             ORDER BY verkaufskanal, tp.term, (tp.orders * 5 + tp.carts * 3 + tp.clicks) DESC',
            $params,
            $types
        );

        return ['columns' => $this->columns($rows, ['verkaufskanal', 'verkaufskanal_id', 'suchbegriff', 'produktnummer', 'produktname', 'produkt_id', 'klicks', 'warenkorb', 'kaeufe', 'zuletzt']), 'rows' => $rows];
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function rewrites(array $salesChannelIds, ?int $days): array
    {
        [$where, $params, $types] = $this->conditions('r', $salesChannelIds, $days);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $this->salesChannelColumns('r') . ',
                    r.term AS suchbegriff,
                    r.rewrite AS umschreibung,
                    r.status AS status,
                    r.hits AS treffer,
                    r.attempts AS versuche,
                    r.created_at AS erstmals,
                    COALESCE(r.updated_at, r.created_at) AS zuletzt
             FROM nova_search_rewrite r
             ' . $this->salesChannelJoin('r') . '
             ' . $this->whereSql($where) . '
             ORDER BY verkaufskanal, r.status, r.term',
            $params,
            $types
        );

        return ['columns' => $this->columns($rows, ['verkaufskanal', 'verkaufskanal_id', 'suchbegriff', 'umschreibung', 'status', 'treffer', 'versuche', 'erstmals', 'zuletzt']), 'rows' => $rows];
    }

    /**
     * Claude-Verbrauch je Monat und Modell. Gilt für alle Verkaufskanäle zusammen (ein Kostenlimit).
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function claudeUsage(?int $days): array
    {
        $where = '';
        $params = [];
        if ($days !== null && $days > 0) {
            $where = 'WHERE `month` >= :since';
            $params['since'] = (new \DateTimeImmutable(\sprintf('-%d days', $days), new \DateTimeZone('UTC')))->format('Y-m');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT `month` AS monat,
                    `model` AS modell,
                    `requests` AS anfragen,
                    `input_tokens` AS input_tokens,
                    `output_tokens` AS output_tokens,
                    `cache_read_tokens` AS cache_lesen_tokens,
                    `cache_write_tokens` AS cache_schreiben_tokens,
                    ROUND(`cost_micro_usd` / 1000000, 6) AS kosten_usd
             FROM `nova_search_claude_usage`
             ' . $where . '
             ORDER BY `month` DESC, `cost_micro_usd` DESC',
            $params
        );

        return ['columns' => $this->columns($rows, ['monat', 'modell', 'anfragen', 'input_tokens', 'output_tokens', 'cache_lesen_tokens', 'cache_schreiben_tokens', 'kosten_usd']), 'rows' => $rows];
    }

    /**
     * Rückmeldungen zum Suchergebnis (neueste zuerst). Der Text ist eine Kundeneingabe – toCsv() entschärft
     * Formeln, Steuerzeichen wurden schon beim Speichern entfernt.
     *
     * @param list<string> $salesChannelIds
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function feedback(array $salesChannelIds, ?int $days): array
    {
        [$where, $params, $types] = $this->conditions('f', $salesChannelIds, null);
        if ($days !== null && $days > 0) {
            $where[] = 'f.created_at >= :since';
            $params['since'] = (new \DateTimeImmutable(\sprintf('-%d days', $days)))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $this->salesChannelColumns('f') . ',
                    f.created_at AS datum,
                    f.term AS suchbegriff,
                    f.rating AS bewertung,
                    f.hits AS treffer,
                    f.source AS quelle,
                    f.text AS text
             FROM nova_search_feedback f
             ' . $this->salesChannelJoin('f') . '
             ' . $this->whereSql($where) . '
             ORDER BY f.created_at DESC',
            $params,
            $types
        );

        return ['columns' => $this->columns($rows, ['verkaufskanal', 'verkaufskanal_id', 'datum', 'suchbegriff', 'bewertung', 'treffer', 'quelle', 'text']), 'rows' => $rows];
    }

    private function salesChannelColumns(string $alias): string
    {
        return "COALESCE(sct.name, LOWER(HEX($alias.sales_channel_id))) AS verkaufskanal, LOWER(HEX($alias.sales_channel_id)) AS verkaufskanal_id";
    }

    private function salesChannelJoin(string $alias): string
    {
        return "LEFT JOIN sales_channel sc ON sc.id = $alias.sales_channel_id
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id";
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    private function conditions(string $alias, array $salesChannelIds, ?int $days): array
    {
        $where = [];
        $params = [];
        $types = [];

        if ($salesChannelIds !== []) {
            $where[] = "$alias.sales_channel_id IN (:salesChannelIds)";
            $params['salesChannelIds'] = Uuid::fromHexToBytesList($salesChannelIds);
            $types['salesChannelIds'] = ArrayParameterType::BINARY;
        }

        if ($days !== null && $days > 0) {
            $where[] = "COALESCE($alias.updated_at, $alias.created_at) >= :since";
            $params['since'] = (new \DateTimeImmutable(\sprintf('-%d days', $days)))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        }

        return [$where, $params, $types];
    }

    /**
     * @param list<string> $where
     */
    private function whereSql(array $where): string
    {
        return $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $default Spalten, falls keine Zeilen vorhanden sind
     *
     * @return list<string>
     */
    private function columns(array $rows, array $default): array
    {
        return $rows === [] ? $default : array_keys($rows[0]);
    }
}
