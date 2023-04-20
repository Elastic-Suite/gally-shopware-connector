<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\ShopwarePlugin\Api\GraphQlClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class Adapter
{
    private Configuration $configuration;
    private GraphQlClient $client;
    private ResultBuilder $resultBuilder;

    private array $sortMapping = [
        '_score' => '_score',
        'product.cheapestPrice' => 'price__price',
        'product.name' => 'name',
        'product.sales' => '_score', // Todo : generate available sorting list from gally api
    ];

    public function __construct(Configuration $configuration, GraphQlClient $client, ResultBuilder $resultBuilder)
    {
        $this->configuration = $configuration;
        $this->client = $client;
        $this->resultBuilder = $resultBuilder;
    }

    public function search(SalesChannelContext $context, Criteria $criteria): Result
    {
        $sorts = $criteria->getSorting();
        $sort = reset($sorts);
        $navigationsIds = $criteria->getIds();

        return $this->resultBuilder->build(
            $this->client->query(
                $this->getSearchQuery(),
                [
                    'requestType' => $criteria->getTerm() ? 'product_search' : 'product_catalog',
                    'localizedCatalog' => $context->getSalesChannelId() . $context->getLanguageId(),
                    'currentCategoryId' => empty($navigationsIds) ? null : reset($navigationsIds),
                    'search' => $criteria->getTerm(),
                    'sort' => [ $sort->getField() => strtolower($sort->getDirection())],
                    'currentPage' => $criteria->getOffset() == 0 ? 1 : $criteria->getOffset() / $criteria->getLimit() + 1,
                    'pageSize' => $criteria->getLimit(),
                ]
            )
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
              \$sort: ProductSortInput
            ) {
              products (
                requestType: \$requestType,
                localizedCatalog: \$localizedCatalog,
                currentPage: \$currentPage,
                currentCategoryId: \$currentCategoryId,
                pageSize: \$pageSize,
                search: \$search,
                sort: \$sort,
                filter: []
              ) {
                collection { ... on Product { sku } }
                paginationInfo { lastPage itemsPerPage totalCount }
                sortInfo { current { field direction } }
                aggregations {
                  field
                  count
                  hasMore
                  options { count label value }
                }
            }
          }
        GQL;
    }
}
