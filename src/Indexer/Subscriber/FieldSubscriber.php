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
use Shopware\Core\Content\Property\PropertyEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\CustomField\CustomFieldEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Update gally source field when related entity has been updated from shopware side.
 */
class FieldSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PropertyEvents::PROPERTY_GROUP_WRITTEN_EVENT => 'onFieldUpdate',
            CustomFieldEvents::CUSTOM_FIELD_WRITTEN_EVENT => 'onFieldUpdate',
            CustomFieldEvents::CUSTOM_FIELD_SET_WRITTEN_EVENT => 'onFieldSetUpdate',
        ];
    }

    public function onFieldUpdate(EntityWrittenEvent $event)
    {
        foreach ($event->getWriteResults() as $writeResult) {
            switch ($writeResult->getEntityName()) {
                case 'custom_field':
                    $this->messageBus->dispatch(
                        new SyncMessage(SyncMessage::ENTITY_CUSTOM_FIELD, $writeResult->getPrimaryKey())
                    );
                    break;
                default:
                    $this->messageBus->dispatch(
                        new SyncMessage(SyncMessage::ENTITY_PROPERTY_GROUP, $writeResult->getPrimaryKey())
                    );
                    break;
            }
        }
    }

    public function onFieldSetUpdate(EntityWrittenEvent $event)
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $this->messageBus->dispatch(
                new SyncMessage(SyncMessage::ENTITY_CUSTOM_FIELD_SET, $writeResult->getPrimaryKey())
            );
        }
    }
}
