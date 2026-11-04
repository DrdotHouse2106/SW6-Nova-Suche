<?php declare(strict_types=1);

namespace Nova\Search\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchTermInterpreterInterface;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\SearchConfigLoader;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Filter\AbstractTokenFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\SearchPattern;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\SearchTerm;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\TokenizerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Wortteile: Shopware findet ein Suchwort nur am Anfang oder Ende eines Wortes im Shop.
 * „vergaser“ findet deshalb „Vergaserdichtung“ und „Doppelvergaser“, aber nicht
 * „Doppelvergasers“ (Genitiv) oder „Vergaserüberholsatzes“. Diese Erweiterung ergänzt:
 *
 * 1. Wörter ab 5 Buchstaben finden auch Wörter, die sie mittendrin enthalten
 *    („vergaser“ → „doppelvergasers“, „einzelvergasers“).
 * 2. Ein zusammengesetztes Suchwort ohne Treffer wird in zwei Teile zerlegt, die beide
 *    passen müssen („vergasersatz“ → „vergaser“ + „satz“, „überholungssatz“ → „überholung“ + „satz“).
 *    Der erste Teil muss ein Wort aus dem Shop sein, so entstehen keine Zufallstreffer
 *    wie „vergas“ + „ersatz“.
 *
 * Teilwort-Treffer zählen weniger als echte Treffer, die Reihenfolge bleibt also erhalten.
 *
 * Last: Die Abfragen im Suchwort-Verzeichnis (LIKE '%…%') werden je Sprache und Wortteil eine Stunde im
 * Objekt-Cache gehalten (neue Wörter aus dem Suchindex wirken also spätestens nach einer Stunde), und je
 * Suche werden höchstens WordParts::MAX_EXTENDED_TOKENS Wörter erweitert. Setzt der Aufrufer am Request
 * das Attribut SKIP (Suchfenster über der Grenze je IP), bleibt die Suche unverändert.
 */
class WordPartTermInterpreter implements ProductSearchTermInterpreterInterface, ResetInterface
{
    /** Request-Attribut: Wortteile für diese Anfrage auslassen */
    public const SKIP = 'nova-search-skip-word-parts';

    private const MAX_KEYWORDS = 60;
    private const MAX_SPLIT_KEYWORDS = 300;
    private const ENDINGS = ['', 's', 'es', 'e', 'en', 'n'];
    private const MAX_SCORED = 10;
    private const SCORE = 0.3;
    private const CACHE_SECONDS = 3600;

    /** @var array<string, list<string>> */
    private array $infixCache = [];

    public function __construct(
        private readonly ProductSearchTermInterpreterInterface $decorated,
        private readonly Connection $connection,
        private readonly TokenizerInterface $tokenizer,
        private readonly AbstractTokenFilter $tokenFilter,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ?SearchConfigLoader $configLoader = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function interpret(string $word, Context $context): SearchPattern
    {
        $pattern = $this->decorated->interpret($word, $context);

        try {
            $source = $context->getSource();
            $salesChannelId = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;
            if ($this->config->get('NovaSearch.config.wordParts', $salesChannelId) === false
                || $this->requestStack?->getMainRequest()?->attributes->getBoolean(self::SKIP) === true) {
                return $pattern;
            }

            return $this->extend($pattern, $word, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Suche: Wortteile konnten nicht ergänzt werden', ['exception' => $e]);

            return $pattern;
        }
    }

    public function reset(): void
    {
        $this->infixCache = [];
    }

    private function extend(SearchPattern $pattern, string $word, Context $context): SearchPattern
    {
        $tokens = self::strings($this->tokenFilter->filter($this->tokenize($word, $context), $context));
        $tokenTerms = $pattern->getTokenTerms();

        // Nur wenn die Wörter genau zu Shopwares Wortgruppen passen – sonst lieber nichts ändern
        if ($tokens === [] || \count($tokens) !== \count($tokenTerms)) {
            return $pattern;
        }
        $tokenTerms = array_values($tokenTerms);

        $languageId = $context->getLanguageId();
        $result = [];
        $added = [];
        $changed = false;
        $extended = 0;

        foreach ($tokens as $index => $token) {
            $keywords = $tokenTerms[$index] ?? [];

            // Jedes erweiterte Wort kostet Abfragen – lange Zufallseingaben nicht beliebig teuer werden lassen
            if ($extended >= WordParts::MAX_EXTENDED_TOKENS || !WordParts::eligible($token)) {
                $result[] = $keywords;
                continue;
            }
            ++$extended;

            if ($keywords === [] && ($parts = $this->split($token, $languageId)) !== null) {
                // Zusammengesetztes Wort ohne Treffer: beide Teile müssen passen
                foreach ($parts as $partKeywords) {
                    $result[] = $partKeywords;
                    $added = array_merge($added, $partKeywords);
                }
                $changed = true;

                continue;
            }

            $infix = $this->containing($token, $languageId);
            $new = array_values(array_diff($infix, $keywords));
            if ($new !== []) {
                $keywords = array_values(array_unique(array_merge($keywords, $new)));
                $added = array_merge($added, $new);
                $changed = true;
            }
            $result[] = $keywords;
        }

        if (!$changed) {
            return $pattern;
        }

        $extendedPattern = new SearchPattern($pattern->getOriginal());
        $extendedPattern->setBooleanClause($pattern->getBooleanClause() === SearchPattern::BOOLEAN_CLAUSE_AND);
        $extendedPattern->setTokenTerms($result);

        $known = [];
        foreach ($pattern->getTerms() as $term) {
            $known[$term->getTerm()] = true;
            $extendedPattern->addTerm($term);
        }

        // Kürzeste Wörter zuerst: „doppelvergasers“ ist näher an „vergaser“ als „vergaserüberholsatzes“
        $added = array_values(array_unique($added));
        usort($added, static fn (string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));
        $scored = 0;
        foreach ($added as $keyword) {
            if (isset($known[$keyword])) {
                continue;
            }
            $extendedPattern->addTerm(new SearchTerm($keyword, self::SCORE));
            if (++$scored >= self::MAX_SCORED) {
                break;
            }
        }

        return $extendedPattern;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $word, Context $context): array
    {
        $minLength = null;
        if ($this->configLoader !== null) {
            $config = $this->configLoader->load($context);
            $minLength = $config[0]['min_search_length'] ?? null;
        }

        /** @phpstan-ignore arguments.count (zweiter Parameter ab Shopware 6.6 vorhanden, in der Signatur noch auskommentiert) */
        return self::strings($this->tokenizer->tokenize($word, $minLength));
    }

    /**
     * Fortlaufend nummerierte Zeichenketten – Token-Filter anderer Erweiterungen behalten mitunter die Schlüssel.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $strings[] = \is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }

    /**
     * Zerlegt ein zusammengesetztes Wort in einen bekannten ersten Teil und einen Rest, der im Shop vorkommt.
     * Der längste bekannte erste Teil gewinnt („vergaser“ vor „verga“). Ein Fugen-s wird abgetrennt.
     *
     * @return array{list<string>, list<string>}|null
     */
    private function split(string $token, string $languageId): ?array
    {
        $candidates = WordParts::splitCandidates($token);
        if ($candidates === []) {
            return null;
        }

        $known = $this->cached($languageId . '|split|' . $token, fn (): array => $this->connection->fetchFirstColumn(
            'SELECT `keyword` FROM `product_keyword_dictionary` WHERE `language_id` = :language AND `keyword` IN (:keywords)',
            ['language' => Uuid::fromHexToBytes($languageId), 'keywords' => array_map('strval', array_keys($candidates))],
            ['keywords' => ArrayParameterType::STRING]
        ));
        if ($known === []) {
            return null;
        }

        usort($known, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($known as $head) {
            if (!isset($candidates[$head])) {
                continue;
            }
            [$head, $tail] = $candidates[$head];

            // Der Rest steht am Wortende („komplettsatz“, „dichtungssatz“), nicht mittendrin („ersatzteil“)
            $tailKeywords = $this->endingWith($tail, $languageId);
            if ($tailKeywords === []) {
                continue;
            }

            return [$this->containing($head, $languageId, self::MAX_SPLIT_KEYWORDS), $tailKeywords];
        }

        return null;
    }

    /**
     * Wörter, die mit dem Teil enden, auch mit üblicher Endung („satz“ → „komplettsatz“, „dichtsatzes“).
     *
     * @return list<string>
     */
    private function endingWith(string $part, string $languageId): array
    {
        return $this->cached($languageId . '|end|' . $part, function () use ($part, $languageId): array {
            $escaped = addcslashes($part, '%_\\');
            $patterns = [];
            $params = ['language' => Uuid::fromHexToBytes($languageId)];
            foreach (self::ENDINGS as $n => $ending) {
                $patterns[] = '`keyword` LIKE :p' . $n;
                $params['p' . $n] = '%' . $escaped . $ending;
            }

            return $this->connection->fetchFirstColumn(
                'SELECT `keyword` FROM `product_keyword_dictionary`
                 WHERE `language_id` = :language AND (' . implode(' OR ', $patterns) . ')
                 ORDER BY CHAR_LENGTH(`keyword`), `keyword`
                 LIMIT ' . self::MAX_SPLIT_KEYWORDS,
                $params
            );
        });
    }

    /**
     * Wörter aus dem Suchwort-Verzeichnis des Shops, die den Teil enthalten (kürzeste zuerst).
     *
     * @return list<string>
     */
    private function containing(string $part, string $languageId, int $limit = self::MAX_KEYWORDS): array
    {
        return $this->cached($languageId . '|' . $limit . '|' . $part, fn (): array => $this->connection->fetchFirstColumn(
            'SELECT `keyword` FROM `product_keyword_dictionary`
             WHERE `language_id` = :language AND `keyword` LIKE :pattern
             ORDER BY CHAR_LENGTH(`keyword`), `keyword`
             LIMIT ' . $limit,
            ['language' => Uuid::fromHexToBytes($languageId), 'pattern' => '%' . addcslashes($part, '%_\\') . '%']
        ));
    }

    /**
     * Ergebnis einer Wörterbuch-Abfrage: erst aus dem Request-Speicher, dann aus dem Objekt-Cache
     * (eine Stunde), sonst frisch geladen. Ein gestörter Cache verhindert die Suche nicht.
     *
     * @param callable(): list<mixed> $load
     *
     * @return list<string>
     */
    private function cached(string $key, callable $load): array
    {
        if (isset($this->infixCache[$key])) {
            return $this->infixCache[$key];
        }

        $item = null;
        if ($this->cache !== null) {
            try {
                $item = $this->cache->getItem(WordParts::cacheKey($key));
                $value = $item->get();
                if ($item->isHit() && \is_array($value)) {
                    return $this->remember($key, array_values($value));
                }
            } catch (\Throwable) {
                $item = null;
            }
        }

        $keywords = $this->remember($key, $load());

        if ($item !== null && $this->cache !== null) {
            try {
                $item->set($keywords);
                $item->expiresAfter(self::CACHE_SECONDS);
                $this->cache->save($item);
            } catch (\Throwable) {
                // nur Zwischenspeicher
            }
        }

        return $keywords;
    }

    /**
     * @param list<mixed> $keywords
     *
     * @return list<string>
     */
    private function remember(string $key, array $keywords): array
    {
        if (\count($this->infixCache) > 200) {
            $this->infixCache = [];
        }

        return $this->infixCache[$key] = array_map(static fn (mixed $keyword): string => \is_scalar($keyword) ? (string) $keyword : '', $keywords);
    }
}
