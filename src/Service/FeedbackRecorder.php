<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Nova\Search\Rewrite\RewriteService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Nimmt eine „Rückmeldung zum Suchergebnis“ entgegen (Suchfenster, Suchergebnisseite oder App).
 *
 * Regeln (wie bei den Lernsignalen):
 *  - pro Verkaufskanal abschaltbar (feedbackActive)
 *  - nur echte Suchbegriffe (TermNormalizer), Bewertung „up“/„down“, Freitext bereinigt (FeedbackText)
 *  - Grenzen je Client-IP und je Verkaufskanal (TrafficLimiter::FEEDBACK)
 *  - eine Bewertung je Besucher, Begriff und Tag: Merker im Objekt-Cache mit Hash aus Sitzung bzw.
 *    Kontext-Token, zusätzlich je IP-Hash – nichts davon landet in der Datenbank
 *  - der Freitext lässt sich nur einmal an die eigene „Daumen runter“-Bewertung anhängen
 *  - die Trefferzahl ermittelt der Server selbst (echte Produktsuche inkl. gespeicherter Umschreibung)
 */
class FeedbackRecorder
{
    private const DAY_SECONDS = 86400;
    private const HITS_CACHE_SECONDS = 900;

    private ?TrafficLimiter $limiter = null;

    public function __construct(
        private readonly SearchFeedback $feedback,
        private readonly RewriteService $rewriteService,
        private readonly CacheItemPoolInterface $cache,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function setLimiter(TrafficLimiter $limiter): void
    {
        $this->limiter = $limiter;
    }

    public function isActive(string $salesChannelId): bool
    {
        return $this->config->get('NovaSearch.config.feedbackActive', $salesChannelId) !== false;
    }

    /**
     * @param array<string, mixed> $input  { term, rating, text?, source? }
     * @param string               $visitor Sitzungs-ID bzw. Kontext-Token – wird nur gehasht im Cache verwendet
     *
     * @return bool true, wenn etwas gespeichert wurde (nur für Tests/Logs – die Routen antworten immer gleich)
     */
    public function submit(string $salesChannelId, array $input, string $source, string $visitor, Request $request): bool
    {
        if ($visitor === '' || !$this->isActive($salesChannelId)) {
            return false;
        }

        $term = \is_string($input['term'] ?? null) ? TermNormalizer::normalize($input['term']) : '';
        $rating = FeedbackText::rating($input['rating'] ?? null);
        if ($rating === null || !TermNormalizer::isTrackable($term)) {
            return false;
        }

        // Text gibt es nur zu „Daumen runter“
        $text = $rating === FeedbackText::RATING_DOWN ? FeedbackText::clean($input['text'] ?? null) : null;

        if ($this->limiter === null || !$this->limiter->allow(TrafficLimiter::FEEDBACK, $salesChannelId, 1, $request)) {
            return false;
        }

        $day = date('Y-m-d');
        try {
            $memory = $this->cache->getItem('nova-search-feedback-' . hash('sha256', $visitor . '|' . $salesChannelId . '|' . $term . '|' . $day));
            $known = $memory->get();

            // Schon bewertet: höchstens den Freitext an die eigene „Daumen runter“-Bewertung anhängen
            if ($memory->isHit() && \is_array($known)) {
                if ($text !== null && ($known['rating'] ?? null) === FeedbackText::RATING_DOWN
                    && empty($known['text']) && \is_string($known['id'] ?? null)) {
                    $this->feedback->attachText($known['id'], $text);
                    $known['text'] = true;
                    $memory->set($known);
                    $memory->expiresAfter(self::DAY_SECONDS);
                    $this->cache->save($memory);

                    return true;
                }

                return false;
            }

            // Neue Bewertung: zusätzlich je IP, Begriff und Tag nur einmal
            if (!$this->limiter->once('feedback', $salesChannelId . '|' . $term . '|' . $day, self::DAY_SECONDS, $request)) {
                return false;
            }

            $id = $this->feedback->add($salesChannelId, $term, $rating, $text, $source, $this->hits($salesChannelId, $term));

            $memory->set(['id' => $id, 'rating' => $rating, 'text' => $text !== null]);
            $memory->expiresAfter(self::DAY_SECONDS);
            $this->cache->save($memory);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Suche: Rückmeldung konnte nicht gespeichert werden', ['exception' => $e]);

            return false;
        }

        return true;
    }

    /** Trefferzahl wie bei den Besuchern (mit gespeicherter Umschreibung), 15 Minuten zwischengespeichert */
    private function hits(string $salesChannelId, string $term): ?int
    {
        try {
            $item = $this->cache->getItem('nova-search-feedback-hits-' . hash('sha256', $salesChannelId . '|' . $term));
            if ($item->isHit() && \is_int($item->get())) {
                return $item->get();
            }

            $hits = $this->rewriteService->total($salesChannelId, $term, null, true);
            $item->set($hits);
            $item->expiresAfter(self::HITS_CACHE_SECONDS);
            $this->cache->save($item);

            return $hits;
        } catch (\Throwable $e) {
            $this->logger->notice('Nova Suche: Trefferzahl für Rückmeldung nicht ermittelbar', ['exception' => $e]);

            return null;
        }
    }
}
