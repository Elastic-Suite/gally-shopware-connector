<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Gally\ShopwarePlugin\Indexer\CategoryIndexer;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reindex category on save event.
 */
class CategorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CategoryIndexer $categoryIndexer
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CategoryEvents::CATEGORY_WRITTEN_EVENT => 'reindex'];
    }

    public function reindex(EntityWrittenEvent $event)
    {
        $documentsIdsToReindex = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $documentsIdsToReindex[] = $writeResult->getPrimaryKey();
        }
        $this->categoryIndexer->reindex($event->getContext(), $documentsIdsToReindex);
    }
}
