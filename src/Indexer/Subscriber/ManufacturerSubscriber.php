<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Gally\ShopwarePlugin\Indexer\ManufacturerIndexer;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reindex manufacturer on save event.
 */
class ManufacturerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ManufacturerIndexer $manufacturerIndexer
    ) {
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
        $this->manufacturerIndexer->reindex($event->getContext(), $documentsIdsToReindex);
    }
}
