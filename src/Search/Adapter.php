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

use Gally\Rest\ApiException;
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
    public function __construct(
        private GraphQlClient $client,
        private ResultBuilder $resultBuilder
    ) {
    }

    public function search(SalesChannelContext $context, Criteria $criteria): Result
    {
        $sorts = $criteria->getSorting();
        $sort = reset($sorts);
        $navigationsIds = $criteria->getIds();
        $currentPage = 0 == $criteria->getOffset() ? 1 : $criteria->getOffset() / $criteria->getLimit() + 1;

        $data = [
            'requestType' => $criteria->getTerm() ? 'product_search' : 'product_catalog',
            'localizedCatalog' => $context->getSalesChannelId() . $context->getLanguageId(),
            'currentCategoryId' => empty($navigationsIds) ? null : reset($navigationsIds),
            'search' => $criteria->getTerm(),
            'currentPage' => $currentPage,
            'pageSize' => $criteria->getLimit(),
            'filter' => $this->getFiltersFromCriteria($criteria),
        ];

        if (SortOptionProvider::DEFAULT_SEARCH_SORT !== $sort->getField()) {
            $data['sort'] = [$sort->getField() => strtolower($sort->getDirection())];
        }
        $criteria->resetSorting();

        return $this->resultBuilder->build(
            $context,
            $this->client->query($this->getSearchQuery(), $data),
            $currentPage
        );
    }

    public function viewMoreOption(SalesChannelContext $context, Criteria $criteria, string $aggregationField)
    {
        $navigationsIds = $criteria->getIds();
        $response = $this->client->query(
            $this->getViewMoreQuery(),
            [
                'aggregation' => $aggregationField,
                'localizedCatalog' => $context->getSalesChannelId() . $context->getLanguageId(),
                'currentCategoryId' => empty($navigationsIds) ? null : reset($navigationsIds),
                'search' => $criteria->getTerm(),
                'filter' => $this->getFiltersFromCriteria($criteria),
            ]
        );
        $data = json_decode($response->getBody()->getContents(), true);
        if (\array_key_exists('errors', $data)) {
            throw new ApiException(reset($data['errors'])['message']);
        }

        return $data['data']['viewMoreProductFacetOptions'];
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

    private function getViewMoreQuery(): string
    {
        return <<<GQL
            query viewMoreProductFacetOptions (
              \$aggregation: String!,
              \$localizedCatalog: String!,
              \$currentCategoryId: String,
              \$search: String,
              \$filter: [ProductFieldFilterInput]
            ) {
              viewMoreProductFacetOptions (
                aggregation: \$aggregation,
                localizedCatalog: \$localizedCatalog,
                currentCategoryId: \$currentCategoryId,
                search: \$search,
                filter: \$filter
              ) {
                value
                label
                count
            }
          }
        GQL;
    }

    private function getFiltersFromCriteria(Criteria $criteria): array
    {
        $filters = [];
        foreach ($criteria->getPostFilters() as $filter) {
            switch ($filter::class) {
                case EqualsFilter::class:
                    $filters[] = [$filter->getField() => ['eq' => $filter->getValue()]];
                    break;
                case EqualsAnyFilter::class:
                    // On gally side, category filter can't handle multiple values
                    // This is why we use a boolean filter in this case.
                    if ('category__id' === $filter->getField()) {
                        $boolFilterClauses = [];
                        foreach ($filter->getValue() as $value) {
                            $boolFilterClauses[] = [$filter->getField() => ['eq' => $value]];
                        }
                        $filters[] = ['boolFilter' => ['_should' => $boolFilterClauses]];
                    } else {
                        $filters[] = [$filter->getField() => ['in' => $filter->getValue()]];
                    }
                    break;
                case RangeFilter::class:
                    $filters[] = [$filter->getField() => $filter->getParameters()];
                    break;
            }
        }

        return $filters;
    }
}
