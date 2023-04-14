<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Subscriber;

use Gally\ShopwarePlugin\Indexer\ManufacturerIndexer;
use Gally\ShopwarePlugin\Indexer\ProductIndexer;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductSubscriber implements EventSubscriberInterface
{
    private ProductIndexer $productIndexer;

    public function __construct(
        ProductIndexer $productIndexer
    ) {
        $this->productIndexer = $productIndexer;
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
