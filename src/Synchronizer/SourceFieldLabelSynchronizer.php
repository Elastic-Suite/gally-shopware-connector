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

use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldLabel;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\Context;

/**
 * Synchronize shopware custom field and property labels with gally source field labels.
 */
class SourceFieldLabelSynchronizer extends AbstractSynchronizer
{
    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $putEntityMethod,
        protected LocalizedCatalogSynchronizer $localizedCatalogSynchronizer
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
        /** @var SourceFieldLabel $entity */
        return $entity->getSourceField() . $entity->getLocalizedCatalog();
    }

    public function synchronizeAll(Context $context)
    {
        throw new \LogicException('Run source field synchronizer to sync all localized labels');
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        throw new \LogicException('Run source field synchronizer to sync localized label');
    }

    protected function buildFetchAllParams(int $page): array
    {
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            null,
            null,
            null,
            null,
            $page,
            self::FETCH_PAGE_SIZE,
        ];
    }

    protected function buildFetchOneParams(ModelInterface $entity): array
    {
        /** @var SourceFieldLabel $entity */
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            $entity->getLocalizedCatalog(),
            null,
            $entity->getSourceField(),
        ];
    }
}
