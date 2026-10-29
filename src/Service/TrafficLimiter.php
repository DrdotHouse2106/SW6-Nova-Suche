<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Mengenbegrenzung für alles, was von außen ohne Login in die Lerndaten schreibt oder teure Arbeit
 * auslöst (Symfony RateLimiter, gleitendes Stundenfenster): je Client-IP und zusätzlich global je
 * Verkaufskanal. Die Grenzen liegen weit über dem, was ein echter Besucher erzeugt – trifft es doch
 * einmal jemanden, fällt nur ein Lernsignal weg, die Suche selbst bleibt unverändert.
 *
 * Im Cache landet nur ein Hash von IP bzw. Schlüssel (CacheStorage), höchstens für das Zeitfenster.
 */
class TrafficLimiter
{
    /** Ereignisse aus Suchfenster und App */
    public const TRACK = 'track';
    /** Neue Begriffe für die Claude-Warteschlange */
    public const PENDING = 'pending';
    /** Sofort-Auswertung mit Claude auf der Suchergebnisseite */
    public const CLAUDE_SYNC = 'claude-sync';
    /** Schwestershop-Suchen bei 0 Treffern */
    public const PARTNER = 'partner';

    /** @var array<string, array{ip: int, global: int, failOpen: bool}> */
    private const LIMITS = [
        self::TRACK => ['ip' => 600, 'global' => 30000, 'failOpen' => false],
        self::PENDING => ['ip' => 30, 'global' => 300, 'failOpen' => false],
        self::CLAUDE_SYNC => ['ip' => 3, 'global' => 30, 'failOpen' => false],
        self::PARTNER => ['ip' => 120, 'global' => 3000, 'failOpen' => true],
    ];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
        private readonly RequestBudget $budget,
    ) {
    }

    /**
     * Verbraucht $tokens Einheiten (je IP und global). false = Grenze erreicht.
     */
    public function allow(string $scope, string $salesChannelId, int $tokens = 1, ?Request $request = null): bool
    {
        $limits = self::LIMITS[$scope] ?? null;
        if ($limits === null || $tokens < 1) {
            return true;
        }

        try {
            $tokens = min($tokens, $limits['ip']);

            return $this->factory($scope . '-ip', $limits['ip'])->create($salesChannelId . '|' . $this->client($request))->consume($tokens)->isAccepted()
                && $this->factory($scope . '-global', $limits['global'])->create($salesChannelId)->consume($tokens)->isAccepted();
        } catch (\Throwable) {
            return $limits['failOpen'];
        }
    }

    /**
     * true, wenn diese IP den Schlüssel im Zeitfenster zum ersten Mal meldet (z. B. ein Klick je Begriff,
     * Produkt und Tag). Ohne Cache wird nichts gezählt.
     */
    public function once(string $scope, string $key, int $windowSeconds, ?Request $request = null): bool
    {
        return $this->budget->once($scope, $this->client($request) . '|' . $key, $windowSeconds, false);
    }

    private function client(?Request $request): string
    {
        $request ??= $this->requestStack->getMainRequest();

        return $request?->getClientIp() ?? 'unknown';
    }

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return $this->factories[$id] ??= new RateLimiterFactory(
            ['id' => 'nova-search-' . $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new CacheStorage($this->cache)
        );
    }
}
