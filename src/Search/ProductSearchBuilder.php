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

namespace Gally\ShopwarePlugin\Search;

use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decorate the native product search builder to prevent shopware to run the search in mysql.
 */
class ProductSearchBuilder implements ProductSearchBuilderInterface
{
    public function __construct(
        private ProductSearchBuilderInterface $decorated,
        private Configuration $configuration
    ) {
    }

    public function build(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$this->configuration->isActive($context->getSalesChannelId())) {
            $this->decorated->build($request, $criteria, $context);
        }

        // For gally search criteria building is managed in
        // @see \Gally\ShopwarePlugin\Search\ProductListingFeaturesSubscriber::handleListingRequest
    }
}
