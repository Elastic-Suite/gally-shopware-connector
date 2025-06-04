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

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Allows to add/update criteria before manufacturer collection load, during the indexation.
 */
class IndexerBeforeManufacturerLoadEvent extends AbstractIndexerBeforeLoadEntityEvent
{
    public const NAME = 'gally.manufacturer_indexer.before_manufacturer_load';
}
