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

namespace Gally\ShopwarePlugin\Indexer\Provider;

use Gally\Sdk\Entity\RecommenderType;
use Shopware\Core\Framework\Context;

/**
 * Provide the recommender types managed by the plugin.
 */
class RecommenderTypeProvider implements ProviderInterface
{
    /**
     * @return iterable<RecommenderType>
     */
    public function provide(Context $context): iterable
    {
        yield new RecommenderType('Upsell', 'upsell');
        yield new RecommenderType('Related', 'related');
        yield new RecommenderType('Crosssell', 'crosssell');
    }
}
