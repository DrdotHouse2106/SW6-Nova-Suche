<?php declare(strict_types=1);

namespace Nova\Search\Service;

/**
 * Prüft und bereinigt Eingaben der „Rückmeldung zum Suchergebnis“ (Daumen hoch/runter, optionaler Text).
 * Bewusst ohne Abhängigkeiten, damit es sich auch außerhalb von Shopware testen lässt.
 */
final class FeedbackText
{
    public const MAX_LENGTH = 200;

    public const RATING_UP = 'up';
    public const RATING_DOWN = 'down';

    public const SOURCE_LAYER = 'layer';
    public const SOURCE_PAGE = 'page';
    public const SOURCE_APP = 'app';

    /**
     * Freitext: nur gültiges UTF-8, Steuer- und unsichtbare Formatzeichen (z. B. Richtungswechsel,
     * Zeilenumbrüche, Null-Bytes) entfernt, Leerraum zusammengefasst, getrimmt, max. 200 Zeichen.
     * Leer = kein Text. HTML wird nicht entfernt, sondern überall als reiner Text ausgegeben.
     */
    public static function clean(mixed $text): ?string
    {
        if (!\is_string($text) || $text === '' || \strlen($text) > self::MAX_LENGTH * 8) {
            return null;
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        // Zeilenumbrüche und Tabulatoren werden zu Leerzeichen, alle übrigen Steuer- und Formatzeichen fallen weg
        $text = (string) preg_replace('/[\r\n\t\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', $text);
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Co}]+/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        $text = rtrim($text);

        return $text === '' ? null : $text;
    }

    public static function rating(mixed $rating): ?string
    {
        return \is_string($rating) && \in_array($rating, [self::RATING_UP, self::RATING_DOWN], true) ? $rating : null;
    }

    /**
     * @param list<string> $allowed
     */
    public static function source(mixed $source, array $allowed): ?string
    {
        return \is_string($source) && \in_array($source, $allowed, true) ? $source : null;
    }
}
