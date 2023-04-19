<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\Rest\Api\CategorySortingOptionApi;
use Gally\ShopwarePlugin\Api\RestClient;

class SortOptionProvider
{
    protected RestClient $client;

    public function __construct(
        RestClient $client
    ) {
        $this->client = $client;
    }

    public function getSortingOptions(): array
    {
        // Todo manage error
        return $this->client->query(CategorySortingOptionApi::class, 'getCategorySortingOptionCollection');
    }
}

