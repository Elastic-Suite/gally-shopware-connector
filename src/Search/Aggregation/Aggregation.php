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

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\BucketResult;

/**
 * Gally aggregation.
 */
class Aggregation extends BucketResult
{
    /**
     * @param AggregationOption[] $options
     */
    public function __construct(
        string $label,
        private string $field,
        private string $type,
        array $options,
    ) {
        parent::__construct($label, $options);
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

    public function getOptions(): array
    {
        return $this->getBuckets();
    }
}
