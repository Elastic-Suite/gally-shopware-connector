<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\Metadata;
use Gally\Rest\Model\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Synchronize shopware entity with gally metadata.
 */
class MetadataSynchronizer extends AbstractSynchronizer
{
    public function synchronizeAll(SalesChannelEntity $salesChannel)
    {
        throw new \LogicException('Run source field synchronizer to sync all metadata');
    }

    public function synchronizeItem(SalesChannelEntity $salesChannel, array $params = []): ?ModelInterface
    {
        return $this->createOrUpdateEntity($salesChannel, new Metadata(["entity" => $params['entity']]));
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var Metadata $entity */
        return $entity->getEntity();
    }

    protected function getEntityFromApi(SalesChannelEntity $salesChannel, ModelInterface $entity): ?ModelInterface
    {
        if (!$this->allEntityHasBeenFetch) {
            $this->fetchEntities($salesChannel);
        }

        return $this->entityByCode[$this->getIdentity($entity)] ?? null;
    }
}
