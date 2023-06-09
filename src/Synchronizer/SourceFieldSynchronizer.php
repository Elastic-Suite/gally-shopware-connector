<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\Metadata;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldSourceFieldApi;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupTranslation\PropertyGroupTranslationEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Synchronize shopware custom fields and properties with gally source fields.
 */
class SourceFieldSynchronizer extends AbstractSynchronizer
{
    private array $entitiesToSync = ['category', 'product', 'manufacturer'];
    private array $staticFields = [
        'product' => [
            'manufacturer' => [
                'type' => 'select',
                'labelKey' => 'listing.filterManufacturerDisplayName'
            ],
            'free_shipping' => [
                'type' => 'boolean',
                'labelKey' => 'listing.filterFreeShippingDisplayName'
            ],
            'rating_avg' => [
                'type' => 'float',
                'labelKey' => 'listing.filterRatingDisplayName'
            ],
            'category' => [
                'type' => 'category',
                'labelKey' => 'general.categories'
            ],
        ],
        'manufacturer' => [
            'id' => 'text',
            'name' => 'text',
            'description' => 'text',
            'link' => 'text',
            'image' => 'text',
        ],
    ];
    private string $currentEntity;

    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod,
        private EntityRepository $customFieldRepository,
        private EntityRepository $propertyGroupRepository,
        private MetadataSynchronizer $metadataSynchronizer,
        private SourceFieldLabelSynchronizer $sourceFieldLabelSynchronizer,
        private SourceFieldOptionSynchronizer $sourceFieldOptionSynchronizer,
        private EntityRepository $languageRepository,
        private TranslatorInterface $translator
    ) {
        parent::__construct(
            $configuration,
            $client,
            $entityClass,
            $getCollectionMethod,
            $createEntityMethod,
            $patchEntityMethod
        );
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldSourceFieldApi $entity */
        return $entity->getMetadata() . $entity->getCode();
    }

    public function synchronizeAll(Context $context)
    {
        $this->fetchEntities();
        $this->sourceFieldLabelSynchronizer->fetchEntities();
        $this->sourceFieldOptionSynchronizer->fetchEntities();

        foreach ($this->entitiesToSync as $entity) {
            /** @var Metadata $metadata */
            $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => $entity]);

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', $entity));
            $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);

            // Static fields
            foreach ($this->staticFields[$entity] ?? [] as $code => $data) {
                $labels = [];
                if (is_array($data)) {
                    foreach ($this->getAllAvailableLocales($context) as $locale) {
                        $labels[$locale] = $this->translator->trans($data['labelKey'], [], null, $locale);
                    }
                }
                $this->synchronizeItem(
                    [
                        'metadata' => $metadata,
                        'field' => ['code' => $code, 'type' => is_array($data) ? $data['type'] : $data, 'labels' => $labels]
                    ]
                );
            }

            // Custom fields
            /** @var CustomFieldCollection $customFields */
            $customFields = $this->customFieldRepository->search($criteria, $context)->getEntities();
            foreach ($customFields as $customField) {
                $this->synchronizeItem(['metadata' => $metadata, 'field' => $customField]);
            }

            // Property groups
            if ($entity == 'product') {
                $criteria = new Criteria();
                $criteria->addAssociations([
                    'options',
                    'translations',
                    'options.translations',
                    'translations.language',
                    'translations.language.locale',
                    'options.translations.language',
                    'options.translations.language.locale'
                ]);

                /** @var PropertyGroupCollection $properties */
                $properties = $this->propertyGroupRepository->search($criteria, $context)->getEntities();

                foreach ($properties as $property) {
                    $this->synchronizeItem(['metadata' => $metadata, 'field' => $property]);
                }
            }
        }
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var Metadata $metadata */
        $metadata = $params['metadata'];
        $this->currentEntity = $metadata->getEntity();

        /** @var array|CustomFieldEntity|PropertyGroupEntity $field */
        $field = $params['field'];

        $data = ['metadata' => '/metadata/' . $metadata->getId()];

        if (is_array($field)) {
            $data['code'] = $field['code'];
            $data['type'] = $field['type'];
            $labels = $field['labels'] ?? [];
            // Prevent to update system source field
            if ($field['code'] !== 'category') {
                $data['defaultLabel'] = empty($labels) ? $data['code'] : reset($labels);
            }
        } elseif (is_a($field, CustomFieldEntity::class)) {
            $labels = $field->getConfig()['label'] ?? [];
            $options = $field->getConfig()['options'] ?? [];
            $data['code'] = $field->getName();
            $data['defaultLabel'] = empty($labels) ? $field->getName() : reset($labels);
            $data['type'] = $this->getGallyType($field->getType());
        } elseif (is_a($field, PropertyGroupEntity::class)) {
            $labels = $field->getTranslations();
            $options = $field->getOptions();
            $data['code'] = 'property_' . $field->getId(); // Prefix with property to avoid graphql error if field start with number
            $data['defaultLabel'] = $field->getName();
            $data['type'] = 'select';
        }

        $sourceField = $this->createOrUpdateEntity(new SourceFieldSourceFieldApi($data));

        /** @var string|PropertyGroupTranslationEntity $label */
        foreach ($labels ?? [] as $localeCode => $label) {
            if ($label) {
                $this->sourceFieldLabelSynchronizer->synchronizeItem([
                    'field' => $sourceField,
                    'localeCode' =>  str_replace('-', '_', is_string($label) ? $localeCode: $label->getLanguage()->getLocale()->getCode()),
                    'label' => is_string($label) ? $label : $label->getName(),
                ]);
            }
        }

        foreach ($options ?? [] as $position => $option) {
            $this->sourceFieldOptionSynchronizer->synchronizeItem([
                'field' => $sourceField,
                'option' => $option,
                'position' => $position,
            ]);
        }

        return $sourceField;
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

    public function fetchEntity(ModelInterface $entity): ?ModelInterface
    {
        /** @var SourceFieldSourceFieldApi $entity */
        $results = $this->client->query(...$this->buildFetchOneParams($entity));
        $filteredResults = [];
        /** @var SourceFieldSourceFieldApi $result */
        foreach ($results as $result) {
            // Search by source field code in api is a partial match,
            // to be sure to get the good sourceField we need to check that the code of the result
            // is exactly the code of the sourceField.
            if ($result->getCode() === $entity->getCode()) {
                $filteredResults[] = $result;
            }
        }
        if (count($filteredResults) !== 1) {
            return null;
        }
        return reset($filteredResults);
    }

    protected function buildFetchOneParams(ModelInterface $entity): array
    {
        /** @var SourceFieldSourceFieldApi $entity */
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            $entity->getCode(),
            null,
            null,
            $this->currentEntity
        ];
    }

    private function getAllAvailableLocales(Context $context): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['locale']);
        $languages = $this->languageRepository->search($criteria, $context);

        /** @var LanguageEntity $language */
        foreach ($languages as $language) {
            yield $language->getLocale()->getCode();
        }
    }

    private function getGallyType(string $type): string
    {
        switch ($type) {
            case 'number':
                return 'float';
            case 'date':
                return 'date';
            case 'switch':
            case 'checkbox':
                return 'boolean';
            case 'price':
                return 'price';
            case 'stock':
                return 'stock';
            default:
                return 'text';
        }
    }
}
