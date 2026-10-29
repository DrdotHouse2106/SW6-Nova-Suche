<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Verarbeitet Nutzungssignale aus Suchfenster (Storefront) und App (Store-API) einheitlich:
 *  - search:   Suchbegriff + Trefferanzahl („total“, im Storefront „hits“)
 *  - click:    Produkt aus den Suchergebnissen angeklickt
 *  - cart:     Produkt nach einer Suche in den Warenkorb gelegt
 *  - purchase: Produkt nach einer Suche gekauft – nur, wenn der aktuelle Kunde dieses Produkt
 *              tatsächlich kürzlich bestellt hat (Schutz vor erfundenen Käufen)
 *
 * Gespeichert werden nur Begriff, Produkt-ID und Zähler – keine Kunden-, Sitzungs- oder Bestelldaten.
 * Klicks, Warenkorb und Korrekturen zählen je Client-IP, Begriff und Produkt bzw. Korrektur höchstens
 * einmal am Tag (nur ein Hash im Cache, 24 Stunden).
 */
class EventRecorder
{
    public const MAX_EVENTS_PER_REQUEST = 20;
    public const TYPE_SEARCH = 'search';
    public const TYPE_PURCHASE = 'purchase';
    /** Begriff ohne Klick, direkt danach ein ähnlicher Begriff mit Klick (vermutlich eine Korrektur) */
    public const TYPE_REFINE = 'refine';

    private const SIGNAL_DEDUP_SECONDS = 86400;

    /** Wie lange nach einer Bestellung ein Kauf noch gemeldet werden darf */
    private const PURCHASE_WINDOW_HOURS = 48;

    private ?TrafficLimiter $limiter = null;

    public function __construct(
        private readonly SearchStatistics $statistics,
        private readonly RewriteStore $rewrites,
        private readonly LoggerInterface $logger,
        private readonly ?SystemConfigService $config = null,
    ) {
    }

    public function setLimiter(TrafficLimiter $limiter): void
    {
        $this->limiter = $limiter;
    }

    /**
     * Sieht „target“ wie die korrigierte Schreibweise von „term“ aus? Nur kleine Abweichungen
     * (Tippfehler, vergessener Buchstabe) – „bremse“ → „auspuff“ ist ein neuer Gedanke, keine Korrektur.
     */
    public static function isLikelyCorrection(string $term, string $target): bool
    {
        $a = TermNormalizer::normalize($term);
        $b = TermNormalizer::normalize($target);
        if ($a === $b || !TermNormalizer::isTrackable($a) || !TermNormalizer::isTrackable($b)) {
            return false;
        }

        // Weiter- oder Zurücktippen ist keine Korrektur
        if (str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return false;
        }

        // Andere Zahlen = anderes Teil oder Fahrzeug („spiegel t2“ → „spiegel t3“), kein Tippfehler
        preg_match_all('/\d+/', $a, $digitsA);
        preg_match_all('/\d+/', $b, $digitsB);
        if ($digitsA[0] !== $digitsB[0]) {
            return false;
        }

        $distance = self::distance($a, $b);
        $allowed = min(3, max(1, intdiv(max(mb_strlen($a), mb_strlen($b)), 4)));

        return $distance <= $allowed;
    }

    /** Levenshtein-Abstand auf Zeichen (nicht Bytes) – Umlaute zählen als ein Zeichen */
    private static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $previous = range(0, \count($y));

        foreach ($x as $i => $charX) {
            $current = [$i + 1];
            foreach ($y as $j => $charY) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($charX === $charY ? 0 : 1));
            }
            $previous = $current;
        }

        return $previous[\count($y)];
    }

    /** Korrektur nur beobachten – übernommen wird sie erst nach der Trefferprüfung im Hintergrund */
    private function learnCorrection(string $salesChannelId, string $term, string $target): bool
    {
        if (!self::isLikelyCorrection($term, $target)
            || $this->config?->get('NovaSearch.config.learnCorrections', $salesChannelId) === false
            || !$this->firstToday('refine', $salesChannelId . '|' . TermNormalizer::normalize($term) . '|' . TermNormalizer::normalize($target))) {
            return false;
        }

        $this->rewrites->recordRefinement($salesChannelId, $term, $target);

        return true;
    }

    /** Ohne Begrenzer (z. B. während eines Updates) zählen Lernsignale nicht */
    private function firstToday(string $scope, string $key): bool
    {
        return $this->limiter !== null && $this->limiter->once($scope, $key, self::SIGNAL_DEDUP_SECONDS);
    }

    /**
     * Wandelt den rohen Request-Inhalt in eine Liste von Ereignissen (max. 20, nur Arrays).
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(mixed $events): array
    {
        if (!\is_array($events)) {
            return [];
        }

        $list = array_values(array_filter(array_values($events), static fn ($event): bool => \is_array($event)));

        /** @var list<array<string, mixed>> $list */
        return \array_slice($list, 0, self::MAX_EVENTS_PER_REQUEST);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param string|null $customerId nur gesetzt, wenn Käufe erlaubt sind (Store-API mit angemeldetem Kunden/Gast)
     *
     * @return int Anzahl gespeicherter Ereignisse
     */
    public function record(string $salesChannelId, array $events, ?string $customerId = null): int
    {
        if ($events === []) {
            return 0;
        }

        $productIds = array_values(array_filter(array_map(
            static fn (array $event): string => \is_string($event['productId'] ?? null) ? strtolower($event['productId']) : '',
            $events
        ), Uuid::isValid(...)));

        $saved = 0;

        try {
            $existing = array_flip($this->statistics->existingProductIds($productIds));

            $ordered = [];
            if ($customerId !== null && Uuid::isValid($customerId)
                && array_filter($events, static fn (array $e): bool => ($e['type'] ?? '') === self::TYPE_PURCHASE) !== []) {
                $ordered = array_flip($this->statistics->recentlyOrderedProductIds($customerId, $productIds, self::PURCHASE_WINDOW_HOURS));
            }

            foreach ($events as $event) {
                $term = \is_string($event['term'] ?? null) ? $event['term'] : '';
                $type = \is_string($event['type'] ?? null) ? $event['type'] : '';
                if ($term === '') {
                    continue;
                }

                if ($type === self::TYPE_REFINE) {
                    $target = \is_string($event['target'] ?? null) ? $event['target'] : '';
                    if ($target !== '' && $this->learnCorrection($salesChannelId, $term, $target)) {
                        ++$saved;
                    }
                    continue;
                }

                if ($type === self::TYPE_SEARCH) {
                    $hits = $event['total'] ?? $event['hits'] ?? 0;
                    $hits = \is_numeric($hits) ? (int) $hits : 0;
                    $this->statistics->recordSearch($salesChannelId, $term, $hits);
                    if ($hits === 0 && empty($event['rewritten'])) {
                        $this->rewrites->queue($salesChannelId, $term, $this->limiter);
                    }
                    ++$saved;
                    continue;
                }

                $productId = \is_string($event['productId'] ?? null) ? strtolower($event['productId']) : '';
                if (!isset($existing[$productId])) {
                    continue;
                }

                $statisticType = match ($type) {
                    SearchStatistics::TYPE_CLICK => SearchStatistics::TYPE_CLICK,
                    SearchStatistics::TYPE_CART => SearchStatistics::TYPE_CART,
                    self::TYPE_PURCHASE => isset($ordered[$productId]) ? SearchStatistics::TYPE_ORDER : null,
                    default => null,
                };

                if ($statisticType === null) {
                    continue;
                }

                // Käufe sind bereits gegen die Bestellung geprüft und je Kontext entdoppelt
                if ($statisticType !== SearchStatistics::TYPE_ORDER
                    && !$this->firstToday($statisticType, $salesChannelId . '|' . TermNormalizer::normalize($term) . '|' . $productId)) {
                    continue;
                }

                $this->statistics->recordProduct($salesChannelId, $term, $productId, $statisticType);
                ++$saved;
            }
        } catch (\Throwable $e) {
            // Erfassung ist optional – Fehler nie an Besucher oder App weitergeben
            $this->logger->warning('Nova Suche: Ereignisse konnten nicht gespeichert werden', ['exception' => $e]);
        }

        return $saved;
    }
}
