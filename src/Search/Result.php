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

namespace Gally\ShopwarePlugin\Search;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Gally result.
 */
class Result
{
    public function __construct(
        private array $productNumbers,
        private int $totalResultCount,
        private int $currentPage,
        private int $itemPerPage,
        private string $sortField,
        private string $sortDirection,
        private AggregationResultCollection $aggregations
    ) {
    }

    /**
     * Get product numbers from gally response.
     *
     * @return string[]
     */
    public function getProductNumbers(): array
    {
        return $this->productNumbers;
    }

    public function getResultListing(ProductListingResult $listing): ProductListingResult
    {
        $newCriteria = clone $listing->getCriteria();
        $newCriteria->setLimit($this->itemPerPage);
        $newCriteria->setOffset(($this->currentPage - 1) * $this->itemPerPage);

        /** @var ProductListingResult $newListing */
        $newListing = ProductListingResult::createFrom(new EntitySearchResult(
            $listing->getEntity(),
            $this->totalResultCount,
            $listing->getEntities(),
            $this->aggregations,
            $newCriteria,
            $listing->getContext()
        ));

        foreach ($listing->getCurrentFilters() as $name => $filter) {
            $newListing->addCurrentFilter($name, $filter);
        }

        $sortKey = SortOptionProvider::SCORE_SEARCH_SORT === $this->sortField
            ? $this->sortField
            : $this->sortField . '-' . $this->sortDirection;

        $newListing->setExtensions($listing->getExtensions());
        $newListing->setSorting($sortKey);
        $sortings = $listing->getAvailableSortings();
        $sortings->remove(SortOptionProvider::DEFAULT_SEARCH_SORT);
        $newListing->setAvailableSortings($sortings);

        $this->sortListing($newListing);

        return $newListing;
    }

    private function sortListing(ProductListingResult $listing): void
    {
        $gallyOrder = [];

        foreach (array_keys($this->productNumbers) as $order => $sku) {
            $gallyOrder[$sku] = $order;
            foreach ($this->productNumbers[$sku] as $childSku) {
                $gallyOrder[$childSku] = $order;
            }
        }

        $listing->sort(
            function (ProductEntity $productA, ProductEntity $productB) use ($gallyOrder) {
                return $gallyOrder[$productA->getProductNumber()] >= $gallyOrder[$productB->getProductNumber()];
            }
        );
    }
}
