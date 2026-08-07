<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Gally to newer versions in the future.
 *
 * @package   Gally
 * @author    Gally Team <elasticsuite@smile.fr>
 * @copyright 2022-present Smile
 * @license   Open Software License v. 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Gally\ShopwarePlugin\Indexer\Message\ReindexMessage;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reindex product on save event.
 */
class ProductSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private EntityRepository $productRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'reindex',
            EntityDeleteEvent::class => 'beforeDelete',
        ];
    }

    public function reindex(EntityWrittenEvent $event)
    {
        $documentsIdsToReindex = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $documentsIdsToReindex[] = $writeResult->getPrimaryKey();
        }
        $this->messageBus->dispatch(
            new ReindexMessage(ReindexMessage::ENTIY_PRODUCT, $documentsIdsToReindex)
        );
    }

    /**
     * Gally indexes products by autoIncrement, not by id: the deletion event only ever carries the id, so the
     * autoIncrement values have to be fetched here, while the products still exist, and carried over to the
     * success callback which fires once the deletion actually happened.
     */
    public function beforeDelete(EntityDeleteEvent $event): void
    {
        $ids = $event->getIds(ProductDefinition::ENTITY_NAME);

        if ([] === $ids) {
            return;
        }

        $criteria = new Criteria($ids);
        $autoIncrements = [];
        /** @var ProductEntity $product */
        foreach ($this->productRepository->search($criteria, $event->getContext())->getEntities() as $product) {
            $autoIncrements[] = (string) $product->getAutoIncrement();
        }

        $event->addSuccess(function () use ($autoIncrements): void {
            $this->messageBus->dispatch(
                new ReindexMessage(ReindexMessage::ENTIY_PRODUCT, $autoIncrements, remove: true)
            );
        });
    }
}
