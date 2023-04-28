<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\ShopwarePlugin\Api\GraphQlClient;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Gally search adapter.
 */
class Adapter
{
    private GraphQlClient $client;
    private ResultBuilder $resultBuilder;

    public function __construct(GraphQlClient $client, ResultBuilder $resultBuilder)
    {
        $this->client = $client;
        $this->resultBuilder = $resultBuilder;
    }

    public function search(SalesChannelContext $context, Criteria $criteria): Result
    {
        $sorts = $criteria->getSorting();
        $sort = reset($sorts);
        $navigationsIds = $criteria->getIds();
        $filters = [];
        foreach ($criteria->getPostFilters() as $filter) {
            switch (get_class($filter)) {
                case EqualsFilter::class:
                    $filters[] = [$filter->getField() => ['eq' => $filter->getValue()]];
                    break;
                case EqualsAnyFilter::class:
                    $filters[] = [$filter->getField() => ['in' => $filter->getValue()]];
                    break;
                case RangeFilter::class:
                    $filters[] = [$filter->getField() => $filter->getParameters()];
                    break;
            }
        }

        $currentPage = $criteria->getOffset() == 0 ? 1 : $criteria->getOffset() / $criteria->getLimit() + 1;

        return $this->resultBuilder->build(
            $context,
            $this->client->query(
                $this->getSearchQuery(),
                [
                    'requestType' => $criteria->getTerm() ? 'product_search' : 'product_catalog',
                    'localizedCatalog' => $context->getSalesChannelId() . $context->getLanguageId(),
                    'currentCategoryId' => empty($navigationsIds) ? null : reset($navigationsIds),
                    'search' => $criteria->getTerm(),
                    'sort' => [$sort->getField() => strtolower($sort->getDirection())],
                    'currentPage' => $currentPage,
                    'pageSize' => $criteria->getLimit(),
                    'filter' => $filters
                ]
            ),
            $currentPage
        );
    }

    private function getSearchQuery(): string
    {
        return <<<GQL
            query getProducts (
              \$requestType: ProductRequestTypeEnum!,
              \$localizedCatalog: String!,
              \$currentPage: Int,
              \$currentCategoryId: String,
              \$pageSize: Int,
              \$search: String,
              \$sort: ProductSortInput,
              \$filter: [ProductFieldFilterInput]
            ) {
              products (
                requestType: \$requestType,
                localizedCatalog: \$localizedCatalog,
                currentPage: \$currentPage,
                currentCategoryId: \$currentCategoryId,
                pageSize: \$pageSize,
                search: \$search,
                sort: \$sort,
                filter: \$filter
              ) {
                collection { ... on Product { sku source } }
                paginationInfo { lastPage itemsPerPage totalCount }
                sortInfo { current { field direction } }
                aggregations {
                  type
                  field
                  label
                  count
                  hasMore
                  options { count label value }
                }
            }
          }
        GQL;
    }
}
