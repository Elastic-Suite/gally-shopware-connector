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

namespace Gally\ShopwarePlugin\Indexer\Provider;

use Gally\Sdk\Entity\Label;
use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\Sdk\Entity\Metadata;
use Gally\Sdk\Entity\SourceField;
use Gally\Sdk\Entity\SourceFieldOption;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldCollection;

/**
 * Gally Catalog data provider.
 */
class SourceFieldOptionProvider implements ProviderInterface
{
    private array $entitiesToSync = ['category', 'product', 'manufacturer'];

    /** @var LocalizedCatalog[][] */
    private array $localizedCatalogsByLocale = [];

    public function __construct(
        private CatalogProvider $catalogProvider,
        private EntityRepository $customFieldRepository,
        private EntityRepository $propertyGroupRepository,
    ) {
    }

    /**
     * @return iterable<SourceFieldOption>
     */
    public function provide(Context $context): iterable
    {
        foreach ($this->catalogProvider->provide($context) as $localizedCatalog) {
            $this->localizedCatalogsByLocale[$localizedCatalog->getLocale()][] = $localizedCatalog;
        }

        foreach ($this->entitiesToSync as $entity) {
            $metadata = new Metadata($entity);

            // Custom fields
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', $entity));
            $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);
            /** @var CustomFieldCollection $customFields */
            $customFields = $this->customFieldRepository->search($criteria, $context)->getEntities();
            foreach ($customFields as $customField) {
                $position = 0;
                $sourceField = new SourceField($metadata, $customField->getName(), '', '', []);
                foreach ($customField->getConfig()['options'] ?? [] as $option) {
                    $labels = [];
                    foreach ($option['label'] as $localeCode => $label) {
                        $gallyLocale = str_replace('-', '_', $localeCode);
                        foreach ($this->localizedCatalogsByLocale[$gallyLocale] as $localizedCatalog) {
                            $labels[] = new Label($localizedCatalog, $label);
                        }
                    }

                    yield new SourceFieldOption(
                        $sourceField,
                        $option['value'],
                        ++$position,
                        reset($labels)->getLabel(),
                        $labels,
                    );
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
                    $sourceField = new SourceField($metadata, 'property_' . $property->getId(), '', '', []);
                    foreach ($property->getOptions() as $option) {
                        $labels = [];
                        foreach ($option->getTranslations() as $label) {
                            $gallyLocale = str_replace('-', '_', $label->getLanguage()->getLocale()->getCode());
                            foreach ($this->localizedCatalogsByLocale[$gallyLocale] as $localizedCatalog) {
                                $labels[] = new Label($localizedCatalog, $label->getName());
                            }
                        }

                        yield new SourceFieldOption(
                            $sourceField,
                            $option->getId(),
                            $option->getPosition(),
                            reset($labels)->getLabel(),
                            $labels,
                        );
                    }
                }
            }
        }

        return [];
    }
}
