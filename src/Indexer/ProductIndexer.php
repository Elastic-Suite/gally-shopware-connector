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

namespace Gally\ShopwarePlugin\Indexer;

use Gally\Sdk\Service\IndexOperation;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Event\IndexerBeforeProductLoadEvent;
use Gally\ShopwarePlugin\Indexer\Event\IndexerFormatProductEvent;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Checkout\Customer\Rule\CustomerGroupRule;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\Core\Application\AbstractMediaUrlGenerator;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\ProductStream\Service\AbstractProductStreamBuilder;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Rule\Aggregate\RuleCondition\RuleConditionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Format and index product entity data to gally.
 */
class ProductIndexer extends AbstractIndexer
{
    private ?EntitySearchResult $categoryCollection = null;

    /** @var array<string, array<string, CategoryEntity>> */
    private array $categoriesByStreamId = [];

    /** @var array<string, Filter[]> filters of the dynamic product groups used by the categories, keyed by stream id */
    private array $streamFilters = [];

    /** @var array<string, string[]> dynamic product groups matched by the products of the current batch, keyed by product id */
    private array $streamIdsByProductId = [];

    /** @var array<string, array<string, string>> option labels by option value, keyed by custom field name */
    private array $selectCustomFieldLabels = [];

    /** @var array<string, true> names of json custom fields */
    private array $jsonCustomFields = [];

    public function __construct(
        protected ConfigManager $configManager,
        EntityRepository $salesChannelRepository,
        IndexOperation $indexOperation,
        CatalogProvider $catalogProvider,
        EntityRepository $entityRepository,
        AbstractMediaUrlGenerator $urlGenerator,
        EventDispatcherInterface $eventDispatcher,
        private EntityRepository $categoryRepository,
        private EntityRepository $ruleConditionRepository,
        private EntityRepository $customerGroupRepository,
        private EntityRepository $customFieldRepository,
        private ProductStreamBuilderInterface|AbstractProductStreamBuilder $productStreamBuilder,
    ) {
        parent::__construct(
            $configManager,
            $salesChannelRepository,
            $indexOperation,
            $catalogProvider,
            $entityRepository,
            $urlGenerator,
            $eventDispatcher,
        );
    }

    public function getEntityType(): string
    {
        return 'product';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable
    {
        $context = $this->getContext($salesChannel, $language);
        $this->loadCategoryCollection($context, $salesChannel->getNavigationCategoryId());
        $this->loadStreamFilters($context);
        $this->loadCustomFields($context, $language);
        $simpleCustomerGroupRules = $this->getSimpleCustomerGroupRules($context);
        $displayGrossByGroupId = [];
        /** @var CustomerGroupEntity $customerGroup */
        foreach ($this->customerGroupRepository->search(new Criteria(), $context)->getEntities() as $customerGroup) {
            $displayGrossByGroupId[$customerGroup->getId()] = $customerGroup->getDisplayGross();
        }

        $batchSize = 1000;
        $criteria = new Criteria();
        if (!empty($documentIdsToReindex)) {
            // Only parent products are indexed: a saved variant reindexes its parent.
            $criteria->addFilter(new OrFilter([
                new EqualsAnyFilter('id', $documentIdsToReindex),
                new EqualsAnyFilter('children.id', $documentIdsToReindex),
            ]));
        }
        $criteria->addFilter(
            new ProductAvailableFilter($salesChannel->getId(), ProductVisibilityDefinition::VISIBILITY_SEARCH)
        );
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addAssociations(
            [
                'categories',
                'manufacturer',
                'prices.rule.conditions',
                'media',
                'media.media',
                'media.media.thumbnails',
                'cover',
                'cover.media',
                'cover.media.thumbnails',
                'customFields',
                'properties',
                'properties.group',
                'visibilities',
                'children',
            ]
        );
        $criteria->addSorting(new FieldSorting('autoIncrement', FieldSorting::ASCENDING));
        $criteria->setOffset(0);
        $criteria->setLimit($batchSize);

        $event = new IndexerBeforeProductLoadEvent($criteria);
        $this->eventDispatcher->dispatch($event, IndexerBeforeProductLoadEvent::NAME);

        $products = $this->entityRepository->search($criteria, $context);

        while ($products->count()) {
            $children = $this->getChildren($products, $context);
            $this->loadStreamIdsByProductId(array_values(array_merge($products->getIds(), $children->getIds())), $context);

            /** @var ProductEntity $product */
            foreach ($products as $product) {
                $data = $this->formatProduct($product, $children, $context, $simpleCustomerGroupRules, $displayGrossByGroupId);

                // Remove key from category array
                if (\array_key_exists('category', $data)) {
                    $data['category'] = array_values($data['category']);
                }

                // Keep the first non-null image
                if (\array_key_exists('image', $data)) {
                    $media = array_filter($data['image']);
                    $data['image'] = !empty($media) ? reset($media) : '';
                }

                // Remove option ids in key from data. (We need before them to avoid duplicated property values.)
                array_walk(
                    $data,
                    function (&$item, $key) {
                        $item = (\is_array($item) && 'stock' !== $key) ? array_values($item) : $item;
                    }
                );
                yield $data;
            }
            $criteria->setOffset($criteria->getOffset() + $batchSize);
            $products = $this->entityRepository->search($criteria, $context);
        }
    }

    private function loadCategoryCollection(Context $context, string $rootId): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter('id', $rootId),
                new ContainsFilter('path', $rootId),
            ])
        );
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $this->categoryCollection = $this->categoryRepository->search($criteria, $context);

        // Categories filled by a dynamic product group, keyed by stream id.
        $this->categoriesByStreamId = [];
        /** @var CategoryEntity $category */
        foreach ($this->categoryCollection as $category) {
            if (CategoryDefinition::PRODUCT_ASSIGNMENT_TYPE_PRODUCT_STREAM === $category->getProductAssignmentType()
                && null !== $category->getProductStreamId()
            ) {
                $this->categoriesByStreamId[$category->getProductStreamId()][$category->getId()] = $category;
            }
        }
    }

    /**
     * Build once per catalog the filters of the dynamic product groups used by the categories, the same way the
     * native category listing does (see ProductListingRoute).
     */
    private function loadStreamFilters(Context $context): void
    {
        $this->streamFilters = [];
        foreach (array_keys($this->categoriesByStreamId) as $streamId) {
            try {
                $criteria = new Criteria();
                if ($this->productStreamBuilder instanceof AbstractProductStreamBuilder) {
                    $this->productStreamBuilder->enrichCriteria($criteria, $streamId, $context);
                } else {
                    $criteria->addFilter(...$this->productStreamBuilder->buildFilters($streamId, $context));
                }
                $this->streamFilters[$streamId] = $criteria->getFilters();
            } catch (\Throwable) {
                // Invalid or empty dynamic product group: its categories get no product.
            }
        }
    }

    /**
     * Evaluate the dynamic product groups on a product batch (parents and variants), with inheritance like the
     * storefront, in the language of the indexed catalog.
     *
     * @param string[] $productIds
     */
    private function loadStreamIdsByProductId(array $productIds, Context $context): void
    {
        $this->streamIdsByProductId = [];
        foreach ($this->streamFilters as $streamId => $filters) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('id', $productIds), ...$filters);

            try {
                $matchedIds = $context->enableInheritance(
                    fn (Context $context): array => $this->entityRepository->searchIds($criteria, $context)->getIds()
                );
            } catch (\Throwable) {
                // Filter on a field that no longer exists (deleted custom field...): the group matches no product.
                continue;
            }

            foreach ($matchedIds as $productId) {
                $this->streamIdsByProductId[$productId][] = $streamId;
            }
        }
    }

    /**
     * Product custom fields that need a specific format:
     *  - indexed as gally "select" (see SourceFieldProvider::getGallyType()), with the option labels in the indexed
     *    language. Entity custom fields have no option list, their value is used as label.
     *  - json, indexed as gally "text": objects can't be sent to a text field, they are sent json encoded.
     */
    private function loadCustomFields(Context $context, LanguageEntity $language): void
    {
        $localeCode = $language->getLocale()?->getCode();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSet.relations.entityName', 'product'));
        $criteria->addFilter(new EqualsAnyFilter('type', ['select', 'entity', 'json']));

        $this->selectCustomFieldLabels = [];
        $this->jsonCustomFields = [];
        /** @var CustomFieldEntity $customField */
        foreach ($this->customFieldRepository->search($criteria, $context)->getEntities() as $customField) {
            if ('json' === $customField->getType()) {
                $this->jsonCustomFields[$customField->getName()] = true;
                continue;
            }

            $labels = [];
            foreach ($customField->getConfig()['options'] ?? [] as $option) {
                $optionLabels = $option['label'] ?? [];
                $labels[(string) $option['value']] = $optionLabels[$localeCode] ?? reset($optionLabels) ?: (string) $option['value'];
            }
            $this->selectCustomFieldLabels[$customField->getName()] = $labels;
        }
    }

    /**
     * @param array<string, string[]> $simpleCustomerGroupRules
     * @param array<string, bool>     $displayGrossByGroupId
     */
    private function formatProduct(ProductEntity $product, EntitySearchResult $children, Context $context, array $simpleCustomerGroupRules, array $displayGrossByGroupId): array
    {
        $data = [
            'id' => "{$product->getAutoIncrement()}",
            'sku' => [$product->getProductNumber()],
            'name' => [$product->getTranslation('name')],
            'description' => [$product->getTranslation('description')],
            'image' => [$this->formatMedia($product) ?: null],
            'price' => $this->formatPrice($product, $simpleCustomerGroupRules, $displayGrossByGroupId),
            'stock' => [
                'status' => $product->getAvailableStock() > 0,
                'qty' => $product->getStock(),
            ],
            'category' => $this->formatCategories($product),
            'manufacturer' => $this->formatManufacturer($product),
            'free_shipping' => $product->getShippingFree(),
            'rating_avg' => $product->getRatingAverage(),
        ];

        $properties = array_merge(
            $product->getProperties() ? iterator_to_array($product->getProperties()) : [],
            $product->getOptions() ? iterator_to_array($product->getOptions()) : [],
        );

        /** @var PropertyGroupOptionEntity $property */
        foreach ($properties as $property) {
            $propertyId = 'property_' . $property->getGroupId();
            if (!\array_key_exists($propertyId, $data)) {
                $data[$propertyId] = [];
            }
            $data[$propertyId][$property->getId()] = [
                'value' => $property->getId(),
                'label' => $property->getTranslation('name'),
            ];
        }

        foreach ($product->getCustomFields() ?: [] as $code => $value) {
            if (null === $value) {
                continue;
            }

            // Custom fields synced as gally "select" source fields expect {value, label} options.
            if (\array_key_exists($code, $this->selectCustomFieldLabels)) {
                $data[$code] = [];
                foreach ((array) $value as $optionValue) {
                    $optionValue = (string) $optionValue;
                    $data[$code][$optionValue] = [
                        'value' => $optionValue,
                        'label' => $this->selectCustomFieldLabels[$code][$optionValue] ?? $optionValue,
                    ];
                }
            } elseif (\array_key_exists($code, $this->jsonCustomFields)) {
                $data[$code] = [\is_string($value) ? $value : json_encode($value)];
            } else {
                $data[$code] = [$value];
            }
        }

        if ($product->getChildCount()) {
            foreach ($product->getChildren()->getIds() as $childId) {
                /** @var ProductEntity $child */
                $child = $children->get($childId);
                $childData = $this->formatProduct($child, $children, $context, $simpleCustomerGroupRules, $displayGrossByGroupId);
                $childData['children.sku'] = $childData['sku'];
                if (\array_key_exists('name', $childData)) {
                    $childData['children.name'] = $childData['name'];
                }
                if (\array_key_exists('description', $childData)) {
                    $childData['children.description'] = $childData['description'];
                }
                unset($childData['id']);
                unset($childData['sku']);
                unset($childData['name']);
                unset($childData['description']);
                unset($childData['stock']);
                unset($childData['price']);
                unset($childData['free_shipping']);
                unset($childData['rating_avg']);
                foreach ($childData as $field => $value) {
                    $data[$field] = array_merge($data[$field] ?? [], $value);
                }
            }
        }

        $event = new IndexerFormatProductEvent($data, $product, $children, $context);
        $this->eventDispatcher->dispatch($event, IndexerFormatProductEvent::NAME);
        $data = $event->getData();

        // Remove empty values
        return array_filter(
            $data,
            fn ($item, $key) => \in_array($key, ['stock'], true) || !\is_array($item) || !empty(array_filter($item)),
            \ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param array<string, string[]> $simpleCustomerGroupRules customer group price rules (see
     *                                                          getSimpleCustomerGroupRules()), keyed by rule id
     * @param array<string, bool>     $displayGrossByGroupId    whether each customer group id shows net or
     *                                                          gross prices (CustomerGroupEntity::getDisplayGross())
     */
    private function formatPrice(ProductEntity $product, array $simpleCustomerGroupRules, array $displayGrossByGroupId): array
    {
        $prices = [];
        /** @var Price $price */
        foreach ($product->getPrice() ?? [] as $price) {
            $originalPrice = $price->getListPrice() ? $price->getListPrice()->getGross() : $price->getGross();
            $prices[] = [
                'price' => $price->getGross(),
                'original_price' => $originalPrice,
                'group_id' => '0',
                'is_discounted' => $price->getGross() < $originalPrice,
            ];
        }
        $basePrice = $prices[0] ?? null;

        // Advanced pricing rows whose rule is a plain "customer group" condition (see
        // getSimpleCustomerGroupRules()): one extra price entry per targeted group, net or gross
        // depending on that group's own display setting.
        $groupPrices = [];
        /** @var ProductPriceEntity $productPrice */
        foreach ($product->getPrices() ?? [] as $productPrice) {
            if (1 !== $productPrice->getQuantityStart()) {
                // Quantity-tiered advanced pricing has no equivalent in Gally's per-document price
                // groups (that's a cart-time computation); only the base tier is indexed here.
                continue;
            }

            $customerGroupIds = $simpleCustomerGroupRules[$productPrice->getRuleId()] ?? null;
            if (null === $customerGroupIds) {
                continue;
            }

            /** @var Price|null $price */
            $price = $productPrice->getPrice()->first();
            if (null === $price) {
                continue;
            }

            foreach ($customerGroupIds as $customerGroupId) {
                $displayGross = $displayGrossByGroupId[$customerGroupId] ?? true;
                $amount = $displayGross ? $price->getGross() : $price->getNet();
                $listPrice = $price->getListPrice();
                $originalAmount = $listPrice ? ($displayGross ? $listPrice->getGross() : $listPrice->getNet()) : $amount;
                $groupPrices[$customerGroupId] = [
                    'price' => $amount,
                    'original_price' => $originalAmount,
                    'group_id' => $customerGroupId,
                    'is_discounted' => $amount < $originalAmount,
                ];
            }
        }

        // Gally returns no price at all when a search's price-group-id has no matching entry in
        // a document (no fallback to the default price), so every customer group needs its own
        // entry here, falling back to the regular price (in its own net/gross display mode) where
        // this product has no group-specific rule for it.
        if (null !== $basePrice) {
            /** @var Price|null $basePriceValue */
            $basePriceValue = $product->getPrice()?->first();
            foreach ($displayGrossByGroupId as $customerGroupId => $displayGross) {
                if (isset($groupPrices[$customerGroupId]) || null === $basePriceValue) {
                    continue;
                }

                $amount = $displayGross ? $basePriceValue->getGross() : $basePriceValue->getNet();
                $listPrice = $basePriceValue->getListPrice();
                $originalAmount = $listPrice ? ($displayGross ? $listPrice->getGross() : $listPrice->getNet()) : $amount;
                $groupPrices[$customerGroupId] = [
                    'price' => $amount,
                    'original_price' => $originalAmount,
                    'group_id' => $customerGroupId,
                    'is_discounted' => $amount < $originalAmount,
                ];
            }
        }

        return array_merge($prices, array_values($groupPrices));
    }

    /**
     * Advanced pricing rules ("product_price", tied to a generic Rule) whose only real condition
     * is a plain "customer belongs to group X" check: the only shape that maps unambiguously to a
     * Gally price group. Rules combining other conditions (cart quantity, country...) are left
     * out, the product keeps its regular price for those.
     *
     * The rule builder always wraps conditions in a default orContainer > andContainer, even for
     * a single condition, so "only real condition" is counted excluding those two container
     * types, not by looking for a top-level (parentId null) condition.
     *
     * @return array<string, string[]> customer group ids, keyed by rule id
     */
    private function getSimpleCustomerGroupRules(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('type', CustomerGroupRule::RULE_NAME));
        /** @var RuleConditionEntity[] $groupConditions */
        $groupConditions = iterator_to_array($this->ruleConditionRepository->search($criteria, $context)->getEntities());
        if ([] === $groupConditions) {
            return [];
        }

        $ruleIds = array_unique(array_map(static fn (RuleConditionEntity $condition): string => $condition->getRuleId(), $groupConditions));
        $allConditionsCriteria = new Criteria();
        $allConditionsCriteria->addFilter(new EqualsAnyFilter('ruleId', $ruleIds));
        $leafConditionCountByRule = [];
        /** @var RuleConditionEntity $condition */
        foreach ($this->ruleConditionRepository->search($allConditionsCriteria, $context)->getEntities() as $condition) {
            if (\in_array($condition->getType(), ['andContainer', 'orContainer'], true)) {
                continue;
            }
            $leafConditionCountByRule[$condition->getRuleId()] = ($leafConditionCountByRule[$condition->getRuleId()] ?? 0) + 1;
        }

        $simpleRules = [];
        foreach ($groupConditions as $condition) {
            if (1 === ($leafConditionCountByRule[$condition->getRuleId()] ?? 0)) {
                $simpleRules[$condition->getRuleId()] = $condition->getValue()['customerGroupIds'] ?? [];
            }
        }

        return $simpleRules;
    }

    private function formatMedia(ProductEntity $product): string
    {
        $cover = $product->getCover();
        if ($cover && $cover->getMedia()) {
            $media = $cover->getMedia();
            /** @var MediaThumbnailEntity $thumbnail */
            foreach ($media->getThumbnails() ?? [] as $thumbnail) {
                if (400 == $thumbnail->getWidth() || 400 == $thumbnail->getHeight()) {
                    return $thumbnail->getPath();
                }
            }
        }

        return '';
    }

    private function formatCategories(ProductEntity $product): array
    {
        $categories = [];
        /** @var array<string, CategoryEntity> $productCategories */
        $productCategories = $product->getCategories() ? $product->getCategories()->getElements() : [];

        // Like Shopware, ignore manual assignments to a category filled by a dynamic product group.
        $productCategories = array_filter(
            $productCategories,
            fn (CategoryEntity $category) => CategoryDefinition::PRODUCT_ASSIGNMENT_TYPE_PRODUCT_STREAM !== $category->getProductAssignmentType()
        );

        // Categories assigned through a dynamic product group.
        foreach ($this->streamIdsByProductId[$product->getId()] ?? [] as $streamId) {
            $productCategories += $this->categoriesByStreamId[$streamId] ?? [];
        }

        foreach ($productCategories as $productCategory) {
            $categoryPath = $productCategory->getPath() ?: '';
            foreach (array_merge([$productCategory->getId()], explode('|', $categoryPath)) as $categoryId) {
                /** @var CategoryEntity|null $category */
                $category = $this->categoryCollection->get($categoryId);
                if ($category && $category->getActive()) {
                    $categories[$category->getId()] = [
                        'id' => $category->getId(),
                        'category_uid' => $category->getId(),
                        'name' => $category->getName(),
                        'is_parent' => !\array_key_exists($category->getId(), $productCategories),
                    ];
                }
            }
        }

        return $categories;
    }

    private function formatManufacturer(ProductEntity $product): array
    {
        $manufacturer = $product->getManufacturer();

        return $manufacturer
            ? [
                $manufacturer->getId() => [
                    'value' => $manufacturer->getId(),
                    'label' => $manufacturer->getName(),
                ],
            ]
            : [];
    }

    private function getChildren(EntitySearchResult $products, Context $context): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('parentId', $products->getIds()));
        $criteria->addAssociations(
            [
                'categories',
                'prices',
                'media.media',
                'media.media.thumbnails',
                'cover',
                'cover.media',
                'cover.media.thumbnails',
                'customFields',
                'properties',
                'properties.group',
                'visibilities',
                'options',
            ]
        );
        $criteria->addSorting(new FieldSorting('autoIncrement', FieldSorting::ASCENDING));

        return $this->entityRepository->search($criteria, $context);
    }
}
