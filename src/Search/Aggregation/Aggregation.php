<?php

namespace Gally\ShopwarePlugin\Search\Aggregation;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\BucketResult;

class Aggregation extends BucketResult
{
    private string $field;
    private string $type;
    private bool $hasMore;

    /**
     * @param AggregationOption[] $options
     */
    public function __construct(
        string $label,
        string $field,
        string $type,
        bool $hasMore,
        array $options
    ) {
        parent::__construct($label, $options);
        $this->field = $field;
        $this->type = $type;
        $this->hasMore = $hasMore;
    }

    public function getLabel(): string
    {
        return $this->getName();
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    public function getOptions(): array
    {
        return $this->getBuckets();
    }
}
