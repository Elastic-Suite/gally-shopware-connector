<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search\Aggregation;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;

/**
 * Gally aggregation option
 */
class AggregationOption extends Bucket
{
    public function __construct(
        private string $label,
        string $value,
        int $count
    ) {
        parent::__construct($value, $count, null);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTranslated(): array
    {
        return ['name' => $this->getLabel()];
    }

    public function getId(): string
    {
        return $this->getKey();
    }
}
