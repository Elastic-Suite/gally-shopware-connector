<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Shopware\Core\Content\Product\ProductEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reindex manufacturer on save event.
 */
class ManufacturerSubscriber extends AbstractIndexerSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT => 'reindex'];
    }
}
