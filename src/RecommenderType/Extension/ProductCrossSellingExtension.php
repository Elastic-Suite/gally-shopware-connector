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
 * Adds a "gally_recommender_type" FK to native cross-selling groups so one can be driven by
 * Gally instead of a manual product list. EntityExtension only allows association/FK fields
 * (a plain StringField/BoolField is rejected at schema-build time), hence the indirection
 * through a minimal local GallyRecommenderType row (id + code, created on demand, see
 * AdminController::resolveRecommenderType()): "Gally-enabled" just means gallyRecommenderTypeId
 * is not null.
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

    /**
     * @deprecated will be removed once the Shopware version floor no longer needs it, replaced by getEntityName()
     */
    public function getDefinitionClass(): string
    {
        return ProductCrossSellingDefinition::class;
    }

    public function getEntityName(): string
    {
        return ProductCrossSellingDefinition::ENTITY_NAME;
    }
}
