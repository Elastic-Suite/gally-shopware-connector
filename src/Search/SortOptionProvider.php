<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\Api\ProductSortingOptionApi;
use Gally\Rest\Model\ProductSortingOption;
use Gally\ShopwarePlugin\Api\RestClient;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Get available sorting option from gally.
 */
class SortOptionProvider
{
    public const DEFAULT_SEARCH_SORT = '_default';
    public const SCORE_SEARCH_SORT = '_score';

    public function __construct(protected RestClient $client)
    {
    }

    public function getSortingOptions(): ProductSortingCollection
    {
        $sortingOptions = $this->client->query(ProductSortingOptionApi::class, 'getProductSortingOptionCollection');
        $sortings = new ProductSortingCollection();

        /** @var ProductSortingOption $option */
        foreach ($sortingOptions as $option) {
            foreach ([FieldSorting::ASCENDING, FieldSorting::DESCENDING] as $direction) {
                if ($option->getCode() === self::SCORE_SEARCH_SORT) {
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

        $sortingEntity = new ProductSortingEntity();
        $sortingEntity->setId(self::DEFAULT_SEARCH_SORT);
        $sortingEntity->setKey(self::DEFAULT_SEARCH_SORT);
        $sortingEntity->setLabel(self::DEFAULT_SEARCH_SORT);
        $sortingEntity->addTranslated('label', self::DEFAULT_SEARCH_SORT);
        $sortingEntity->setLocked(true);
        $sortingEntity->setFields([
            [
                'field' => self::DEFAULT_SEARCH_SORT,
                'order' => FieldSorting::ASCENDING,
                'priority' => 1,
            ]
        ]);
        $sortings->add($sortingEntity);

        return $sortings;
    }
}

