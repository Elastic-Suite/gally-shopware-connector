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
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Update gally catalog when sale channel has been updated from shopware side.
 */
class SalesChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SalesChannelEvents::SALES_CHANNEL_WRITTEN => 'onSave'];
    }

    public function onSave(EntityWrittenEvent $event)
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $this->messageBus->dispatch(
                new SyncMessage(SyncMessage::ENTITY_SALES_CHANNEL, $writeResult->getPrimaryKey())
            );
        }
    }
}
