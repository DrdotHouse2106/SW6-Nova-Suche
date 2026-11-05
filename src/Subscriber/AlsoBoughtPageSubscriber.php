<?php declare(strict_types=1);

namespace Nova\Search\Subscriber;

use Nova\Search\Service\AlsoBoughtBuilder;
use Shopware\Core\Content\Cms\SalesChannel\Struct\CrossSellingStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Zeigt „Kunden kauften auch“ als eigene Zeile unter dem festen Cross-Selling statt als weiteren Reiter:
 * Das erzeugte Cross-Selling wird aus den Reitern des Elements „Cross-Selling“ genommen und am Element
 * als Erweiterung `novaAlsoBought` abgelegt; die Vorlage gibt es danach als eigenen Slider aus.
 */
class AlsoBoughtPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION = 'novaAlsoBought';

    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPage'];
    }

    public function onProductPage(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $cmsPage = $page->getCmsPage();
        if ($cmsPage === null) {
            return;
        }

        $product = $page->getProduct();
        $ids = array_unique(array_filter([
            AlsoBoughtBuilder::crossSellingId($product->getParentId() ?? $product->getId()),
            AlsoBoughtBuilder::crossSellingId($product->getId()),
        ]));

        foreach ($cmsPage->getElementsOfType('cross-selling') as $slot) {
            $data = $slot->getData();
            if (!$data instanceof CrossSellingStruct || $data->getCrossSellings() === null) {
                continue;
            }
            $collection = $data->getCrossSellings();
            foreach ($collection->getElements() as $key => $element) {
                if (!\in_array($element->getCrossSelling()->getId(), $ids, true)) {
                    continue;
                }
                $collection->remove($key);
                if ($element->getTotal() > 0 && $element->getCrossSelling()->isActive()) {
                    $slot->addExtension(self::EXTENSION, $element);
                }
                break;
            }
        }
    }
}
