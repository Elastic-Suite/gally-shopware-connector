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

use Gally\Sdk\Service\StructureSynchonizer;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Update gally catalog when sale channel has been updated from shopware side.
 */
class SalesChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ConfigManager $configManager,
        private EntityRepository $entityRepository,
        private CatalogProvider $catalogProvider,
        private StructureSynchonizer $synchonizer,
    ) {
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

            /** @var SalesChannelEntity $salesChannel */
            $salesChannel = $this->entityRepository
                ->search($criteria, $event->getContext())
                ->getEntities()
                ->first();

            if ($this->configManager->isActive($salesChannel->getId())) {
                /** @var LanguageEntity $language */
                foreach ($salesChannel->getLanguages() as $language) {
                    $localizedCatalog = $this->catalogProvider->buildLocalizedCatalog($salesChannel, $language);
                    $this->synchonizer->syncLocalizedCatalog($localizedCatalog);
                }
            }
        }
    }
}
