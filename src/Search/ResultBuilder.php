<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Search\Aggregation\AggregationBuilder;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Build result object from gally raw response.
 */
class ResultBuilder
{
    private AggregationBuilder $aggregationBuilder;

    public function __construct(AggregationBuilder $aggregationBuilder)
    {
        $this->aggregationBuilder = $aggregationBuilder;
    }

    public function build(SalesChannelContext $context, ?ResponseInterface $response, int $currentPage): Result
    {
        $response = $response ? json_decode($response->getBody()->getContents(), true) : null;

        $this->validate($response);
        $response = $response['data']['products'];

        $productNumbers = [];
        foreach ($response['collection'] as $productRawData) {
            $productNumbers[$productRawData['sku']] = $productRawData['source']['children.sku'] ?? [];
        }

        return new Result(
            $productNumbers,
            (int) $response['paginationInfo']['totalCount'],
            $currentPage,
            (int) $response['paginationInfo']['itemsPerPage'],
            $response['sortInfo']['current'][0]['field'],
            $response['sortInfo']['current'][0]['direction'],
            $this->aggregationBuilder->build($response['aggregations'] ?? [])
        );
    }

    private function validate(array $response)
    {
        if (array_key_exists('errors', $response)) {
            $firstError = reset($response['errors']);
            throw new ApiException($firstError['debugMessage'] ?? $firstError['message']);
        }

        if (!array_key_exists('data', $response) || !array_key_exists('products', $response['data'])) {
            throw new ApiException('Empty gally response.');
        }

        $data = $response['data']['products'];

        if (
            !array_key_exists('collection', $data)
            || !array_key_exists('paginationInfo', $data)
            || !array_key_exists('sortInfo', $data)
            || !array_key_exists('aggregations', $data)
        ) {
            throw new ApiException('Malformed gally response.');
        }
    }
}
