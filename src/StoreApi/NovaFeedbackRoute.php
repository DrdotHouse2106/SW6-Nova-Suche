<?php declare(strict_types=1);

namespace Nova\Search\StoreApi;

use Nova\Search\Service\FeedbackRecorder;
use Nova\Search\Service\FeedbackText;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /store-api/nova-search/feedback
 *
 * Body: { "term": "bremse", "rating": "up"|"down", "text"?: "…" }
 *
 * Gleiche Regeln wie im Suchfenster: Grenzen je Client-IP und je Verkaufskanal, eine Bewertung je
 * Kontext-Token, Begriff und Tag (und je IP), Text nur zu „down“ (max. 200 Zeichen, bereinigt) und
 * einmal nachreichbar, Trefferzahl ermittelt der Server. Gespeichert wird nichts Personenbezogenes
 * außer dem, was jemand selbst in den Text schreibt. Antwortet immer mit 204.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class NovaFeedbackRoute extends AbstractNovaFeedbackRoute
{
    public function __construct(private readonly FeedbackRecorder $recorder)
    {
    }

    public function getDecorated(): AbstractNovaFeedbackRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/nova-search/feedback',
        name: 'store-api.nova-search.feedback',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: [Request::METHOD_POST]
    )]
    public function feedback(Request $request, SalesChannelContext $context): NoContentResponse
    {
        $body = $request->request->all();
        $input = ['term' => $body['term'] ?? null, 'rating' => $body['rating'] ?? null, 'text' => $body['text'] ?? null];

        $this->recorder->submit($context->getSalesChannelId(), $input, FeedbackText::SOURCE_APP, $context->getToken(), $request);

        return new NoContentResponse();
    }
}
