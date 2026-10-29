<?php declare(strict_types=1);

namespace Nova\Search\Controller;

use Nova\Search\Service\EventRecorder;
use Nova\Search\Service\SearchCountGuard;
use Nova\Search\Service\TermNormalizer;
use Nova\Search\Service\TrafficLimiter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nimmt anonyme Nutzungssignale aus dem Suchfenster entgegen (per navigator.sendBeacon):
 *  - search: Suchbegriff + Trefferanzahl (nach einer Tipp-Pause)
 *  - click:  Klick auf ein Produkt aus den Suchergebnissen
 *  - cart:   Produkt aus einer Suche wurde in den Warenkorb gelegt
 *
 * Es werden weder IP-Adressen noch Kunden- oder Sitzungs-IDs in der Datenbank gespeichert.
 * Schutz vor Missbrauch: nur Aufrufe mit bestehender Shop-Sitzung (Cookie) von derselben Website, keine Bots,
 * max. 20 Ereignisse pro Aufruf, max. 300 pro Sitzung und Stunde, dazu Grenzen je Client-IP und je
 * Verkaufskanal (TrafficLimiter), nur bekannte Ereignistypen, nur existierende Produkt-IDs, Begriffe normalisiert.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class TrackingController extends StorefrontController
{
    private const MAX_EVENTS_PER_HOUR = 300;
    private const MAX_BODY_BYTES = 8192;
    private const SESSION_KEY = 'nova-search-track';
    private const REFINED_KEY = 'nova-search-refined';

    private ?TrafficLimiter $limiter = null;

    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly SystemConfigService $config,
    ) {
    }

    public function setLimiter(TrafficLimiter $limiter): void
    {
        $this->limiter = $limiter;
    }

    #[Route(
        path: '/nova-search/track',
        name: 'frontend.nova-search.track',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: [Request::METHOD_POST]
    )]
    public function track(Request $request, SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();
        if ($this->config->get('NovaSearch.config.learningActive', $salesChannelId) === false) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        // Das Suchfenster sendet immer mit dem Sitzungs-Cookie des Shops und von derselben Website –
        // Aufrufe ohne bestehende Sitzung, von fremden Seiten oder von Bots werden verworfen
        if (!$request->hasPreviousSession() || SearchCountGuard::isBot($request) || !self::isSameOrigin($request)) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        $body = (string) $request->getContent();
        $data = \strlen($body) <= self::MAX_BODY_BYTES ? json_decode($body, true) : null;
        $events = EventRecorder::parse(\is_array($data) ? ($data['events'] ?? null) : null);

        $events = array_values(array_filter($events, fn (array $event): bool => $this->allow($request)));
        if ($events === [] || $this->limiter === null
            || !$this->limiter->allow(TrafficLimiter::TRACK, $salesChannelId, \count($events), $request)) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        // Suchen: je Begriff und Sitzung einmal in 30 Minuten
        $events = array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? '') !== EventRecorder::TYPE_SEARCH
            || SearchCountGuard::shouldCount($request, \is_string($event['term'] ?? null) ? $event['term'] : '')));
        // Korrekturen: je Paar und Sitzung nur einmal
        $events = array_values(array_filter($events, fn (array $event): bool => ($event['type'] ?? '') !== EventRecorder::TYPE_REFINE
            || $this->firstRefinement($request, $event)));
        if ($events === []) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        // Käufe meldet das Storefront nicht per Beacon – die ordnet der OrderAttributionSubscriber zu
        $events = array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? '') !== EventRecorder::TYPE_PURCHASE));
        $this->recorder->record($salesChannelId, $events);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Fehlt der Header (ältere Browser), wird nicht geblockt – die übrigen Prüfungen greifen trotzdem */
    private static function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        return $site === null || \in_array($site, ['same-origin', 'none'], true);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function firstRefinement(Request $request, array $event): bool
    {
        $term = \is_string($event['term'] ?? null) ? TermNormalizer::normalize($event['term']) : '';
        $target = \is_string($event['target'] ?? null) ? TermNormalizer::normalize($event['target']) : '';
        if ($term === '' || $target === '') {
            return false;
        }

        $session = $request->getSession();
        $seen = $session->get(self::REFINED_KEY);
        $seen = \is_array($seen) ? $seen : [];
        $key = hash('sha256', $term . '|' . $target);
        if (\in_array($key, $seen, true)) {
            return false;
        }

        $seen[] = $key;
        $session->set(self::REFINED_KEY, \array_slice($seen, -50));

        return true;
    }

    /** Einfache Begrenzung pro Sitzung (gleitendes Stundenfenster) */
    private function allow(Request $request): bool
    {
        if (!$request->hasSession()) {
            return true;
        }

        $session = $request->getSession();
        $state = $session->get(self::SESSION_KEY);
        $now = time();

        if (!\is_array($state) || ($state['since'] ?? 0) < $now - 3600) {
            $state = ['since' => $now, 'count' => 0];
        }

        if ($state['count'] >= self::MAX_EVENTS_PER_HOUR) {
            return false;
        }

        ++$state['count'];
        $session->set(self::SESSION_KEY, $state);

        return true;
    }
}
