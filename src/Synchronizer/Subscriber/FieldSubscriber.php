<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer\Subscriber;

use Gally\ShopwarePlugin\Synchronizer\MetadataSynchronizer;
use Gally\ShopwarePlugin\Synchronizer\SourceFieldSynchronizer;
use Shopware\Core\Content\Property\PropertyEvents;
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
        private SourceFieldSynchronizer $sourceFieldSynchronizer,
        private MetadataSynchronizer $metadataSynchronizer,
        private EntityRepository $customFieldRepository,
        private EntityRepository $customFieldSetRepository,
        private EntityRepository $propertyGroupRepository
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
                        $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => $entity->getEntityName()]);
                        $this->sourceFieldSynchronizer->synchronizeItem(['metadata' => $metadata, 'field' => $field]);
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
                        'options.translations.language.locale'
                    ]);
                    $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => 'product']);
                    $property = $this->propertyGroupRepository
                        ->search($criteria, $event->getContext())
                        ->getEntities()
                        ->first();
                    $this->sourceFieldSynchronizer->synchronizeItem(
                        ['metadata' => $metadata, 'field' => $property]
                    );
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
                $metadata = $this->metadataSynchronizer->synchronizeItem(['entity' => $entity->getEntityName()]);
                foreach ($fieldSet->getCustomFields() as $customField) {
                    $this->sourceFieldSynchronizer->synchronizeItem(['metadata' => $metadata, 'field' => $customField]);
                }
            }
        }
    }
}
