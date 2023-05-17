<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search\Aggregation;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;

/**
 * Gally aggregation option
 */
class AggregationOption extends Bucket
{
    private string $label;

    public function __construct(
        string $label,
        string $value,
        int $count
    ) {
        parent::__construct($value, $count, null);
        $this->label = $label;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}