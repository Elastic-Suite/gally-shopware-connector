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

use Gally\Rest\Model\LocalizedCatalog;
use Gally\Rest\Model\MetadataMetadataRead;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldOptionSourceFieldOptionRead;
use Gally\Rest\Model\SourceFieldOptionSourceFieldOptionWrite;
use Gally\Rest\Model\SourceFieldSourceFieldRead;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldCollection;

/**
 * Synchronize shopware custom field and property options with gally source field options.
 */
class SourceFieldOptionSynchronizer extends AbstractSynchronizer
{
    private array $entitiesToSync = ['category', 'product', 'manufacturer'];
    private array $sourceFieldOptionCodes = [];

    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $putEntityMethod,
        string $deleteEntityMethod,
        string $bulkEntityMethod,
        private EntityRepository $customFieldRepository,
        private EntityRepository $propertyGroupRepository,
        private SourceFieldSynchronizer $sourceFieldSynchronizer,
        private MetadataSynchronizer $metadataSynchronizer,
        private LocalizedCatalogSynchronizer $localizedCatalogSynchronizer
    ) {
        parent::__construct(
            $configuration,
            $client,
            $entityClass,
            $getCollectionMethod,
            $createEntityMethod,
            $putEntityMethod,
            $deleteEntityMethod,
            $bulkEntityMethod
        );
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldOptionSourceFieldOptionWrite $entity */
        return $entity->getSourceField() . $entity->getCode();
    }

    public function synchronizeAll(Context $context)
    {
        $this->sourceFieldSynchronizer->fetchEntities();

        foreach ($this->entitiesToSync as $entity) {
            /** @var MetadataMetadataRead $metadata */
            $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => $entity]);

            // Custom fields
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', $entity));
            $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);
            /** @var CustomFieldCollection $customFields */
            $customFields = $this->customFieldRepository->search($criteria, $context)->getEntities();
            foreach ($customFields as $customField) {
                $position = 0;
                foreach ($customField->getConfig()['options'] ?? [] as $option) {
                    $sourceField = $this->sourceFieldSynchronizer->getEntityByCode($metadata, $customField->getName());

                    $labels = [];
                    foreach ($option['label'] as $localeCode => $label) {
                        $labels[] = [
                            'locale' => str_replace('-', '_', $localeCode),
                            'label' => $label
                        ];
                    }

                    $this->synchronizeItem([
                        'sourceField' => $sourceField,
                        'code' => $option['value'],
                        'labels' => $labels,
                        'position' => ++$position,
                    ]);
                }
            }

            // Property groups
            if ('product' == $entity) {
                $criteria = new Criteria();
                $criteria->addAssociations([
                    'options',
                    'options.translations',
                    'options.translations.language',
                    'options.translations.language.locale',
                ]);

                /** @var PropertyGroupCollection $properties */
                $properties = $this->propertyGroupRepository->search($criteria, $context)->getEntities();

                foreach ($properties as $property) {
                    foreach ($property->getOptions() as $option) {
                        $sourceField = $this->sourceFieldSynchronizer->getEntityByCode($metadata, 'property_' . $property->getId());

                        $labels = [];
                        foreach ($option->getTranslations() as $label) {
                            $labels[] = [
                                'locale' => str_replace('-', '_', $label->getLanguage()->getLocale()->getCode()),
                                'label' => $label->getName()
                            ];
                        }

                        $this->synchronizeItem([
                            'sourceField' => $sourceField,
                            'code' => $option->getId(),
                            'labels' => $labels,
                            'position' => $option->getPosition(),
                        ]);
                    }
                }
            }
        }

        $this->runBulk();
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var SourceFieldSourceFieldRead $sourceField */
        $sourceField = $params['sourceField'];

        /** @var array $labels */
        $labels = $params['labels'];

        $data = [
            'sourceField' => '/source_fields/' . $sourceField->getId(),
            'code' => $params['code'],
            'defaultLabel' => reset($labels)['label'],
            'position' => $params['position'],
            'labels' => [],
        ];

        foreach ($labels as $label) {
            $locale = $label['locale'];
            /** @var LocalizedCatalog $localizedCatalog */
            foreach ($this->localizedCatalogSynchronizer->getLocalizedCatalogByLocale($locale) as $localizedCatalog) {
                $data['labels'][] = [
                    'localizedCatalog' => '/localized_catalogs/' . $localizedCatalog->getId(),
                    'label' => $label['label'],
                ];
            }
        }

        $sourceFieldOption = new SourceFieldOptionSourceFieldOptionWrite($data);
        $this->addEntityToBulk($sourceFieldOption);

        return $sourceFieldOption;
    }

    public function cleanAll(Context $context, bool $dryRun = true, bool $quiet = false): void
    {
        $this->sourceFieldOptionCodes = array_flip($this->getAllEntityCodes());
        $this->sourceFieldSynchronizer->fetchEntities();

        foreach ($this->entitiesToSync as $entity) {
            /** @var MetadataMetadataRead $metadata */
            $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => $entity]);

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', $entity));
            $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);

            // Custom fields
            /** @var CustomFieldCollection $customFields */
            $customFields = $this->customFieldRepository->search($criteria, $context)->getEntities();
            foreach ($customFields as $customField) {
                foreach ($customField->getConfig()['options'] ?? [] as $option) {
                    /** @var SourceFieldSourceFieldRead $sourceField */
                    $sourceField = $this->sourceFieldSynchronizer->getEntityByCode($metadata, $customField->getName());
                    unset($this->sourceFieldOptionCodes['/source_fields/' . $sourceField->getId() . $option['value']]);
                }
            }

            // Property groups
            if ('product' == $entity) {
                $criteria = new Criteria();
                $criteria->addAssociations(['options']);

                /** @var PropertyGroupCollection $properties */
                $properties = $this->propertyGroupRepository->search($criteria, $context)->getEntities();

                foreach ($properties as $property) {
                    foreach ($property->getOptions() as $option) {
                        /** @var SourceFieldSourceFieldRead $sourceField */
                        $sourceField = $this->sourceFieldSynchronizer->getEntityByCode($metadata, 'property_' . $property->getId());
                        unset($this->sourceFieldOptionCodes['/source_fields/' . $sourceField->getId() . $option->getId()]);
                    }
                }
            }
        }

        foreach (array_flip($this->sourceFieldOptionCodes) as $sourceFieldOptionCode) {
            /** @var SourceFieldOptionSourceFieldOptionRead $sourceFieldOption */
            $sourceFieldOption = $this->getEntityFromApi($sourceFieldOptionCode);
            if (!$quiet) {
                print("  Delete sourceFieldOption {$sourceFieldOption->getSourceField()} {$sourceFieldOption->getCode()}\n");
            }
            if (!$dryRun) {
                $this->deleteEntity($sourceFieldOption->getId());
            }
        }
    }

    public function fetchEntity(ModelInterface $entity): ?ModelInterface
    {
        /** @var SourceFieldOptionSourceFieldOptionWrite $entity */
        $results = $this->client->query(...$this->buildFetchOneParams($entity));
        $filteredResults = [];
        /** @var SourceFieldOptionSourceFieldOptionWrite $result */
        foreach ($results as $result) {
            // It is not possible to search by source field option code in api.
            // So we need to get the good option after.
            if ($result->getCode() === $entity->getCode()) {
                $filteredResults[] = $result;
            }
        }
        if (1 !== \count($filteredResults)) {
            return null;
        }

        return reset($filteredResults);
    }

    protected function buildFetchAllParams(int $page): array
    {
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            null,
            null,
            null,
            $page,
            self::FETCH_PAGE_SIZE,
        ];
    }

    protected function buildFetchOneParams(ModelInterface $entity): array
    {
        /** @var SourceFieldOptionSourceFieldOptionWrite $entity */
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            $entity->getSourceField(),
        ];
    }
}
