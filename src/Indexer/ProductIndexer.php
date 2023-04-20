<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Service\IndexOperation;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
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

class ProductIndexer extends AbstractIndexer
{
    private EntityRepository $categoryRepository;
    private ?EntitySearchResult $categoryCollection = null;

    public function __construct(
        Configuration $configuration,
        EntityRepository $salesChannelRepository,
        IndexOperation $indexOperation,
        EntityRepository $entityRepository,
        EntityRepository $categoryRepository
    )
    {
        parent::__construct($configuration, $salesChannelRepository, $indexOperation, $entityRepository);
        $this->categoryRepository = $categoryRepository;
    }

    public function getEntityType(): string
    {
        return 'product';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable
    {
        $context = $this->getContext($salesChannel, $language);

        $criteria = new Criteria();
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter('id', $salesChannel->getNavigationCategoryId()),
                new ContainsFilter('path', $salesChannel->getNavigationCategoryId())
            ])
        );
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $this->categoryCollection = $this->categoryRepository->search($criteria, $context);

        $batchSize = 1000;
        $criteria = new Criteria();
        if (!empty($documentIdsToReindex)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $documentIdsToReindex));
        }
        $criteria->addFilter(new ProductAvailableFilter($salesChannel->getId()));
        $criteria->addAssociations(
            [
                'categories',
                'prices',
                'media',
                'customFields',
                'properties',
                'properties.group',
                'visibilities'
            ]
        );
        $criteria->addSorting(new FieldSorting('autoIncrement', FieldSorting::ASCENDING));
        $criteria->setOffset(0);
        $criteria->setLimit($batchSize);

        $products = $this->entityRepository->search($criteria, $context);

        while ($products->count()) {
            /** @var ProductEntity $product */
            foreach ($products as $product) {
                yield $this->formatProduct($product);
            }
            $criteria->setOffset($criteria->getOffset() + $batchSize);
            $products = $this->entityRepository->search($criteria, $context);
        }
    }

    private function formatProduct(ProductEntity $product): array
    {
        $mediaPath = '';

        if ($product->getMedia()) {
            /** @var MediaThumbnailEntity $thumbnail */
            foreach ($product->getMedia()->getMedia()->first()->getThumbnails() as $thumbnail) {
                if (400 == $thumbnail->getWidth()){
                    $mediaPath = $thumbnail->getUrl();
                }
            }
        }

        $data = [
            'id' => $product->getAutoIncrement(),
            'sku' => $product->getProductNumber(),
            'name' => $product->getName(),
            'image' => str_replace('http://localhost', '', $mediaPath), // Todo how to add base url in context
            'price' => $this->formatPrice($product),
            'stock' => [
                'status' => $product->getAvailableStock() > 0, // Todo manage stock status
                'qty' => $product->getStock()
            ],
            'category' => $this->formatCategories($product),
        ];

        /** @var PropertyGroupOptionEntity $property */
        foreach ($product->getProperties() as $property) {
            $propertyId = 'property_' . $property->getGroupId();
            if (!array_key_exists($propertyId, $data)) {
                $data[$propertyId] = [];
            }
            $data[$propertyId][] = [
                'label' => $property->getName(),
                'value' => $property->getId(),
            ];
        }

        foreach ($product->getCustomFields() ?: [] as $code => $value) {
            $data[$code] = $value;
        }

        return $data;
    }

    private function formatPrice(ProductEntity $product): array
    {
        $prices = [];
        /** @var Price $price */
        foreach ($product->getPrice() as $price) {
            $prices[] = [
                'price' =>  $price->getGross(),
                'original_price' => $price->getGross(), // Todo manage promo
                'group_id' => 0,
                'is_discounted' => false
            ];
        }
        return $prices;
    }

    private function formatCategories(ProductEntity $product): array
    {
        $categories = [];
        $categoryIds = $product->getCategories() ? $product->getCategories()->getIds() : [];
        /** @var CategoryEntity $productCategory */
        foreach ($product->getCategories() ?? [] as $productCategory) {
            foreach (array_merge([$productCategory->getId()], explode('|', $productCategory->getPath())) as $categoryId) {
                /** @var CategoryEntity $category */
                $category = $this->categoryCollection->get($categoryId);
                if ($category) {
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
}
