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

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Pure FK target for product_cross_selling.gallyRecommenderType: maps a Shopware row id to the
 * code of a recommender type configured directly in Gally. Rows are created on demand (see
 * AdminController::resolveRecommenderType()) when an admin picks a code in the cross-selling
 * form, never managed by hand: Gally is the source of truth for the code's existence/label.
 */
class GallyRecommenderTypeEntity extends Entity
{
    use EntityIdTrait;

    protected string $code;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }
}
