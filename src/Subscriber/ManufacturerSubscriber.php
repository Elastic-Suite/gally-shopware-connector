<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Subscriber;

use Gally\ShopwarePlugin\Indexer\ManufacturerIndexer;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ManufacturerSubscriber implements EventSubscriberInterface
{
    private ManufacturerIndexer $manufacturerIndexer;

    public function __construct(
        ManufacturerIndexer $manufacturerIndexer
    ) {
        $this->manufacturerIndexer = $manufacturerIndexer;
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT => 'reindex'];
    }

    public function reindex(EntityWrittenEvent $event)
    {
        $documentsIdsToReindex = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $documentsIdsToReindex[] = $writeResult->getPrimaryKey();
        }
        $this->manufacturerIndexer->reindex($documentsIdsToReindex);
    }
}
