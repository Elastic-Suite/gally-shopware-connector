<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Api\GraphQlClient;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class Result
{
    private array $productNumbers;
    private int $totalResultCount;
    private string $sortField;
    private string $sortDirection;
    private AggregationResultCollection $aggregations;

    public function __construct(
        array $productNumbers,
        int $totalResultCount,
        string $sortField,
        string $sortDirection,
        AggregationResultCollection $aggregations
    ) {
        $this->productNumbers = $productNumbers;
        $this->totalResultCount = $totalResultCount;
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

    /**
     * Get total number of results.
     */
    public function getTotalResultCount(): int
    {
        return $this->totalResultCount;
    }

    /**
     * Get sort field.
     */
    public function getSortField(): string
    {
        return $this->sortField;
    }

    /**
     * Get sort direction.
     */
    public function getSortDirection(): string
    {
        return $this->sortDirection;
    }

    /**
     * Get aggregations.
     */
    public function getAggregations(): AggregationResultCollection
    {
        return $this->aggregations;
    }
}
