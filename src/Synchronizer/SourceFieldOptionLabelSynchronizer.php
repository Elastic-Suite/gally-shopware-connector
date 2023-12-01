<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\LocalizedCatalog;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldOptionLabelSourceFieldOptionLabelRead;
use Gally\Rest\Model\SourceFieldOptionLabelSourceFieldOptionLabelWrite;
use Gally\Rest\Model\SourceFieldOptionSourceFieldOptionLabelRead;
use Gally\Rest\Model\SourceFieldOptionSourceFieldOptionWrite;
use Shopware\Core\Framework\Context;

/**
 * Synchronize shopware custom field and property option labels with gally source field option labels.
 */
class SourceFieldOptionLabelSynchronizer extends SourceFieldLabelSynchronizer
{
    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldOptionLabelSourceFieldOptionLabelRead|SourceFieldOptionLabelSourceFieldOptionLabelWrite $entity */
        /** @var SourceFieldOptionSourceFieldOptionLabelRead|string $sourceFieldOption */
        $sourceFieldOption = $entity->getSourceFieldOption();
        $sourceFieldOption = is_string($sourceFieldOption)
            ? $sourceFieldOption
            : '/source_field_options/' . $sourceFieldOption->getId();
        return $sourceFieldOption . $entity->getLocalizedCatalog();
    }

    public function synchronizeAll(Context $context)
    {
        throw new \LogicException('Run source field synchronizer to sync all localized option labels');
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        throw new \LogicException('Run source field synchronizer to sync localized option label');
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
            null,
            null,
            null,
            $page,
            self::FETCH_PAGE_SIZE,
        ];
    }

    protected function buildFetchOneParams(ModelInterface $entity): array
    {
        /** @var SourceFieldOptionLabelSourceFieldOptionLabelWrite $entity */
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            $entity->getLocalizedCatalog(),
            null,
            $entity->getSourceFieldOption(),
        ];
    }
}
