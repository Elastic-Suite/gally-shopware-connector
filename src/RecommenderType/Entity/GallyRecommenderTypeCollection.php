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

namespace Gally\ShopwarePlugin\RecommenderType\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<GallyRecommenderTypeEntity>
 */
class GallyRecommenderTypeCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return GallyRecommenderTypeEntity::class;
    }
}
