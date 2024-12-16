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

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;

/**
 * Gally aggregation option.
 */
class AggregationOption extends Bucket
{
    public function __construct(
        private string $label,
        string $value,
        int $count,
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
