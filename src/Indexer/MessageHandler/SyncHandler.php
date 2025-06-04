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

namespace Gally\ShopwarePlugin\Indexer\MessageHandler;

use Gally\Sdk\Service\StructureSynchonizer;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Message\SyncMessage;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Gally\ShopwarePlugin\Indexer\Provider\SourceFieldProvider;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Message handler to manage async structure synchronisation request.
 */
#[AsMessageHandler]
class SyncHandler
{
    public function __construct(
        private ConfigManager $configManager,
        private EntityRepository $salesChannelRepository,
        private EntityRepository $customFieldRepository,
        private EntityRepository $customFieldSetRepository,
        private EntityRepository $propertyGroupRepository,
        private CatalogProvider $catalogProvider,
        private SourceFieldProvider $sourceFieldProvider,
        private StructureSynchonizer $synchonizer,
    ) {
    }

    public function __invoke(SyncMessage $message): void
    {
        $context = Context::createDefaultContext();
        switch ($message->getEntityCode()) {
            case SyncMessage::ENTITY_SALES_CHANNEL:
                $this->syncSalesChannel($context, $message->getEntityIds());
                break;
            case SyncMessage::ENTITY_PROPERTY_GROUP:
                $this->syncPropertyGroup($context, $message->getEntityIds());
                break;
            case SyncMessage::ENTITY_CUSTOM_FIELD:
                $this->syncCustomField($context, $message->getEntityIds());
                break;
            case SyncMessage::ENTITY_CUSTOM_FIELD_SET:
                $this->syncCustomFieldSet($context, $message->getEntityIds());
                break;
        }
    }

    private function syncSalesChannel(Context $context, string|array $salesChannelId): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $salesChannelId));
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency']);

        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();

        if ($this->configManager->isActive($salesChannel->getId())) {
            /** @var LanguageEntity $language */
            foreach ($salesChannel->getLanguages() as $language) {
                $localizedCatalog = $this->catalogProvider->buildLocalizedCatalog($salesChannel, $language);
                $this->synchonizer->syncLocalizedCatalog($localizedCatalog);
            }
        }
    }

    private function syncPropertyGroup(Context $context, string|array $propertyGroupId): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $propertyGroupId));

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
            ->search($criteria, $context)
            ->getEntities()
            ->first();

        $sourceField = $this->sourceFieldProvider->buildSourceField($property, 'product');
        $this->synchonizer->syncSourceField($sourceField);
    }

    private function syncCustomField(Context $context, string|array $customFieldId)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customFieldId));
        $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);
        /** @var CustomFieldEntity $field */
        $field = $this->customFieldRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();
        foreach ($field->getCustomFieldSet()->getRelations() as $entity) {
            $sourceField = $this->sourceFieldProvider->buildSourceField($field, $entity->getEntityName());
            $this->synchonizer->syncSourceField($sourceField);
        }
    }

    private function syncCustomFieldSet(Context $context, string|array $customFieldSetId)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customFieldSetId));
        $criteria->addAssociations(['customFields', 'relations']);
        /** @var CustomFieldSetEntity $fieldSet */
        $fieldSet = $this->customFieldSetRepository
            ->search($criteria, $context)
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
