<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Einfache Mengenbegrenzung (gleitendes Zeitfenster) über den Shopware-Objekt-Cache –
 * z. B. „max. 300 Ereignisse pro Kontext-Token und Stunde“ für die Store-API.
 * Es wird nur ein Hash des Schlüssels gespeichert, nie der Token selbst.
 */
class RequestBudget
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    /**
     * Prüft, ob noch Budget übrig ist, und verbraucht eine Einheit.
     */
    public function consume(string $scope, string $key, int $max, int $windowSeconds): bool
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($scope, $key));
            $state = $item->get();
            $now = time();

            if (!\is_array($state) || !\is_int($state['since'] ?? null) || $state['since'] < $now - $windowSeconds) {
                $state = ['since' => $now, 'count' => 0];
            }

            if ((int) ($state['count'] ?? 0) >= $max) {
                return false;
            }

            $state['count'] = (int) ($state['count'] ?? 0) + 1;
            $item->set($state);
            $item->expiresAfter($windowSeconds);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Cache nicht verfügbar → lieber annehmen als die App blockieren
        }

        return true;
    }

    /**
     * true, wenn der Schlüssel im Zeitfenster zum ersten Mal vorkommt (z. B. ein Kauf nur einmal zählen).
     * $failOpen = false: ohne Cache gilt der Schlüssel als schon gesehen (für Lernsignale).
     */
    public function once(string $scope, string $key, int $windowSeconds, bool $failOpen = true): bool
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($scope, $key));
            if ($item->isHit()) {
                return false;
            }

            $item->set(1);
            $item->expiresAfter($windowSeconds);
            $this->cache->save($item);
        } catch (\Throwable) {
            return $failOpen;
        }

        return true;
    }

    private function cacheKey(string $scope, string $key): string
    {
        // Nur erlaubte Zeichen für PSR-6-Schlüssel
        return 'nova-search-' . preg_replace('/[^a-z0-9_.-]/', '', $scope) . '-' . hash('sha256', $key);
    }
}
