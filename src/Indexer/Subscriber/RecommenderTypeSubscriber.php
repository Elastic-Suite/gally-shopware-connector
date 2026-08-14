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

use Gally\ShopwarePlugin\Indexer\Message\SyncMessage;
use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sync gally recommender types with gally when they are edited or deleted from the admin.
 */
class RecommenderTypeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            GallyRecommenderTypeDefinition::ENTITY_NAME . '.written' => 'onWrite',
            GallyRecommenderTypeDefinition::ENTITY_NAME . '.deleted' => 'onDelete',
        ];
    }

    public function onWrite(EntityWrittenEvent $event): void
    {
        $recommenderTypeIds = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $recommenderTypeIds[] = $writeResult->getPrimaryKey();
        }
        $this->messageBus->dispatch(
            new SyncMessage(SyncMessage::ENTITY_RECOMMENDER_TYPE, $recommenderTypeIds)
        );
    }

    /**
     * Gally identifies recommender types by their code, which is no longer available once the
     * Shopware row is deleted. Trigger a full resync with clean=true instead of a per-id delete,
     * so the deleted code gets removed from gally's side too.
     */
    public function onDelete(): void
    {
        $this->messageBus->dispatch(
            new SyncMessage(SyncMessage::ENTITY_RECOMMENDER_TYPE_DELETED, [])
        );
    }
}
