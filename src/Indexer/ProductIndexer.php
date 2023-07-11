<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Service\IndexOperation;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\Pathname\UrlGenerator;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Format and index product entity data to gally.
 */
class ProductIndexer extends AbstractIndexer
{
    private ?EntitySearchResult $categoryCollection = null;

    public function __construct(
        Configuration $configuration,
        EntityRepository $salesChannelRepository,
        IndexOperation $indexOperation,
        EntityRepository $entityRepository,
        UrlGenerator $urlGenerator,
        private EntityRepository $categoryRepository
    )
    {
        parent::__construct($configuration, $salesChannelRepository, $indexOperation, $entityRepository, $urlGenerator);
    }

    public function getEntityType(): string
    {
        return 'product';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable
    {
        $context = $this->getContext($salesChannel, $language);
        $this->loadCategoryCollection($context, $salesChannel->getNavigationCategoryId());

        $batchSize = 1000;
        $criteria = new Criteria();
        if (!empty($documentIdsToReindex)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $documentIdsToReindex));
        }
        $criteria->addFilter(
            new ProductAvailableFilter($salesChannel->getId(), ProductVisibilityDefinition::VISIBILITY_SEARCH)
        );
        $criteria->addAssociations(
            [
                'categories',
                'manufacturer',
                'prices',
                'media',
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

        $products = $this->entityRepository->search($criteria, $context);

        while ($products->count()) {
            $children = $this->getChildren($products, $context);

            /** @var ProductEntity $product */
            foreach ($products as $product) {
                $data = $this->formatProduct($product, $children, $context);

                // Keep the first non-null image
                if (array_key_exists('image', $data)) {
                    $media = array_filter($data['image']);
                    $data['image'] = !empty($media) ? reset($media) : '';
                }

                // Remove option ids in key from data. (We need before them to avoid duplicated property values.)
                array_walk(
                    $data,
                    function (&$item, $key) {
                        $item = (is_array($item) && $key !== 'stock') ? array_values($item) : $item;
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
                new ContainsFilter('path', $rootId)
            ])
        );
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $this->categoryCollection = $this->categoryRepository->search($criteria, $context);
    }

    private function formatProduct(ProductEntity $product, EntitySearchResult $children, Context $context): array
    {
        $data = [
            'id' => "{$product->getAutoIncrement()}",
            'sku' => [$product->getProductNumber()],
            'name' => [$product->getTranslation('name')],
            'image' => [$this->formatMedia($product) ?: null],
            'price' => $this->formatPrice($product),
            'stock' => [
                'status' => $product->getAvailableStock() > 0,
                'qty' => $product->getStock()
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
            if (!array_key_exists($propertyId, $data)) {
                $data[$propertyId] = [];
            }
            $data[$propertyId][$property->getId()] = [
                'value' => $property->getId(),
                'label' => $property->getTranslation('name'),
            ];
        }

        foreach ($product->getCustomFields() ?: [] as $code => $value) {
            $data[$code] = $value;
        }

        if ($product->getChildCount()) {
            foreach ($product->getChildren()->getIds() as $childId) {
                /** @var ProductEntity $child */
                $child = $children->get($childId);
                $childData = $this->formatProduct($child, $children, $context);
                $childData['children.sku'] = $childData['sku'];
                unset($childData['id']);
                unset($childData['sku']);
                unset($childData['stock']);
                unset($childData['price']);
                unset($childData['free_shipping']);
                unset($childData['rating_avg']);
                foreach ($childData as $field => $value) {
                    $data[$field] = array_merge($data[$field] ?? [], $value);
                }
            }
        }

        // Remove empty values
        return array_filter(
            $data,
            fn ($item, $key) => in_array($key, ['stock']) || !is_array($item) || !empty(array_filter($item)),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function formatPrice(ProductEntity $product): array
    {
        $prices = [];
        /** @var Price $price */
        foreach ($product->getPrice() ?? [] as $price) {
            $originalPrice = $price->getListPrice() ? $price->getListPrice()->getGross() : $price->getGross();
            $prices[] = [
                'price' =>  $price->getGross(),
                'original_price' => $originalPrice,
                'group_id' => 0,
                'is_discounted' => $price->getGross() < $originalPrice
            ];

        }
        return $prices;
    }

    private function formatMedia(ProductEntity $product): string
    {
        if ($product->getMedia() && $product->getMedia()->count()) {
            $media = $product->getMedia()->getMedia()->first();
            /** @var MediaThumbnailEntity $thumbnail */
            foreach ($media->getThumbnails() as $thumbnail) {
                if (400 == $thumbnail->getWidth()){
                    return $this->urlGenerator->getRelativeThumbnailUrl($media, $thumbnail);
                }
            }
        }

        return '';
    }

    private function formatCategories(ProductEntity $product): array
    {
        $categories = [];
        /** @var array<string, string> $categoryIds */
        $categoryIds = $product->getCategories() ? $product->getCategories()->getIds() : [];
        /** @var CategoryEntity $productCategory */
        foreach ($product->getCategories() ?? [] as $productCategory) {
            $categoryPath = $productCategory->getPath() ?: '';
            foreach (array_merge([$productCategory->getId()], explode('|', $categoryPath)) as $categoryId) {
                /** @var CategoryEntity|null $category */
                $category = $this->categoryCollection->get($categoryId);
                if ($category && $category->getActive()) {
                    $categories[$category->getId()] = [
                        'id' => $category->getId(),
                        'category_uid' => $category->getId(),
                        'name' =>  $category->getName(),
                        'is_parent' => !array_key_exists($category->getId(), $categoryIds)
                    ];
                }
            }
        }
        return array_values($categories);
    }

    private function formatManufacturer(ProductEntity $product): array
    {
        $manufacturer = $product->getManufacturer();
        return $manufacturer
            ? [
                $manufacturer->getId() => [
                    'value' => $manufacturer->getId(),
                    'label' => $manufacturer->getName(),
                ]
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
                'media',
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
