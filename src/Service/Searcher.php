<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Service;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Api\GraphQlClient;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class Searcher
{
    private Configuration $configuration;
    private GraphQlClient $client;

    public function __construct(Configuration $configuration, GraphQlClient $client)
    {
        $this->configuration = $configuration;
        $this->client = $client;
    }

    public function search(SalesChannelContext $context, Criteria $criteria)
    {
        $variables = [
            'requestType' => 'product_search',
            'localizedCatalog' => $context->getSalesChannelId() . $context->getLanguageId(),
            'search' => $criteria->getTerm(),
            'sort' => [],
            'currentPage' => $criteria->getOffset() == 0 ? 1 : $criteria->getOffset() / $criteria->getLimit(),
            'pageSize' => $criteria->getLimit(),
        ];

        $response = $this->client->query($this->getSearchQuery(), $variables);
        $response = $response ? json_decode($response->getBody()->getContents(), true) : null;

        if (array_key_exists('errors', $response)) {
            throw new ApiException(reset($response['errors'])['debugMessage']);
        }

        $productNumbers = [];
        foreach ($response['data']['products']['collection'] as $productData) {
            $productNumbers[] = $productData['sku'];
        }

        return [
            'products' => $productNumbers,
            'paginationData' => $response['data']['products']['paginationInfo']
        ];
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
            }
          }
        GQL;
    }
}
