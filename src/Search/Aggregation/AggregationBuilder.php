<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search\Aggregation;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\BucketResult;

/**
 * Build aggregation object from gally raw response.
 */
class AggregationBuilder
{
    public function build(array $rawAggregationData): AggregationResultCollection
    {
        $aggregationCollection = new AggregationResultCollection();
        $aggregations = [];

        foreach ($rawAggregationData as $data) {
            if ($data['count']) {
                $buckets = [];
                foreach ($data['options'] as $bucket) {
                    $buckets[] = new AggregationOption($bucket['label'], $bucket['value'], (int) $bucket['count']);
                }

                $aggregations[] = new Bucket(
                    $data['field'],
                    $data['count'],
                    new Aggregation(
                        $data['label'],
                        $data['field'],
                        $data['type'],
                        (bool) $data['hasMore'],
                        $buckets
                    )
                );
            }
        }

        $aggregationCollection->add(new BucketResult('gally-aggregations', $aggregations));

        return $aggregationCollection;
    }
}
