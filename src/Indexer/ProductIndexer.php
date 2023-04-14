<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ProductIndexer extends AbstractIndexer
{
    public function getEntityType(): string
    {
        return 'product';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable
    {
        $context = $this->getContext($salesChannel, $language);

        $batchSize = 1000;
        $criteria = new Criteria();
        if (!empty($documentIdsToReindex)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $documentIdsToReindex));
        }
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
                /** @var ProductVisibilityEntity $visibility */
                foreach ($product->getVisibilities() as $visibility) {
                    // Todo manage visibility with filter
                    if (
                        $visibility->getSalesChannelId() == $salesChannel->getId()
                        && $visibility->getVisibility() == ProductVisibilityDefinition::VISIBILITY_ALL
                    ) {
                        yield $this->formatProduct($product);
                    }
                }
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
                'status' => $product->getStock() > 0, // Todo manage stock status
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
        /** @var CategoryEntity $category */
        foreach ($product->getCategories() as $category) {
            $categories[] = [
                'id' => $category->getId(),
                'category_uid' => $category->getId(),
                'name' =>  $category->getName(),
            ];
        }
        return $categories;
    }
}
