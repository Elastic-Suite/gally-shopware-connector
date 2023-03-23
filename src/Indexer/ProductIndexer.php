<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ProductIndexer extends AbstractIndexer
{
    public function getEntityType(): string
    {
        return 'product';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['categories', 'prices', 'customFields', 'properties', 'properties.group', 'visibilities']);
        $criteria->addSorting(new FieldSorting('autoIncrement', FieldSorting::ASCENDING));
        // Todo add pagination
        $products = $this->entityRepository->search($criteria, $this->getContext($salesChannel, $language));
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
    }

    private function formatProduct(ProductEntity $product): array
    {
        $data = [
            'id' => $product->getAutoIncrement(),
            'sku' => $product->getProductNumber(),
            'name' => $product->getName(),
//            'image' => $product->getMedia()->first()
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
