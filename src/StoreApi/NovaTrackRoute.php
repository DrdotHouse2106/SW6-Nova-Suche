<?php declare(strict_types=1);

namespace Nova\Search\StoreApi;

use Nova\Search\Service\EventRecorder;
use Nova\Search\Service\RequestBudget;
use Nova\Search\Service\TrafficLimiter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /store-api/nova-search/track
 *
 * Body: { "events": [{ "type": "search"|"click"|"cart"|"purchase", "term": "…", "productId"?: "…",
 *                      "total"?: 12, "quantity"?: 1 }] }
 *
 * Gleiche Regeln wie im Suchfenster: max. 20 Ereignisse pro Aufruf, max. 300 pro Kontext-Token und
 * Stunde, dazu Grenzen je Client-IP und je Verkaufskanal, nur existierende Produkte, Begriffe normalisiert,
 * nichts Personenbezogenes in der Datenbank.
 * Käufe zählen nur, wenn der Kunde dieses Produkt in den letzten 48 Stunden wirklich bestellt hat,
 * und je Kontext-Token und Produkt nur einmal. Antwortet immer mit 204 – auch wenn nichts gezählt wurde.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class NovaTrackRoute extends AbstractNovaTrackRoute
{
    public const MAX_EVENTS_PER_HOUR = 300;
    private const PURCHASE_DEDUP_SECONDS = 172800;

    private ?TrafficLimiter $limiter = null;

    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly RequestBudget $budget,
        private readonly SystemConfigService $config,
    ) {
    }

    public function setLimiter(TrafficLimiter $limiter): void
    {
        $this->limiter = $limiter;
    }

    public function getDecorated(): AbstractNovaTrackRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/nova-search/track',
        name: 'store-api.nova-search.track',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: [Request::METHOD_POST]
    )]
    public function track(Request $request, SalesChannelContext $context): NoContentResponse
    {
        $salesChannelId = $context->getSalesChannelId();
        if ($this->config->get('NovaSearch.config.learningActive', $salesChannelId) === false) {
            return new NoContentResponse();
        }

        $token = $context->getToken();
        $events = EventRecorder::parse($request->request->all()['events'] ?? null);
        if ($events === [] || $this->limiter === null
            || !$this->limiter->allow(TrafficLimiter::TRACK, $salesChannelId, \count($events), $request)) {
            return new NoContentResponse();
        }

        $accepted = [];
        foreach ($events as $event) {
            if (!$this->budget->consume('track', $salesChannelId . '|' . $token, self::MAX_EVENTS_PER_HOUR, 3600)) {
                break;
            }

            // Einen Kauf je Kontext und Produkt nur einmal zählen (App könnte erneut senden)
            if (($event['type'] ?? '') === EventRecorder::TYPE_PURCHASE) {
                $productId = \is_string($event['productId'] ?? null) ? strtolower($event['productId']) : '';
                if ($productId === '' || !$this->budget->once('purchase', $salesChannelId . '|' . $token . '|' . $productId, self::PURCHASE_DEDUP_SECONDS)) {
                    continue;
                }
            }

            $accepted[] = $event;
        }

        $this->recorder->record($salesChannelId, $accepted, $context->getCustomer()?->getId());

        return new NoContentResponse();
    }
}
