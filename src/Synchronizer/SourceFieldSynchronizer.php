<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\Metadata;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldSourceFieldApi;
use Gally\ShopwarePlugin\Api\Client;
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

class SourceFieldSynchronizer extends AbstractSynchronizer
{
    // Todo get from conf
    private array $entitiesToSync = ['category', 'product', 'manufacturer'];
    private array $staticFields = [
        'category' => [
//            'id' => 'text', TODO don't need to create system source field
//            'name' => 'text',
//            'path' => 'text',
//            'level' => 'number',
//            'parentId' => 'string'
        ],
        'product' => [
//            'id' => 'int',
//            'sku' => 'text',
//            'category' => 'int',
//            'name' => 'string',
//            'price' => 'price',
//            'image' => 'text',
//            'stock' => 'stock'
        ]
    ];

    private EntityRepository $customFieldRepository;
    private EntityRepository $propertyGroupRepository;
    private MetadataSynchronizer $metadataSynchronizer;
    private SourceFieldLabelSynchronizer $sourceFieldLabelSynchronizer;
    private SourceFieldOptionSynchronizer $sourceFieldOptionSynchronizer;

    public function __construct(
        Configuration $configuration,
        Client $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod,
        EntityRepository $customFieldRepository,
        EntityRepository $propertyGroupRepository,
        MetadataSynchronizer $metadataSynchronizer,
        SourceFieldLabelSynchronizer $sourceFieldLabelSynchronizer,
        SourceFieldOptionSynchronizer $sourceFieldOptionSynchronizer
    ) {
        parent::__construct(
            $configuration,
            $client,
            $entityClass,
            $getCollectionMethod,
            $createEntityMethod,
            $patchEntityMethod
        );
        $this->customFieldRepository = $customFieldRepository;
        $this->propertyGroupRepository = $propertyGroupRepository;
        $this->metadataSynchronizer = $metadataSynchronizer;
        $this->sourceFieldLabelSynchronizer = $sourceFieldLabelSynchronizer;
        $this->sourceFieldOptionSynchronizer = $sourceFieldOptionSynchronizer;
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldSourceFieldApi $entity */
        return $entity->getCode();
    }

    public function synchronizeAll()
    {
        foreach ($this->entitiesToSync as $entity) {
            /** @var Metadata $metadata */
            $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => $entity]);

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', $entity));
            $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);

            // Static fields
            foreach ($this->staticFields[$entity] ?? [] as $code => $type) {
                $this->synchronizeItem(
                    [
                        'metadata' => $metadata,
                        'field' => ['code' => $code, 'type' => $type]
                    ]
                );
            }

            // Custom fields
            /** @var CustomFieldCollection $customFields */
            $customFields = $this->customFieldRepository
                ->search($criteria, Context::createDefaultContext())
                ->getEntities();
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
                $properties = $this->propertyGroupRepository
                    ->search($criteria, Context::createDefaultContext())
                    ->getEntities();

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

        /** @var array|CustomFieldEntity|PropertyGroupEntity $field */
        $field = $params['field'];

        $data = [
            'metadata'       => '/metadata/' . $metadata->getId(),
            'isSearchable'   => false,
            'weight'         => 1,
            'isSpellchecked' => false,
            'isFilterable'   => false,
            'isSortable'     => false,
            'isUsedForRules' => false,
        ];

        if (is_array($field)) {
            $data['code'] = $data['defaultLabel'] = $field['code'];
            $data['type'] = $this->getGallyType($field['type']);
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
            $data['type'] = $this->getGallyType('select');
        }

        $sourceField = $this->createOrUpdateEntity(new SourceFieldSourceFieldApi($data));

        /** @var string|PropertyGroupTranslationEntity $label */
        foreach ($labels ?? [] as $localeCode => $label) {
            $this->sourceFieldLabelSynchronizer->synchronizeItem([
                'field' => $sourceField,
                'localeCode' =>  str_replace('-', '_', is_string($label) ? $localeCode: $label->getLanguage()->getLocale()->getCode()),
                'label' => is_string($label) ? $label : $label->getName(),
            ]);
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

    protected function fetchEntities()
    {
        if (empty($this->entityById)) {
            $currentPage = 1;
            do {
                $entities = $this->client->query(
                    $this->entityClass,
                    $this->getCollectionMethod,
                    // Can't used named function argument in php7.4
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
                    $currentPage,
                    30
                );

                foreach ($entities as $entity) {
                    $this->addEntityByIdentity($entity);
                }
                $currentPage++;
            } while (count($entities) > 0);
        }
    }

    private function syncProperties(Metadata $metadata, PropertyGroupCollection $properties)
    {
        /** @var PropertyGroupEntity $property */
        foreach ($properties as $property) {
            $this->createEntityIfNotExists(
                new SourceFieldSourceFieldApi([
                    'metadata'       => '/metadata/' . $metadata->getId(),
                    'code'           => $property->getId(),
                    'defaultLabel'   => $property->getName(),
                    'type'           => 'select',
                    'isSearchable'   => false,
                    'weight'         => 1,
                    'isSpellchecked' => false,
                    'isFilterable'   => $property->getFilterable(),
                    'isSortable'     => false,
                    'isUsedForRules' => false,
                ])
            );

            foreach ($property->getOptions() as $option) {
                print($option->getName());
            }
        }
    }

    private function getGallyType(string $type): string
    {
        switch ($type) {
            case 'entity':
            case 'select':
                return 'select';
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
