<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Api\GraphQlClient;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Gally result
 */
class Result
{
    private array $productNumbers;
    private int $totalResultCount;
    private int $currentPage;
    private int $itemPerPage;
    private string $sortField;
    private string $sortDirection;
    private AggregationResultCollection $aggregations;

    public function __construct(
        array $productNumbers,
        int $totalResultCount,
        int $currentPage,
        int $itemPerPage,
        string $sortField,
        string $sortDirection,
        AggregationResultCollection $aggregations
    ) {
        $this->productNumbers = $productNumbers;
        $this->totalResultCount = $totalResultCount;
        $this->currentPage = $currentPage;
        $this->itemPerPage = $itemPerPage;
        $this->sortField = $sortField;
        $this->sortDirection = $sortDirection;
        $this->aggregations = $aggregations;
    }

    /**
     * Get product numbers from gally response
     *
     * @return string[]
     */
    public function getProductNumbers(): array
    {
        return $this->productNumbers;
    }

    public function getResultListing(ProductListingResult $listing): ProductListingResult
    {
        /** @var ProductListingResult $newListing */
        $newListing = ProductListingResult::createFrom(new EntitySearchResult(
            $listing->getEntity(),
            $this->totalResultCount,
            $listing->getEntities(),
            $this->aggregations,
            $listing->getCriteria(),
            $listing->getContext()
        ));

        foreach ($listing->getCurrentFilters() as $name => $filter) {
            $newListing->addCurrentFilter($name, $filter);
        }

        $sortKey = $this->sortField === SortOptionProvider::SCORE_SEARCH_SORT
            ? $this->sortField
            : $this->sortField . '-' . $this->sortDirection;

        $newListing->getCriteria()->setLimit($this->itemPerPage);
        $newListing->getCriteria()->setOffset(($this->currentPage - 1) * $this->itemPerPage);
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
