<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\Api\CategorySortingOptionApi;
use Gally\Rest\Model\CategorySortingOption;
use Gally\ShopwarePlugin\Api\RestClient;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Get available sorting option from gally.
 */
class SortOptionProvider
{
    public const DEFAULT_SEARCH_SORT = '_score';

    protected RestClient $client;

    public function __construct(RestClient $client)
    {
        $this->client = $client;
    }

    public function getSortingOptions(): ProductSortingCollection
    {
        $sortingOptions = $this->client->query(CategorySortingOptionApi::class, 'getCategorySortingOptionCollection');
        $sortings = new ProductSortingCollection();

        /** @var CategorySortingOption $option */
        foreach ($sortingOptions as $option) {
            foreach ([FieldSorting::ASCENDING, FieldSorting::DESCENDING] as $direction) {
                if ($option->getCode() === self::DEFAULT_SEARCH_SORT) {
                    if ($direction === FieldSorting::ASCENDING) {
                        continue;
                    }
                    $label = $option->getLabel();
                    $code = $option->getCode();
                } else {
                    $label = $option->getLabel() . ' ' . strtolower($direction) . 'ending';
                    $code = $option->getCode() . '-' . strtolower($direction);
                }
                $sortingEntity = new ProductSortingEntity();
                $sortingEntity->setId($code);
                $sortingEntity->setKey($code);
                $sortingEntity->setLabel($label);
                $sortingEntity->addTranslated('label', $label);
                $sortingEntity->setFields([
                    [
                        'field' => $option->getCode(),
                        'order' => $direction,
                        'priority' => 1,
                    ]
                ]);
                $sortings->add($sortingEntity);
            }
        }

        return $sortings;
    }
}

