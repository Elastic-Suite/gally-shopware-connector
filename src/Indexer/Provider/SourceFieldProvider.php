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
use Gally\ShopwarePlugin\Indexer\Event\ProvideAdditionalSourceFieldEvent;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupTranslation\PropertyGroupTranslationCollection;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gally Catalog data provider.
 */
class SourceFieldProvider implements ProviderInterface
{
    private array $entitiesToSync = ['category', 'product', 'manufacturer'];
    private array $staticFields = [
        'product' => [
            [
                'code' => 'manufacturer',
                'type' => 'select',
                'labelKey' => 'listing.filterManufacturerDisplayName',
            ],
            [
                'code' => 'free_shipping',
                'type' => 'boolean',
                'labelKey' => 'listing.filterFreeShippingDisplayName',
            ],
            [
                'code' => 'rating_avg',
                'type' => 'float',
                'labelKey' => 'listing.filterRatingDisplayName',
            ],
            [
                'code' => 'category',
                'type' => 'category',
                'labelKey' => 'general.categories',
            ],
        ],
        'manufacturer' => [
            [
                'code' => 'id',
                'type' => 'text',
            ],
            [
                'code' => 'name',
                'type' => 'text',
            ],
            [
                'code' => 'description',
                'type' => 'text',
            ],
            [
                'code' => 'link',
                'type' => 'text',
            ],
            [
                'code' => 'image',
                'type' => 'text',
            ],
        ],
    ];

    /** @var LocalizedCatalog[] */
    private array $localizedCatalogs = [];
    private array $metadataCache = [];

    public function __construct(
        private CatalogProvider $catalogProvider,
        private EntityRepository $customFieldRepository,
        private EntityRepository $propertyGroupRepository,
        private TranslatorInterface $translator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return iterable<SourceField>
     */
    public function provide(Context $context): iterable
    {
        foreach ($this->catalogProvider->provide($context) as $localizedCatalog) {
            $this->localizedCatalogs[] = $localizedCatalog;
        }

        $additionalSourceFieldEvent = new ProvideAdditionalSourceFieldEvent();
        $this->eventDispatcher->dispatch($additionalSourceFieldEvent, ProvideAdditionalSourceFieldEvent::NAME);

        foreach ($this->entitiesToSync as $entity) {
            // Static fields
            foreach ($this->staticFields[$entity] ?? [] as $data) {
                yield $this->buildSourceField($data, $entity);
            }

            // Custom fields
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', $entity));
            $criteria->addAssociations(['customFieldSet', 'customFieldSet.relations']);
            /** @var CustomFieldCollection $customFields */
            $customFields = $this->customFieldRepository->search($criteria, $context)->getEntities();
            foreach ($customFields as $customField) {
                yield $this->buildSourceField($customField, $entity);
            }

            // Property groups
            if ('product' == $entity) {
                $criteria = new Criteria();
                $criteria->addAssociations(['translations', 'translations.language', 'translations.language.locale']);

                /** @var PropertyGroupCollection $properties */
                $properties = $this->propertyGroupRepository->search($criteria, $context)->getEntities();

                foreach ($properties as $property) {
                    yield $this->buildSourceField($property, $entity);
                }
            }

            // Additional fields
            foreach ($additionalSourceFieldEvent->getAdditionalSourceFields()[$entity] ?? [] as $data) {
                yield $this->buildSourceField($data, $entity);
            }
        }
    }

    public function buildSourceField(CustomFieldEntity|PropertyGroupEntity|array $field, string $entity): SourceField
    {
        if (!\array_key_exists($entity, $this->metadataCache)) {
            $this->metadataCache[$entity] = new Metadata($entity);
        }

        switch (\is_array($field) ? 'array' : $field::class) {
            case CustomFieldEntity::class:

                $labels = $field->getConfig()['label'] ?? [];
                /** @var Label $firstLabel */
                $firstLabel = reset($labels);

                return new SourceField(
                    $this->metadataCache[$entity],
                    $field->getName(),
                    $this->getGallyType($field->getType()),
                    empty($labels) ? $field->getName() : $firstLabel,
                    $this->getLabels($labels),
                );

            case PropertyGroupEntity::class:
                return new SourceField(
                    $this->metadataCache[$entity],
                    'property_' . $field->getId(),
                    'select',
                    $field->getName(),
                    $this->getLabels($field->getTranslations()),
                );

            default:
                $labels = isset($field['labelKey']) ? $this->getLabels($field['labelKey']) : [];

                return new SourceField(
                    $this->metadataCache[$entity],
                    $field['code'],
                    $field['type'],
                    empty($labels) ? $field['code'] : reset($labels)->getLabel(),
                    $labels,
                );
        }
    }

    /**
     * @return Label[]
     */
    private function getLabels(string|array|PropertyGroupTranslationCollection $labelKey): array
    {
        $labelsByLocal = [];
        if (is_iterable($labelKey)) {
            foreach ($labelKey as $localeCode => $label) {
                $localeCode = str_replace(
                    '-',
                    '_',
                    \is_string($label) ? $localeCode : $label->getLanguage()->getLocale()->getCode()
                );
                $labelsByLocal[$localeCode] = \is_string($label) ? $label : $label->getName();
            }
        }

        $labels = [];
        foreach ($this->localizedCatalogs as $localizedCatalog) {
            $localeCode = str_replace('_', '-', $localizedCatalog->getLocale());
            $label = is_iterable($labelKey)
                ? ($labelsByLocal[$localizedCatalog->getLocale()] ?? null)
                : $this->translator->trans($labelKey, [], null, $localeCode);

            if ($label) {
                $labels[] = new Label($localizedCatalog, \is_string($label) ? $label : $label->getName());
            }
        }

        return $labels;
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
