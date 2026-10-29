<?php declare(strict_types=1);

namespace Nova\Search\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
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
 */
class WordPartTermInterpreter implements ProductSearchTermInterpreterInterface, ResetInterface
{
    private const MIN_INFIX_LENGTH = 5;
    private const MIN_SPLIT_LENGTH = 8;
    private const MIN_SPLIT_PART = 4;
    private const MAX_KEYWORDS = 60;
    private const MAX_SPLIT_KEYWORDS = 300;
    private const ENDINGS = ['', 's', 'es', 'e', 'en', 'n'];
    private const MAX_SCORED = 10;
    private const SCORE = 0.3;

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
    ) {
    }

    public function interpret(string $word, Context $context): SearchPattern
    {
        $pattern = $this->decorated->interpret($word, $context);

        try {
            $source = $context->getSource();
            $salesChannelId = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;
            if ($this->config->get('NovaSearch.config.wordParts', $salesChannelId) === false) {
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
        $tokens = array_values($this->tokenFilter->filter($this->tokenize($word, $context), $context));
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

        foreach ($tokens as $index => $token) {
            $token = (string) $token;
            $keywords = $tokenTerms[$index];

            if ($keywords === [] && ($parts = $this->split($token, $languageId)) !== null) {
                // Zusammengesetztes Wort ohne Treffer: beide Teile müssen passen
                foreach ($parts as $partKeywords) {
                    $result[] = $partKeywords;
                    $added = array_merge($added, $partKeywords);
                }
                $changed = true;

                continue;
            }

            $infix = $this->eligible($token) ? $this->containing($token, $languageId) : [];
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

        $extended = new SearchPattern($pattern->getOriginal());
        $extended->setBooleanClause($pattern->getBooleanClause() === SearchPattern::BOOLEAN_CLAUSE_AND);
        $extended->setTokenTerms($result);

        $known = [];
        foreach ($pattern->getTerms() as $term) {
            $known[$term->getTerm()] = true;
            $extended->addTerm($term);
        }

        // Kürzeste Wörter zuerst: „doppelvergasers“ ist näher an „vergaser“ als „vergaserüberholsatzes“
        $added = array_values(array_unique($added));
        usort($added, static fn (string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));
        $scored = 0;
        foreach ($added as $keyword) {
            if (isset($known[$keyword])) {
                continue;
            }
            $extended->addTerm(new SearchTerm($keyword, self::SCORE));
            if (++$scored >= self::MAX_SCORED) {
                break;
            }
        }

        return $extended;
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
        return array_values($this->tokenizer->tokenize($word, $minLength));
    }

    private function eligible(string $token): bool
    {
        return mb_strlen($token) >= self::MIN_INFIX_LENGTH && preg_match('/\d/', $token) !== 1;
    }

    /**
     * Zerlegt ein zusammengesetztes Wort in einen bekannten ersten Teil und einen Rest, der im Shop vorkommt.
     * Der längste bekannte erste Teil gewinnt („vergaser“ vor „verga“). Ein Fugen-s wird abgetrennt.
     *
     * @return array{list<string>, list<string>}|null
     */
    private function split(string $token, string $languageId): ?array
    {
        $length = mb_strlen($token);
        if ($length < self::MIN_SPLIT_LENGTH || preg_match('/\d/', $token) === 1) {
            return null;
        }

        /** @var array<string, array{string, string}> $candidates */
        $candidates = [];
        for ($i = $length - self::MIN_SPLIT_PART; $i >= self::MIN_SPLIT_PART; --$i) {
            $head = mb_substr($token, 0, $i);
            $tail = mb_substr($token, $i);
            $candidates[$head] ??= [$head, $tail];
            if (str_ends_with($head, 's') && mb_strlen($head) > self::MIN_SPLIT_PART) {
                $candidates[mb_substr($head, 0, -1)] ??= [mb_substr($head, 0, -1), $tail];
            }
        }

        $known = $this->connection->fetchFirstColumn(
            'SELECT `keyword` FROM `product_keyword_dictionary` WHERE `language_id` = :language AND `keyword` IN (:keywords)',
            ['language' => Uuid::fromHexToBytes($languageId), 'keywords' => array_keys($candidates)],
            ['keywords' => ArrayParameterType::STRING]
        );
        if ($known === []) {
            return null;
        }

        usort($known, static fn ($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        foreach ($known as $head) {
            [$head, $tail] = $candidates[(string) $head] ?? [null, null];
            if ($head === null) {
                continue;
            }

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
        $key = $languageId . '|end|' . $part;
        if (isset($this->infixCache[$key])) {
            return $this->infixCache[$key];
        }

        $escaped = addcslashes($part, '%_\\');
        $patterns = [];
        $params = ['language' => Uuid::fromHexToBytes($languageId)];
        foreach (self::ENDINGS as $n => $ending) {
            $patterns[] = '`keyword` LIKE :p' . $n;
            $params['p' . $n] = '%' . $escaped . $ending;
        }

        $keywords = $this->connection->fetchFirstColumn(
            'SELECT `keyword` FROM `product_keyword_dictionary`
             WHERE `language_id` = :language AND (' . implode(' OR ', $patterns) . ')
             ORDER BY CHAR_LENGTH(`keyword`), `keyword`
             LIMIT ' . self::MAX_SPLIT_KEYWORDS,
            $params
        );

        return $this->remember($key, $keywords);
    }

    /**
     * Wörter aus dem Suchwort-Verzeichnis des Shops, die den Teil enthalten (kürzeste zuerst).
     *
     * @return list<string>
     */
    private function containing(string $part, string $languageId, int $limit = self::MAX_KEYWORDS): array
    {
        $key = $languageId . '|' . $limit . '|' . $part;
        if (isset($this->infixCache[$key])) {
            return $this->infixCache[$key];
        }

        $keywords = $this->connection->fetchFirstColumn(
            'SELECT `keyword` FROM `product_keyword_dictionary`
             WHERE `language_id` = :language AND `keyword` LIKE :pattern
             ORDER BY CHAR_LENGTH(`keyword`), `keyword`
             LIMIT ' . $limit,
            ['language' => Uuid::fromHexToBytes($languageId), 'pattern' => '%' . addcslashes($part, '%_\\') . '%']
        );

        return $this->remember($key, $keywords);
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

        return $this->infixCache[$key] = array_values(array_map('strval', $keywords));
    }
}
