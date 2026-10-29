<?php declare(strict_types=1);

namespace Nova\Search\Search;

use Nova\Search\Service\NumberIndex;
use Nova\Search\Service\RewriteStore;
use Nova\Search\Service\SearchStatistics;
use Nova\Search\Service\TermNormalizer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\Adapter\Request\RequestParamHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Erweitert Shopwares Produktsuche (Suchseite, Suchfenster, Vorschläge) um zwei Dinge:
 *
 * 1. Umschreibung: Für Begriffe ohne Treffer, zu denen eine geprüfte Umschreibung gespeichert ist
 *    (z. B. „bremsbelag ente“ → „bremsbelag 2cv“), wird direkt mit der Umschreibung gesucht.
 *    Die Criteria bekommt dann die Extension „novaSearchRewrite“ für den Hinweis „Ergebnisse für …“.
 *
 * 2. Ausschlüsse: Produkte, die laut Konfiguration nie in Suchergebnissen erscheinen sollen.
 *
 * 3. Lernen: Produkte, die bei genau diesem Begriff oft geklickt, in den Warenkorb gelegt oder
 *    gekauft wurden, bekommen zusätzliche Relevanz-Punkte. Das wirkt nur auf die Sortierung
 *    nach Relevanz. Berücksichtigt werden nur Produkte, die der Hintergrund-Lauf bei diesem Begriff
 *    auch ohne Boost gefunden hat – so kommt kein fremdes Produkt in die Trefferliste.
 */
class LearningProductSearchBuilder implements ProductSearchBuilderInterface
{
    public const EXTENSION = 'novaSearchRewrite';
    /** Criteria-Extension, wenn per Nummernsuche gefunden wurde */
    public const NUMBER_EXTENSION = 'novaSearchNumber';
    /** Request-Attribut, um Umschreibungen gezielt zu umgehen (z. B. beim erneuten Prüfen) */
    public const SKIP_REWRITE = 'nova-search-skip-rewrite';
    /** Request-Attribut, um das gelernte Ranking zu umgehen (Prüfungen im Hintergrund) */
    public const SKIP_LEARNING = 'nova-search-skip-learning';

    public function __construct(
        private readonly ProductSearchBuilderInterface $decorated,
        private readonly RewriteStore $rewrites,
        private readonly SearchStatistics $statistics,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ?NumberIndex $numberIndex = null,
    ) {
    }

    public function build(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        $search = RequestParamHelper::get($request, 'search');
        $original = \is_array($search) ? implode(' ', $search) : (string) $search;
        $salesChannelId = $context->getSalesChannelId();

        if (self::isExactNumberRequest($request)) {
            $this->buildExactNumberSearch($original, $criteria, $salesChannelId);
            $this->addExclusions($criteria, $salesChannelId);

            return;
        }

        if ($this->buildNumberSearch($original, $criteria, $salesChannelId)) {
            $this->addExclusions($criteria, $salesChannelId);

            return;
        }

        $rewrite = null;
        if (!$request->attributes->getBoolean(self::SKIP_REWRITE) && trim($original) !== '') {
            try {
                $rewrite = $this->rewrites->find($salesChannelId, $original);
            } catch (\Throwable $e) {
                // Datenbankfehler dürfen die Suche nie verhindern
                $this->logger->warning('Nova Suche: Umschreibung konnte nicht gelesen werden', ['exception' => $e]);
            }
        }

        if ($rewrite !== null && $rewrite !== TermNormalizer::normalize($original)) {
            $rewritten = clone $request;
            $rewritten->query->set('search', $rewrite);
            if ($rewritten->request->has('search')) {
                $rewritten->request->set('search', $rewrite);
            }

            $this->decorated->build($rewritten, $criteria, $context);
            $criteria->addExtension(self::EXTENSION, new ArrayStruct(['original' => trim($original), 'rewrite' => $rewrite]));
        } else {
            $this->decorated->build($request, $criteria, $context);
        }

        $this->addExclusions($criteria, $salesChannelId);
        if (!$request->attributes->getBoolean(self::SKIP_LEARNING)) {
            $this->addLearnedBoost($original, $criteria, $context);
        }
    }

    /** Request-Parameter der Checkbox „Nur exakte Teilenummer“ */
    public const EXACT_NUMBER_PARAM = 'exact-number';

    public static function isExactNumberRequest(Request $request): bool
    {
        $value = $request->query->get(self::EXACT_NUMBER_PARAM) ?? $request->request->all()[self::EXACT_NUMBER_PARAM] ?? null;

        return \in_array($value, [true, 1, '1', 'true'], true);
    }

    /**
     * „Nur exakte Teilenummer“: ausschließlich Produkte mit genau dieser Nummer – keine unscharfe
     * Suche, keine Umschreibung, kein Nummernanfang. Ohne Treffer bleibt die Liste leer.
     */
    private function buildExactNumberSearch(string $term, Criteria $criteria, string $salesChannelId): void
    {
        $ids = [];
        $candidates = NumberIndex::exactCandidates($term);
        if ($this->numberIndex !== null && $candidates !== []) {
            try {
                $ids = $this->numberIndex->find($candidates, false);
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Suche: Nummern-Index konnte nicht gelesen werden', ['exception' => $e]);
            }
        }

        // Ohne Treffer: Filter, der nichts findet
        $ids = $ids === [] ? [str_repeat('0', 32)] : $ids;

        $criteria->addFilter(new OrFilter([
            new EqualsAnyFilter('product.id', $ids),
            new EqualsAnyFilter('product.parentId', $ids),
        ]));
        $criteria->addQuery(new ScoreQuery(new EqualsAnyFilter('product.id', $ids), 1000));
        $criteria->addQuery(new ScoreQuery(new EqualsAnyFilter('product.parentId', $ids), 500));
        $criteria->addExtension(self::NUMBER_EXTENSION, new ArrayStruct(['term' => trim($term), 'exact' => true]));
    }

    /**
     * Nummernsuche: Sieht der Begriff wie eine Artikel-, Hersteller- oder OE-Nummer aus und gibt es
     * dazu Produkte im Nummern-Index, werden genau diese gezeigt (inkl. Varianten). Sonst normale Suche.
     */
    private function buildNumberSearch(string $term, Criteria $criteria, string $salesChannelId): bool
    {
        if ($this->numberIndex === null || $this->config->get('NovaSearch.config.numberSearch', $salesChannelId) === false) {
            return false;
        }

        $candidates = NumberIndex::candidates($term);
        if ($candidates === []) {
            return false;
        }

        try {
            $ids = $this->numberIndex->find($candidates, $this->config->get('NovaSearch.config.numberPrefix', $salesChannelId) !== false);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Suche: Nummern-Index konnte nicht gelesen werden', ['exception' => $e]);

            return false;
        }

        if ($ids === []) {
            return false;
        }

        $criteria->addFilter(new OrFilter([
            new EqualsAnyFilter('product.id', $ids),
            new EqualsAnyFilter('product.parentId', $ids),
        ]));
        // Bewertung für die Sortierung „Relevanz“: exakter Produkttreffer vor Varianten
        $criteria->addQuery(new ScoreQuery(new EqualsAnyFilter('product.id', $ids), 1000));
        $criteria->addQuery(new ScoreQuery(new EqualsAnyFilter('product.parentId', $ids), 500));
        $criteria->addExtension(self::NUMBER_EXTENSION, new ArrayStruct(['term' => trim($term)]));

        return true;
    }

    /**
     * Ausschlüsse aus der Konfiguration (Abverkauf ohne Bestand, Produkte, Kategorien, Tags, Zusatzfeld).
     */
    private function addExclusions(Criteria $criteria, string $salesChannelId): void
    {
        $filter = self::exclusionFilter(
            $this->config->getBool('NovaSearch.config.excludeCloseoutOutOfStock', $salesChannelId),
            self::idList($this->config->get('NovaSearch.config.excludeProducts', $salesChannelId)),
            self::idList($this->config->get('NovaSearch.config.excludeCategories', $salesChannelId)),
            self::idList($this->config->get('NovaSearch.config.excludeTags', $salesChannelId)),
            $this->config->getString('NovaSearch.config.excludeCustomField', $salesChannelId),
        );

        if ($filter !== null) {
            $criteria->addFilter($filter);
        }
    }

    /**
     * @param list<string> $productIds
     * @param list<string> $categoryIds
     * @param list<string> $tagIds
     */
    public static function exclusionFilter(bool $closeout, array $productIds, array $categoryIds, array $tagIds, string $customField): ?Filter
    {
        $excluded = [];

        if ($closeout) {
            $excluded[] = new AndFilter([
                new EqualsFilter('product.isCloseout', true),
                new RangeFilter('product.stock', [RangeFilter::LTE => 0]),
            ]);
        }

        if ($productIds !== []) {
            // Hauptprodukt ausgeschlossen → auch alle Varianten
            $excluded[] = new EqualsAnyFilter('product.id', $productIds);
            $excluded[] = new EqualsAnyFilter('product.parentId', $productIds);
        }

        if ($categoryIds !== []) {
            $excluded[] = new EqualsAnyFilter('product.categoriesRo.id', $categoryIds);
        }

        if ($tagIds !== []) {
            $excluded[] = new EqualsAnyFilter('product.tags.id', $tagIds);
        }

        $customField = trim($customField);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $customField) === 1) {
            $excluded[] = new EqualsFilter('product.customFields.' . $customField, true);
        }

        return $excluded === [] ? null : new NotFilter(NotFilter::CONNECTION_OR, $excluded);
    }

    /**
     * @return list<string>
     */
    private static function idList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($id): bool => \is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1));
    }

    private function addLearnedBoost(string $term, Criteria $criteria, SalesChannelContext $context): void
    {
        $salesChannelId = $context->getSalesChannelId();

        // Nur wenn Shopware mit Suchwort-Bewertung arbeitet (Standard). Ohne diese Bewertungs-Queries
        // würden zusätzliche Queries die Trefferliste auf die gelernten Produkte einschränken.
        $queries = $criteria->getQueries();
        if ($queries === [] || $criteria->getTerm() !== null
            || $this->config->get('NovaSearch.config.learningActive', $salesChannelId) === false) {
            return;
        }

        try {
            $learned = $this->statistics->learnedProducts(
                $salesChannelId,
                $term,
                max(1, $this->config->getInt('NovaSearch.config.boostMinScore', $salesChannelId) ?: 3)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Suche: Gelernte Produkte konnten nicht gelesen werden', ['exception' => $e]);

            return;
        }

        if ($learned === []) {
            return;
        }

        // Die Punkte orientieren sich an der stärksten vorhandenen Bewertung, damit der Boost spürbar ist
        // und Shopwares Mindestpunktzahl (kleinste Query-Bewertung) nicht absinkt.
        $base = max(array_map(static fn (ScoreQuery $query): float => $query->getScore(), $queries));
        $strongest = max($learned);

        foreach ($learned as $productId => $score) {
            // 1,0 … 2,0-fache Bewertung, logarithmisch nach Lernwert
            $factor = 1 + log(1 + $score) / log(1 + $strongest);
            $criteria->addQuery(new ScoreQuery(new EqualsFilter('product.id', $productId), $base * $factor));
        }
    }
}
