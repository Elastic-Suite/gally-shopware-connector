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

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\Catalog;
use Gally\Rest\Model\ModelInterface;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Synchronize shopware sale channels with gally catalogs and localizedCatalogs.
 */
class CatalogSynchronizer extends AbstractSynchronizer
{
    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $putEntityMethod,
        private EntityRepository $entityRepository,
        private LocalizedCatalogSynchronizer $localizedCatalogSynchronizer
    ) {
        parent::__construct(
            $configuration,
            $client,
            $entityClass,
            $getCollectionMethod,
            $createEntityMethod,
            $putEntityMethod
        );
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var Catalog $entity */
        return $entity->getCode();
    }

    public function synchronizeAll(Context $context)
    {
        $this->fetchEntities();
        $this->localizedCatalogSynchronizer->fetchEntities();

        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->entityRepository->search($criteria, $context)->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            $this->synchronizeItem(['salesChannel' => $salesChannel]);
        }
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $params['salesChannel'];
        if ($this->configuration->isActive($salesChannel->getId())) {
            $catalog = $this->createOrUpdateEntity(
                new Catalog([
                    'code' => $salesChannel->getId(),
                    'name' => $salesChannel->getName(),
                ])
            );

            /** @var LanguageEntity $language */
            foreach ($salesChannel->getLanguages() as $language) {
                $this->localizedCatalogSynchronizer->synchronizeItem([
                    'salesChannel' => $salesChannel,
                    'language' => $language,
                    'catalog' => $catalog,
                ]);
            }

            return $catalog;
        }

        return null;
    }
}
