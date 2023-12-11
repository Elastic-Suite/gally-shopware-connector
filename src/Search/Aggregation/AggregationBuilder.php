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

namespace Gally\ShopwarePlugin\Search\Aggregation;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Build aggregation object from gally raw response.
 */
class AggregationBuilder
{
    public const MANUFACTURER_AGGREGATION = 'manufacturer__value';
    public const PRICE_AGGREGATION = 'price__price';
    public const FREE_SHIPPING_AGGREGATION = 'free_shipping';
    public const STOCK_STATUS_AGGREGATION = 'stock__status';
    public const RATING_AGGREGATION = 'rating_avg';

    private const SHOW_MORE_OPTION = 'gally-show-more';

    public function __construct(
        protected EntityRepository $manufacturerRepository,
        protected EntityRepository $propertyGroupRepository
    ) {
    }

    public function build(array $rawAggregationData, SalesChannelContext $context): AggregationResultCollection
    {
        $aggregationCollection = new AggregationResultCollection();

        foreach ($rawAggregationData as $data) {
            if ($data['count']) {
                $buckets = [];

                if (self::MANUFACTURER_AGGREGATION === $data['field']) {
                    $criteria = new Criteria();
                    $criteria->addFilter(new EqualsAnyFilter('id', array_column($data['options'], 'value')));
                    $manufacturers = $this->manufacturerRepository->search($criteria, $context->getContext())->getEntities();
                    if ($data['hasMore']) {
                        $showMore = new ProductManufacturerEntity();
                        $showMore->setId(self::SHOW_MORE_OPTION);
                        $manufacturers->add($showMore);
                    }
                    $aggregationCollection->add(new EntityResult($data['field'], $manufacturers));
                } elseif (self::PRICE_AGGREGATION === $data['field']) {
                    $minPrice = reset($data['options'])['value'];
                    $maxPrice = end($data['options'])['value'];
                    $aggregationCollection->add(new StatsResult($data['field'], $minPrice, $maxPrice, null, null));
                } elseif (self::FREE_SHIPPING_AGGREGATION === $data['field']) {
                    $aggregationCollection->add(new MaxResult($data['field'], max(array_column($data['options'], 'value'))));
                } elseif (self::STOCK_STATUS_AGGREGATION === $data['field']) {
                    $aggregationCollection->add(new MaxResult($data['field'], max(array_column($data['options'], 'value'))));
                } elseif (self::RATING_AGGREGATION === $data['field']) {
                    $aggregationCollection->add(new MaxResult($data['field'], max(array_column($data['options'], 'value'))));
                } elseif (str_starts_with($data['field'], 'property')) {
                    $propertyId = str_replace('property_', '', str_replace('__value', '', $data['field']));
                    $criteria = new Criteria();
                    $criteria->addAssociations(['options', 'translations', 'options.translations', 'options.media']);
                    $criteria->addFilter(new EqualsFilter('id', $propertyId));
                    $properties = $this->propertyGroupRepository->search($criteria, $context->getContext());
                    /** @var PropertyGroupEntity $property */
                    $property = $properties->first();

                    $optionIds = array_column($data['options'], 'value');
                    $options = new PropertyGroupOptionCollection();
                    foreach ($optionIds as $optionId) {
                        $options->add($property->getOptions()->get($optionId));
                    }

                    if ($data['hasMore']) {
                        $showMore = new PropertyGroupOptionEntity();
                        $showMore->setId(self::SHOW_MORE_OPTION);
                        $options->add($showMore);
                    }
                    $property->setOptions($options);

                    $aggregationCollection->add(new EntityResult($data['field'], $properties));
                } else {
                    foreach ($data['options'] as $bucket) {
                        $buckets[] = new AggregationOption($bucket['label'], $bucket['value'], (int) $bucket['count']);
                    }

                    if ($data['hasMore']) {
                        $buckets[] = new AggregationOption(self::SHOW_MORE_OPTION, self::SHOW_MORE_OPTION, 0);
                    }

                    $aggregationCollection->add(new Aggregation($data['label'], $data['field'], $data['type'], $buckets));
                }
            }
        }

        return $aggregationCollection;
    }
}
