<?php declare(strict_types=1);

namespace Nova\Search\Search;

/**
 * Reine Regeln der Wortteile-Suche (ohne Datenbank, testbar ohne Shopware):
 * welche Suchwörter erweitert werden und in welche Teile ein zusammengesetztes Wort zerlegt werden kann.
 */
final class WordParts
{
    public const MIN_INFIX_LENGTH = 5;
    public const MIN_SPLIT_LENGTH = 8;
    public const MIN_SPLIT_PART = 4;

    /**
     * Höchstens so viele Wörter je Suche werden erweitert (jedes kostet Abfragen im Suchwort-Verzeichnis) –
     * echte Suchen haben selten mehr als drei lange Wörter, lange Zufallseingaben bleiben so günstig.
     */
    public const MAX_EXTENDED_TOKENS = 4;

    /** Wörter ab 5 Buchstaben ohne Ziffer finden auch Wörter, die sie mittendrin enthalten */
    public static function eligible(string $token): bool
    {
        return mb_strlen($token) >= self::MIN_INFIX_LENGTH && preg_match('/\d/', $token) !== 1;
    }

    /**
     * Mögliche Zerlegungen „erster Teil => [erster Teil, Rest]“, längster erster Teil zuerst.
     * Ein Fugen-s am ersten Teil wird zusätzlich abgetrennt („überholungs|satz“ → „überholung“).
     *
     * @return array<string, array{string, string}>
     */
    public static function splitCandidates(string $token): array
    {
        $length = mb_strlen($token);
        if ($length < self::MIN_SPLIT_LENGTH || preg_match('/\d/', $token) === 1) {
            return [];
        }

        $candidates = [];
        for ($i = $length - self::MIN_SPLIT_PART; $i >= self::MIN_SPLIT_PART; --$i) {
            $head = mb_substr($token, 0, $i);
            $tail = mb_substr($token, $i);
            $candidates[$head] ??= [$head, $tail];
            if (str_ends_with($head, 's') && mb_strlen($head) > self::MIN_SPLIT_PART) {
                $candidates[mb_substr($head, 0, -1)] ??= [mb_substr($head, 0, -1), $tail];
            }
        }

        return $candidates;
    }

    /** Schlüssel für den Objekt-Cache (nur erlaubte PSR-6-Zeichen) */
    public static function cacheKey(string $key): string
    {
        return 'nova-search-wordpart-' . hash('sha256', $key);
    }
}
