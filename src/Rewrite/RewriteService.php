<?php declare(strict_types=1);

namespace Nova\Search\Rewrite;

use Doctrine\DBAL\Connection;
use Nova\Search\Search\LearningProductSearchBuilder;
use Nova\Search\Service\RewriteStore;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wertet Suchbegriffe ohne Treffer aus und speichert geprüfte Umschreibungen dauerhaft.
 *
 * Jede vorgeschlagene Umschreibung wird gegen die echte Produktsuche des Verkaufskanals geprüft –
 * gespeichert wird nur, was tatsächlich Treffer liefert.
 */
class RewriteService
{
    private const TERMS_PER_REQUEST = 30;
    private const BACKGROUND_TIMEOUT = 60.0;

    /** Gelernte Korrekturen: Mindestanzahl (falls nicht eingestellt) und verschiedene Tage */
    public const DEFAULT_CORRECTION_MIN_COUNT = 2;
    public const CORRECTION_MIN_DAYS = 2;
    private const CORRECTIONS_PER_RUN = 50;

    /** @var array<string, SalesChannelContext> */
    private array $contexts = [];

    public function __construct(
        private readonly RewriteStore $store,
        private readonly RewriteSuggesterInterface $suggester,
        private readonly AbstractProductSearchRoute $productSearchRoute,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Hintergrund-Lauf (Scheduled Task / Konsolenbefehl).
     *
     * @return array{processed: int, saved: int, rechecked: int, learned: int}
     */
    public function run(int $limitPerSalesChannel = self::TERMS_PER_REQUEST): array
    {
        $stats = ['processed' => 0, 'saved' => 0, 'rechecked' => $this->recheck(), 'learned' => $this->learnCorrections()];

        foreach ($this->store->pending($limitPerSalesChannel) as $salesChannelId => $terms) {
            if (!$this->suggester->isAvailable($salesChannelId)) {
                continue;
            }

            $stats['processed'] += \count($terms);
            $stats['saved'] += $this->process($salesChannelId, $terms, self::BACKGROUND_TIMEOUT, null);
        }

        return $stats;
    }

    /**
     * Sofort-Auswertung eines einzelnen Begriffs (Suchseite, mit kurzem Timeout).
     */
    public function rewriteNow(string $term, SalesChannelContext $context, float $timeout): ?string
    {
        $salesChannelId = $context->getSalesChannelId();
        if (!$this->suggester->isAvailable($salesChannelId)) {
            return null;
        }

        $this->store->markPending($salesChannelId, $term);
        $this->process($salesChannelId, [$term], $timeout, $context);

        return $this->store->find($salesChannelId, $term);
    }

    /**
     * @param list<string> $terms
     */
    private function process(string $salesChannelId, array $terms, float $timeout, ?SalesChannelContext $context): int
    {
        try {
            $context ??= $this->context($salesChannelId);
            $suggestions = $this->suggester->suggest($salesChannelId, $terms, $this->safeShop($salesChannelId), $timeout);
        } catch (ClaudeBudgetExceededException) {
            // Kostenlimit erreicht: Begriffe bleiben offen und werden später ausgewertet
            return 0;
        } catch (\Throwable $e) {
            foreach ($terms as $term) {
                $this->store->markFailed($salesChannelId, $term);
            }
            $this->logger->warning('Nova Suche: Umschreibung fehlgeschlagen', ['exception' => $e]);

            return 0;
        }

        $saved = 0;
        foreach ($terms as $term) {
            $best = null;
            foreach ($suggestions[$term] ?? [] as $alternative) {
                $hits = $this->hits($alternative, $context);
                if ($hits > 0) {
                    $best = [$alternative, $hits];
                    break;
                }
            }

            if ($best !== null) {
                $this->store->saveRewrite($salesChannelId, $term, $best[0], $best[1]);
                ++$saved;
            } else {
                $this->store->markNone($salesChannelId, $term);
            }
        }

        return $saved;
    }

    /**
     * Beobachtete Korrekturen übernehmen – nur wenn der Begriff selbst nichts findet und die Korrektur
     * Treffer liefert (dieselbe Prüfung wie bei Claude). Gezählt wird je Besucher und Tag einmal,
     * nötig sind mindestens „correctionMinCount“ Beobachtungen an mindestens zwei Tagen.
     */
    public function learnCorrections(): int
    {
        $learned = 0;
        $done = [];
        foreach ($this->store->learnCandidates(self::DEFAULT_CORRECTION_MIN_COUNT, self::CORRECTION_MIN_DAYS, self::CORRECTIONS_PER_RUN) as $row) {
            $salesChannelId = $row['sc'];
            $key = $salesChannelId . '|' . $row['term'];
            $min = max(self::DEFAULT_CORRECTION_MIN_COUNT, $this->config->getInt('NovaSearch.config.correctionMinCount', $salesChannelId));

            try {
                // Geprüft wird erst wieder nach einer neuen Beobachtung
                $this->store->markRefinementChecked($salesChannelId, $row['term'], $row['target']);
                if (isset($done[$key]) || $row['count'] < $min
                    || $this->config->get('NovaSearch.config.learnCorrections', $salesChannelId) === false
                    || $this->config->get('NovaSearch.config.learningActive', $salesChannelId) === false
                    // Begriff hat schon eine aktive Umschreibung (Claude oder früher gelernt)
                    || $this->store->find($salesChannelId, $row['term']) !== null) {
                    continue;
                }

                $context = $this->context($salesChannelId);
                $targetHits = $this->hits($row['target'], $context);
                if ($targetHits > 0 && $this->hits($row['term'], $context) === 0) {
                    $this->store->saveLearned($salesChannelId, $row['term'], $row['target'], $targetHits);
                    $done[$key] = true;
                    ++$learned;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Suche: Korrektur konnte nicht geprüft werden', ['exception' => $e]);
            }
        }

        return $learned;
    }

    /** Trefferanzahl der echten Produktsuche (ohne Umschreibungen und gelerntes Ranking) */
    public function total(string $salesChannelId, string $term, ?Criteria $criteria = null, bool $withRewrites = false): int
    {
        return $this->hits($term, $this->context($salesChannelId), $criteria, $withRewrites);
    }

    /**
     * Nach 30 Tagen prüfen: Findet der ursprüngliche Begriff inzwischen selbst etwas (neues Sortiment)?
     * Dann wird die Umschreibung gelöscht. Begriffe ohne gefundene Umschreibung bekommen eine neue Chance.
     * Gelernte Korrekturen aus älteren Versionen (Status „review“) werden sofort geprüft und bei
     * Nichtbestehen deaktiviert.
     */
    private function recheck(): int
    {
        $count = 0;
        foreach ($this->store->dueForRecheck(100) as $row) {
            try {
                $context = $this->context($row['sc']);
                $hits = $this->hits($row['term'], $context);
                $rewriteHits = $row['status'] !== RewriteStore::STATUS_NONE && $hits === 0 && $row['rewrite'] !== null
                    ? $this->hits($row['rewrite'], $context)
                    : 0;
            } catch (\Throwable $e) {
                $this->logger->warning('Nova Suche: Prüfung fehlgeschlagen', ['exception' => $e]);
                continue;
            }

            if ($row['status'] === RewriteStore::STATUS_REVIEW) {
                if ($rewriteHits > 0) {
                    $this->store->confirm($row['sc'], $row['term'], $rewriteHits);
                } else {
                    $this->store->disable($row['sc'], $row['term']);
                }
            } elseif ($hits > 0) {
                // Begriff findet selbst Treffer → Umschreibung überflüssig
                $this->store->delete($row['sc'], $row['term']);
            } elseif ($row['status'] === RewriteStore::STATUS_DONE && $rewriteHits === 0) {
                // Umschreibung findet nichts mehr → entfernen; Claude-Begriffe neu auswerten
                $this->store->delete($row['sc'], $row['term']);
                if ($row['source'] !== RewriteStore::SOURCE_LEARNED) {
                    $this->store->markPending($row['sc'], $row['term']);
                }
            } elseif ($row['status'] === RewriteStore::STATUS_NONE) {
                // Damals keine Lösung gefunden → mit aktuellem Sortiment erneut versuchen
                $this->store->delete($row['sc'], $row['term']);
                $this->store->markPending($row['sc'], $row['term']);
            } else {
                // Umschreibung weiterhin nötig → behalten
                $this->store->touch($row['sc'], $row['term']);
            }
            ++$count;
        }

        return $count;
    }

    /** Trefferanzahl der echten Produktsuche – ohne gespeicherte Umschreibungen und gelerntes Ranking */
    private function hits(string $term, SalesChannelContext $context, ?Criteria $criteria = null, bool $withRewrites = false): int
    {
        $request = new Request(['search' => $term, 'limit' => 1, 'p' => 1], ['no-aggregations' => true]);
        $request->attributes->set(LearningProductSearchBuilder::SKIP_REWRITE, !$withRewrites);
        $request->attributes->set(LearningProductSearchBuilder::SKIP_LEARNING, true);

        try {
            return $this->productSearchRoute->load($request, $context, $criteria ?? new Criteria())->getListingResult()->getTotal();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function context(string $salesChannelId): SalesChannelContext
    {
        return $this->contexts[$salesChannelId] ??= $this->contextFactory->create(Random::getAlphanumericString(32), $salesChannelId);
    }

    /**
     * Shop-Kontext ist hilfreich, aber nicht nötig – ein Fehler darf die Auswertung nicht verhindern.
     *
     * @return array{shopName: string, locale: string, categories: list<string>, manufacturers: list<string>}
     */
    private function safeShop(string $salesChannelId): array
    {
        try {
            return $this->shop($salesChannelId);
        } catch (\Throwable $e) {
            $this->logger->notice('Nova Suche: Shop-Kontext für Claude nicht verfügbar', ['exception' => $e]);

            return ['shopName' => '', 'locale' => 'de-DE', 'categories' => [], 'manufacturers' => []];
        }
    }

    /**
     * Kontext für Claude: Shopname, Sprache, Kategorie- und Markennamen (keine Kundendaten).
     *
     * @return array{shopName: string, locale: string, categories: list<string>, manufacturers: list<string>}
     */
    private function shop(string $salesChannelId): array
    {
        $id = Uuid::fromHexToBytes($salesChannelId);

        /** @var array{language_id: string, navigation_category_id: string, locale: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT sc.`language_id`, sc.`navigation_category_id`, loc.`code` AS locale
             FROM `sales_channel` sc
             JOIN `language` l ON l.`id` = sc.`language_id`
             JOIN `locale` loc ON loc.`id` = l.`locale_id`
             WHERE sc.`id` = :id',
            ['id' => $id]
        );

        $categories = [];
        $manufacturers = [];
        if ($row !== false) {
            /** @var list<string> $categories */
            $categories = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT ct.`name` FROM `category` c
                 JOIN `category_translation` ct ON ct.`category_id` = c.`id` AND ct.`category_version_id` = c.`version_id` AND ct.`language_id` = :lang
                 WHERE c.`version_id` = :version AND c.`active` = 1 AND c.`path` LIKE :path AND ct.`name` IS NOT NULL
                 ORDER BY c.`level`, ct.`name` LIMIT 60',
                [
                    'lang' => $row['language_id'],
                    'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    'path' => '%|' . Uuid::fromBytesToHex($row['navigation_category_id']) . '|%',
                ]
            );

            /** @var list<string> $manufacturers */
            $manufacturers = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT `name` FROM `product_manufacturer_translation` WHERE `language_id` = :lang AND `name` IS NOT NULL ORDER BY `name` LIMIT 40',
                ['lang' => $row['language_id']]
            );
        }

        return [
            'shopName' => $this->config->getString('core.basicInformation.shopName', $salesChannelId),
            'locale' => $row !== false ? $row['locale'] : 'de-DE',
            'categories' => $categories,
            'manufacturers' => $manufacturers,
        ];
    }
}
