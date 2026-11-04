<?php declare(strict_types=1);

namespace Nova\Search\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Mengenbegrenzung für alles, was von außen ohne Login in die Lerndaten schreibt oder teure Arbeit
 * auslöst (Symfony RateLimiter, gleitendes Stundenfenster): je Client-IP und zusätzlich global je
 * Verkaufskanal. Die Grenzen liegen weit über dem, was ein echter Besucher erzeugt – trifft es doch
 * einmal jemanden, fällt nur ein Lernsignal weg, die Suche selbst bleibt unverändert.
 *
 * Im Cache landet nur ein Hash von IP bzw. Schlüssel (CacheStorage), höchstens für das Zeitfenster.
 * IPv6-Adressen zählen je /64-Netz (so viel bekommt jeder Anschluss bzw. Server mindestens), damit
 * ein Angreifer nicht mit jeder Adresse seines Netzes ein frisches Budget bekommt. Mit Lock-Factory
 * zählen auch gleichzeitige Anfragen exakt (kein Überschreiten des Fensters durch Parallelität).
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
    /** Rückmeldungen zum Suchergebnis (Daumen hoch/runter, Freitext) */
    public const FEEDBACK = 'feedback';
    /** Nicht gecachte Anfragen an das Suchfenster (/nova-search) – nur je IP, keine globale Grenze */
    public const INSTANT = 'instant';

    /** IPv6: Adressen werden auf dieses Präfix zusammengefasst */
    public const IPV6_PREFIX = 64;

    /** @var array<string, array{ip: int, global: int|null, failOpen: bool}> */
    private const LIMITS = [
        self::TRACK => ['ip' => 600, 'global' => 30000, 'failOpen' => false],
        self::PENDING => ['ip' => 30, 'global' => 300, 'failOpen' => false],
        self::CLAUDE_SYNC => ['ip' => 3, 'global' => 30, 'failOpen' => false],
        self::PARTNER => ['ip' => 120, 'global' => 3000, 'failOpen' => true],
        self::FEEDBACK => ['ip' => 30, 'global' => 300, 'failOpen' => false],
        // Eine globale Grenze würde bei einem Angriff alle Besucher aussperren – deshalb nur je IP
        self::INSTANT => ['ip' => 1200, 'global' => null, 'failOpen' => true],
    ];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    private ?LockFactory $lockFactory = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
        private readonly RequestBudget $budget,
    ) {
    }

    public function setLockFactory(?LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    /**
     * Schlüssel für eine Client-IP: IPv4 unverändert, IPv6 auf das /64-Netz gekürzt
     * (IPv4-gemappte IPv6-Adressen wie „::ffff:192.0.2.1“ zählen als IPv4).
     */
    public static function clientKey(?string $ip): string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return 'unknown';
        }

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }

        $binary = filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false ? @inet_pton($ip) : false;
        if (!\is_string($binary) || \strlen($binary) !== 16) {
            return $ip;
        }

        // ::ffff:a.b.c.d → a.b.c.d
        if (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $v4 = inet_ntop(substr($binary, 12));

            return $v4 !== false ? $v4 : $ip;
        }

        $bytes = intdiv(self::IPV6_PREFIX, 8);
        $network = inet_ntop(substr($binary, 0, $bytes) . str_repeat("\0", 16 - $bytes));

        return ($network !== false ? $network : $ip) . '/' . self::IPV6_PREFIX;
    }

    /**
     * Einfaches Budget je Client (IP bzw. /64) und Schlüssel – z. B. „5 Texte je Tag“.
     * Ohne Cache wird angenommen (der Aufrufer begrenzt zusätzlich).
     */
    public function budget(string $scope, string $key, int $max, int $windowSeconds, ?Request $request = null): bool
    {
        return $this->budget->consume($scope, $this->client($request) . '|' . $key, $max, $windowSeconds);
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

            // Schlüssel gehasht: weder im Cache noch im Namen einer Lock-Datei steht die IP im Klartext
            if (!$this->factory($scope . '-ip', $limits['ip'])->create(hash('sha256', $salesChannelId . '|' . $this->client($request)))->consume($tokens)->isAccepted()) {
                return false;
            }

            return $limits['global'] === null
                || $this->factory($scope . '-global', $limits['global'])->create(hash('sha256', $salesChannelId))->consume($tokens)->isAccepted();
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

        return self::clientKey($request?->getClientIp());
    }

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return $this->factories[$id] ??= new RateLimiterFactory(
            ['id' => 'nova-search-' . $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new CacheStorage($this->cache),
            $this->lockFactory
        );
    }
}
