<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\LocalizedCatalog;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldLabel;
use Gally\Rest\Model\SourceFieldSourceFieldApi;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;

/**
 * Synchronize shopware custom field and property labels with gally source field labels.
 */
class SourceFieldLabelSynchronizer extends AbstractSynchronizer
{
    protected LocalizedCatalogSynchronizer $localizedCatalogSynchronizer;

    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod,
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
        $this->localizedCatalogSynchronizer = $localizedCatalogSynchronizer;
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldLabel $entity */
        return $entity->getSourceField() . $entity->getLocalizedCatalog();
    }

    public function synchronizeAll()
    {
        throw new \LogicException('Run source field synchronizer to sync all localized labels');
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var SourceFieldSourceFieldApi $sourceField */
        $sourceField = $params['field'];

        /** @var string $localeCode */
        $localeCode = $params['localeCode'];

        /** @var string $label */
        $label = $params['label'];

        /** @var LocalizedCatalog $localizedCatalog */
        foreach ($this->localizedCatalogSynchronizer->getLocalizedCatalogByLocale($localeCode) as $localizedCatalog) {
            $this->createOrUpdateEntity(
                new SourceFieldLabel(
                    [
                        'sourceField'      => '/source_fields/' . $sourceField->getId() ,
                        'localizedCatalog' => '/localized_catalogs/' . $localizedCatalog->getId(),
                        'label'            => $label,
                    ]
                )
            );
        }

        return null;
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
