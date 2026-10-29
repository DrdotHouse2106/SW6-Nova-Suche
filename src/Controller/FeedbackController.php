<?php declare(strict_types=1);

namespace Nova\Search\Controller;

use Nova\Search\Service\FeedbackRecorder;
use Nova\Search\Service\FeedbackText;
use Nova\Search\Service\SearchCountGuard;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * „Gefällt Ihnen das Suchergebnis?“ – nimmt Daumen hoch/runter und den optionalen Freitext aus dem
 * Suchfenster und von der Suchergebnisseite entgegen.
 *
 * Schutz wie bei den Lernsignalen (TrackingController): nur mit bestehender Shop-Sitzung, von derselben
 * Website (Sec-Fetch-Site) und ohne Bots. Zusätzlich nur als JSON (Content-Type application/json) – ein
 * fremdes Formular oder ein einfacher Cross-Site-Aufruf kann das nicht senden, ohne dass der Browser
 * vorher nachfragt (CORS). Grenzen je IP und Verkaufskanal, eine Bewertung je Besucher, Begriff und Tag:
 * siehe FeedbackRecorder. Antwortet immer mit 204, damit sich nichts ausprobieren lässt.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class FeedbackController extends StorefrontController
{
    private const MAX_BODY_BYTES = 2048;

    public function __construct(private readonly FeedbackRecorder $recorder)
    {
    }

    #[Route(
        path: '/nova-search/feedback',
        name: 'frontend.nova-search.feedback',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: [Request::METHOD_POST]
    )]
    public function feedback(Request $request, SalesChannelContext $context): Response
    {
        $noContent = new Response(null, Response::HTTP_NO_CONTENT);

        if (!$request->hasPreviousSession() || SearchCountGuard::isBot($request) || !self::isSameOrigin($request)
            || !str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return $noContent;
        }

        $body = (string) $request->getContent();
        $data = \strlen($body) <= self::MAX_BODY_BYTES ? json_decode($body, true) : null;
        if (!\is_array($data)) {
            return $noContent;
        }

        $source = FeedbackText::source($data['source'] ?? null, [FeedbackText::SOURCE_LAYER, FeedbackText::SOURCE_PAGE])
            ?? FeedbackText::SOURCE_PAGE;

        $this->recorder->submit($context->getSalesChannelId(), $data, $source, $request->getSession()->getId(), $request);

        return $noContent;
    }

    /** Fehlt der Header (ältere Browser), wird nicht geblockt – die übrigen Prüfungen greifen trotzdem */
    private static function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        return $site === null || $site === 'same-origin';
    }
}
