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
use Gally\ShopwarePlugin\Indexer\Provider\SourceFieldProvider;
use Shopware\Core\Content\Property\PropertyEvents;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\CustomField\CustomFieldEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Update gally source field when related entity has been updated from shopware side.
 */
class FieldSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityRepository $customFieldRepository,
        private EntityRepository $customFieldSetRepository,
        private EntityRepository $propertyGroupRepository,
        private SourceFieldProvider $sourceFieldProvider,
        private StructureSynchonizer $synchonizer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PropertyEvents::PROPERTY_GROUP_WRITTEN_EVENT => 'onFieldUpdate',
            CustomFieldEvents::CUSTOM_FIELD_WRITTEN_EVENT => 'onFieldUpdate',
            CustomFieldEvents::CUSTOM_FIELD_SET_WRITTEN_EVENT => 'onFieldSetUpdate',
        ];
    }

    public function onFieldUpdate(EntityWrittenEvent $event)
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));

            switch ($writeResult->getEntityName()) {
                case 'custom_field':
                    $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);
                    /** @var CustomFieldEntity $field */
                    $field = $this->customFieldRepository
                        ->search($criteria, $event->getContext())
                        ->getEntities()
                        ->first();
                    foreach ($field->getCustomFieldSet()->getRelations() as $entity) {
                        $sourceField = $this->sourceFieldProvider->buildSourceField($field, $entity->getEntityName());
                        $this->synchonizer->syncSourceField($sourceField);
                    }
                    break;
                default:
                    $criteria->addAssociations([
                        'options',
                        'translations',
                        'options.translations',
                        'translations.language',
                        'translations.language.locale',
                        'options.translations.language',
                        'options.translations.language.locale',
                    ]);
                    /** @var PropertyGroupEntity $property */
                    $property = $this->propertyGroupRepository
                        ->search($criteria, $event->getContext())
                        ->getEntities()
                        ->first();

                    $sourceField = $this->sourceFieldProvider->buildSourceField($property, 'product');
                    $this->synchonizer->syncSourceField($sourceField);
                    break;
            }
        }
    }

    public function onFieldSetUpdate(EntityWrittenEvent $event)
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));
            $criteria->addAssociations(['customFields', 'relations']);
            /** @var CustomFieldSetEntity $fieldSet */
            $fieldSet = $this->customFieldSetRepository
                ->search($criteria, $event->getContext())
                ->getEntities()
                ->first();
            foreach ($fieldSet->getRelations() as $entity) {
                foreach ($fieldSet->getCustomFields() as $customField) {
                    $sourceField = $this->sourceFieldProvider->buildSourceField($customField, $entity->getEntityName());
                    $this->synchonizer->syncSourceField($sourceField);
                }
            }
        }
    }
}
