<?php declare(strict_types=1);

namespace Nova\Search\StoreApi;

use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Store-API: Rückmeldung zum Suchergebnis aus Apps (Daumen hoch/runter, optionaler Text).
 */
abstract class AbstractNovaFeedbackRoute
{
    abstract public function getDecorated(): AbstractNovaFeedbackRoute;

    abstract public function feedback(Request $request, SalesChannelContext $context): NoContentResponse;
}
