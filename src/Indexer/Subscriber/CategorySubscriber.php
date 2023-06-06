<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Shopware\Core\Content\Category\CategoryEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reindex category on save event.
 */
class CategorySubscriber extends AbstractIndexerSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [CategoryEvents::CATEGORY_WRITTEN_EVENT => 'reindex'];
    }
}
