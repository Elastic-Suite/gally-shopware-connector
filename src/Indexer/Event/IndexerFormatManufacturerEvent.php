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

namespace Gally\ShopwarePlugin\Indexer\Event;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Allows to add/update manufacturer data before indexation.
 */
class IndexerFormatManufacturerEvent extends AbstractIndexerFormatEntityEvent
{
    public const NAME = 'gally.manufacturer_indexer.format_manufacturer';

    public function __construct(
        array $data,
        private ProductManufacturerEntity $manufacturer,
    ) {
        parent::__construct($data);
    }

    public function getManufacturer(): ProductManufacturerEntity
    {
        return $this->manufacturer;
    }
}
