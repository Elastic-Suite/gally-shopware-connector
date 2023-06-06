<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Shopware\Core\Content\Product\ProductEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reindex product on save event.
 */
class ProductSubscriber extends AbstractIndexerSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_WRITTEN_EVENT => 'reindex'];
    }
}
