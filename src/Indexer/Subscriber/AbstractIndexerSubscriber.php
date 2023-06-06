<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Abstract class to factorize logic on reindex after save event.
 */
abstract class AbstractIndexerSubscriber implements EventSubscriberInterface
{
    protected Configuration $configuration;
    protected EntityRepository $salesChannelRepository;
    protected AbstractIndexer $indexer;

    public function __construct(
        Configuration $configuration,
        EntityRepository $salesChannelRepository,
        AbstractIndexer $indexer
    ) {
        $this->configuration = $configuration;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->indexer = $indexer;
    }

    abstract public static function getSubscribedEvents(): array;

    public function reindex(EntityWrittenEvent $event)
    {
        $documentsIdsToReindex = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $documentsIdsToReindex[] = $writeResult->getPrimaryKey();
        }

        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency', 'domains']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configuration->isActive($salesChannel->getId())) {
                $this->indexer->reindex($salesChannel, $documentsIdsToReindex);
            }
        }
    }
}
