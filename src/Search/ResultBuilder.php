<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\ApiException;
use Psr\Http\Message\ResponseInterface;

class ResultBuilder
{
    private AggregationBuilder $aggregationBuilder;

    public function __construct(
        AggregationBuilder $aggregationBuilder
    ) {
        $this->aggregationBuilder = $aggregationBuilder;
    }

    public function build(?ResponseInterface $response): Result
    {
        $response = $response ? json_decode($response->getBody()->getContents(), true) : null;

        $this->validate($response);
        $response = $response['data']['products'];

        return new Result(
            array_column($response['collection'], 'sku'),
            (int) $response['paginationInfo']['totalCount'],
            $response['sortInfo']['current'][0]['field'],
            $response['sortInfo']['current'][0]['direction'],
            $this->aggregationBuilder->build($response['aggregations'])
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
