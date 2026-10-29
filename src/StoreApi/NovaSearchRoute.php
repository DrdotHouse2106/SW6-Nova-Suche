<?php declare(strict_types=1);

namespace Nova\Search\StoreApi;

use Nova\Search\Rules\SearchRules;
use Nova\Search\Search\LearningProductSearchBuilder;
use Nova\Search\Service\RewriteStore;
use Nova\Search\Service\TrafficLimiter;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /store-api/nova-search/search
 *
 * Gleiche Suche wie im Suchfenster: Shopwares Produktsuche inklusive gelerntem Ranking und
 * gespeicherter Umschreibung (siehe LearningProductSearchBuilder). Liefert nur die Produkt-IDs –
 * Preise, Bilder und Bestand lädt die App wie gewohnt über die Store-API.
 *
 * Die Route zählt die Suche NICHT in der Statistik – die App ruft sie beim Blättern/Nachladen mehrfach
 * auf und meldet jede fertige Suche genau einmal über /store-api/nova-search/track ({ type: "search" }).
 * Begriffe ohne Treffer werden hier nur für die Claude-Auswertung vorgemerkt (ohne Zähler).
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class NovaSearchRoute extends AbstractNovaSearchRoute
{
    public const MIN_TERM_LENGTH = 2;
    public const MAX_TERM_LENGTH = 100;
    public const DEFAULT_LIMIT = 24;
    public const MAX_LIMIT = 48;
    private const MAX_PAGE = 100;

    private ?TrafficLimiter $limiter = null;

    public function __construct(
        private readonly AbstractProductSearchRoute $productSearchRoute,
        private readonly RewriteStore $rewrites,
        private readonly SearchRules $rules,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function setLimiter(TrafficLimiter $limiter): void
    {
        $this->limiter = $limiter;
    }

    public function getDecorated(): AbstractNovaSearchRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/nova-search/search', name: 'store-api.nova-search.search', methods: [Request::METHOD_POST])]
    public function search(Request $request, SalesChannelContext $context): NovaSearchRouteResponse
    {
        $body = $request->request->all();
        $raw = $body['search'] ?? null;
        if ($raw === null || $raw === '') {
            throw RoutingException::missingRequestParameter('search');
        }

        $term = \is_string($raw) ? mb_substr(trim($raw), 0, self::MAX_TERM_LENGTH) : '';
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            throw RoutingException::invalidRequestParameter('search');
        }

        $page = \is_numeric($body['p'] ?? null) ? max(1, min(self::MAX_PAGE, (int) $body['p'])) : 1;
        $limit = \is_numeric($body['limit'] ?? null) && (int) $body['limit'] > 0
            ? min(self::MAX_LIMIT, (int) $body['limit'])
            : self::DEFAULT_LIMIT;
        $order = \is_string($body['order'] ?? null) && preg_match('/^[a-z0-9-]{1,50}$/', $body['order']) === 1 ? $body['order'] : '';

        $params = array_filter([
            'search' => $term,
            'p' => $page,
            'limit' => $limit,
            'order' => $order,
            'properties' => self::ids($body['properties'] ?? null),
            'manufacturer' => self::ids($body['manufacturer'] ?? null),
            // Für die App werden keine Filterwerte gebraucht – nur die Treffer
            'no-aggregations' => true,
            // „Nur exakte Teilenummer“
            LearningProductSearchBuilder::EXACT_NUMBER_PARAM => \in_array($body['exactNumber'] ?? false, [true, 1, '1', 'true'], true) ? 1 : '',
        ], static fn ($value): bool => $value !== '');

        $result = $this->load($request, $params, $context);
        if ($result === null && $order !== '') {
            // Unbekannte Sortierung → mit der Standard-Sortierung erneut versuchen
            unset($params['order']);
            $result = $this->load($request, $params, $context);
        }

        $total = $result?->getTotal() ?? 0;
        $rewriteExtension = $result?->getCriteria()->getExtension(LearningProductSearchBuilder::EXTENSION);
        $rewrite = null;
        if ($rewriteExtension instanceof ArrayStruct) {
            $rewrite = [
                'original' => (string) $rewriteExtension->get('original'),
                'term' => (string) $rewriteExtension->get('rewrite'),
            ];
        }

        if ($page === 1 && $total === 0 && $rewrite === null && !isset($params[LearningProductSearchBuilder::EXACT_NUMBER_PARAM])) {
            $this->markPending($context->getSalesChannelId(), $term);
        }

        $sortings = [];
        foreach ($result?->getAvailableSortings() ?? [] as $sorting) {
            $label = $sorting->getTranslation('label');
            $sortings[] = [
                'key' => $sorting->getKey(),
                'label' => \is_string($label) && $label !== '' ? $label : $sorting->getKey(),
            ];
        }

        return new NovaSearchRouteResponse(
            array_values($result?->getEntities()->getIds() ?? []),
            $total,
            $page,
            $limit,
            $result?->getSorting(),
            $sortings,
            $rewrite,
            $this->rules->redirect($term, $context->getSalesChannelId())
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function load(Request $request, array $params, SalesChannelContext $context): ?ProductListingResult
    {
        // Alle Parameter im Request-Body (POST) – so liest Shopware Filter und Sortierung aus
        $searchRequest = $request->duplicate([], $params);
        $criteria = new Criteria();
        $criteria->setTitle('nova-search::store-api');

        try {
            return $this->productSearchRoute->load($searchRequest, $context, $criteria)->getListingResult();
        } catch (HttpException $e) {
            // z. B. unbekannte Sortierung oder Suchbegriff nur aus Stoppwörtern
            $this->logger->info('Nova Suche (Store-API): Suche ohne Ergebnis', ['exception' => $e]);

            return null;
        }
    }

    /** Neuen Begriff ohne Treffer für die Claude-Umschreibung vormerken (mit Mengenbegrenzung) – zählt die Suche nicht */
    private function markPending(string $salesChannelId, string $term): void
    {
        if ($this->config->get('NovaSearch.config.learningActive', $salesChannelId) === false) {
            return;
        }

        try {
            $this->rewrites->queue($salesChannelId, $term, $this->limiter);
        } catch (\Throwable $e) {
            $this->logger->warning('Nova Suche (Store-API): Begriff konnte nicht vorgemerkt werden', ['exception' => $e]);
        }
    }

    /** IDs mit „|“ getrennt – nur gültige 32-stellige Hex-IDs, max. 50 */
    private static function ids(mixed $value): string
    {
        if (\is_array($value)) {
            $value = implode('|', array_filter($value, 'is_string'));
        }
        if (!\is_string($value)) {
            return '';
        }

        $ids = array_filter(
            explode('|', strtolower($value)),
            static fn (string $id): bool => preg_match('/^[0-9a-f]{32}$/', $id) === 1
        );

        return implode('|', \array_slice(array_values(array_unique($ids)), 0, 50));
    }
}
