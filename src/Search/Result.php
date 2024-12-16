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

use Gally\Sdk\GraphQl\Request;
use Gally\Sdk\GraphQl\Response;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Gally result.
 */
class Result
{
    private array $productNumbers = [];

    public function __construct(
        private Request $request,
        private Response $response,
        private AggregationResultCollection $aggregations,
    ) {
        foreach ($this->response->getCollection() as $productRawData) {
            $this->productNumbers[$productRawData['sku']] = $productRawData['source']['children.sku'] ?? [];
        }
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
        $newCriteria->setLimit($this->response->getItemsPerPage());
        $newCriteria->setOffset(($this->request->getCurrentPage() - 1) * $this->response->getItemsPerPage());

        /** @var ProductListingResult $newListing */
        $newListing = ProductListingResult::createFrom(new EntitySearchResult(
            $listing->getEntity(),
            $this->response->getTotalCount(),
            $listing->getEntities(),
            $this->aggregations,
            $newCriteria,
            $listing->getContext()
        ));

        foreach ($listing->getCurrentFilters() as $name => $filter) {
            $newListing->addCurrentFilter($name, $filter);
        }

        $sortKey = SortOptionProvider::SCORE_SEARCH_SORT === $this->response->getSortField()
            ? $this->response->getSortField()
            : $this->response->getSortField() . '-' . $this->response->getSortDirection();

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

        foreach (array_keys($this->getProductNumbers()) as $order => $sku) {
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
