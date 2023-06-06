<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\Catalog;
use Gally\Rest\Model\ModelInterface;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Synchronize shopware sale channels with gally catalogs and localizedCatalogs.
 */
class CatalogSynchronizer extends AbstractSynchronizer
{
    private EntityRepository $entityRepository;
    private LocalizedCatalogSynchronizer $localizedCatalogSynchronizer;

    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod,
        EntityRepository $entityRepository,
        LocalizedCatalogSynchronizer $localizedCatalogSynchronizer
    ) {
        parent::__construct(
            $configuration,
            $client,
            $entityClass,
            $getCollectionMethod,
            $createEntityMethod,
            $patchEntityMethod
        );
        $this->entityRepository = $entityRepository;
        $this->localizedCatalogSynchronizer = $localizedCatalogSynchronizer;
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var Catalog $entity */
        return $entity->getCode();
    }

    public function synchronizeAll(SalesChannelEntity $salesChannel)
    {
        $this->fetchEntities($salesChannel);
        $this->localizedCatalogSynchronizer->fetchEntities($salesChannel);

        $this->synchronizeItem($salesChannel);
    }

    public function synchronizeItem(SalesChannelEntity $salesChannel, array $params = []): ?ModelInterface
    {
        if ($this->configuration->isActive($salesChannel->getId())) {

            $catalog = $this->createOrUpdateEntity(
                $salesChannel,
                new Catalog([
                    'code' => $salesChannel->getId(),
                    'name' => $salesChannel->getName(),
                ])
            );

            /** @var LanguageEntity $language */
            foreach ($salesChannel->getLanguages() as $language) {
                $this->localizedCatalogSynchronizer->synchronizeItem(
                    $salesChannel,
                    [
                        'language' => $language,
                        'catalog' => $catalog,
                    ]
                );
            }

            return $catalog;
        }

        return null;
    }
}

