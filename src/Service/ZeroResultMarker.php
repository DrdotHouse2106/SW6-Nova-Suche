<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Merkt sich kurz, dass der Server selbst für einen Begriff 0 Treffer ausgeliefert hat (Suchfenster).
 *
 * Das Suchfenster meldet den fertig getippten Begriff per Beacon – die Trefferzahl darin kommt aber
 * vom Browser. Ein Begriff aus dem Beacon wird deshalb nur dann für Claude vorgemerkt, wenn hier ein
 * Merker liegt, also das Suchfenster in den letzten 30 Minuten wirklich 0 Treffer geliefert hat.
 * Antworten mit 0 Treffern werden nie HTTP-gecacht, der Merker entsteht also bei jeder solchen Suche.
 *
 * Im Cache steht nur ein Hash aus Verkaufskanal und Begriff.
 */
class ZeroResultMarker
{
    public const TTL_SECONDS = 1800;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function mark(string $salesChannelId, string $term): void
    {
        $term = TermNormalizer::normalize($term);
        if (!TermNormalizer::isTrackable($term)) {
            return;
        }

        try {
            $item = $this->cache->getItem($this->key($salesChannelId, $term));
            $item->set(1);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Ohne Cache wird aus dem Beacon nichts vorgemerkt – die Suchseite und die Store-API merken weiter vor
        }
    }

    public function confirmed(string $salesChannelId, string $term): bool
    {
        try {
            return $this->cache->getItem($this->key($salesChannelId, TermNormalizer::normalize($term)))->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    private function key(string $salesChannelId, string $term): string
    {
        return 'nova-search-zero-' . hash('sha256', $salesChannelId . '|' . $term);
    }
}
