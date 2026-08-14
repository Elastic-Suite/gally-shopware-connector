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

namespace Gally\ShopwarePlugin\RecommenderType\Extension;

use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Adds a "gally_recommender_type" relation to the native product cross-selling groups, so a
 * group can be driven by Gally instead of a manual product list. A group is considered
 * "Gally-enabled" when gallyRecommenderTypeId is not null: there is no separate boolean flag,
 * Shopware's EntityExtension mechanism only allows association/FK/runtime fields to be added
 * to an existing entity, a plain BoolField is rejected at schema-build time.
 */
class ProductCrossSellingExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new FkField('gally_recommender_type_id', 'gallyRecommenderTypeId', GallyRecommenderTypeDefinition::class))
                ->addFlags(new ApiAware())
        );
        $collection->add(
            (new ManyToOneAssociationField('gallyRecommenderType', 'gally_recommender_type_id', GallyRecommenderTypeDefinition::class, 'id'))
                ->addFlags(new ApiAware())
        );
    }

    public function getEntityName(): string
    {
        return ProductCrossSellingDefinition::ENTITY_NAME;
    }
}
