<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Subscriber;

use Gally\ShopwarePlugin\Synchronizer\CatalogSynchronizer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesChannelSubscriber implements EventSubscriberInterface
{
    private CatalogSynchronizer $catalogSynchronizer;
    private EntityRepository $entityRepository;

    public function __construct(
        CatalogSynchronizer $catalogSynchronizer,
        EntityRepository $entityRepository
    ) {
        $this->catalogSynchronizer = $catalogSynchronizer;
        $this->entityRepository = $entityRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [SalesChannelEvents::SALES_CHANNEL_WRITTEN => 'onSave'];
    }

    public function onSave(EntityWrittenEvent $event)
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));
            $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency']);

            $salesChannel = $this->entityRepository
                ->search($criteria, Context::createDefaultContext())
                ->getEntities()
                ->first();

            $this->catalogSynchronizer->synchronizeItem(['salesChannel' => $salesChannel]);
        }
    }
}
