<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Gally\ShopwarePlugin\Indexer\ProductIndexer;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reindex product on save event.
 */
class ProductSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ProductIndexer $productIndexer
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_WRITTEN_EVENT => 'reindex'];
    }

    public function reindex(EntityWrittenEvent $event)
    {
        $documentsIdsToReindex = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $documentsIdsToReindex[] = $writeResult->getPrimaryKey();
        }
        $this->productIndexer->reindex($documentsIdsToReindex);
    }
}
