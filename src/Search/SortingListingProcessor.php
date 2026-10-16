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

use Gally\ShopwarePlugin\Config\ConfigManager;
use Shopware\Core\Content\Product\SalesChannel\Listing\Processor\AbstractListingProcessor;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class SortingListingProcessor extends AbstractListingProcessor
{
    public function __construct(
        private readonly AbstractListingProcessor $decorated,
        private readonly ConfigManager $configManager,
    ) {
    }

    public function getDecorated(): AbstractListingProcessor
    {
        return $this->decorated;
    }

    public function prepare(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$this->configManager->isActive($context->getSalesChannelId())) {
            $this->decorated->prepare($request, $criteria, $context);
        }

        // For gally sort criteria is managed in
        // @see \Gally\ShopwarePlugin\Search\CriteriaBuilder::handleSorting
    }

    public function process(Request $request, ProductListingResult $result, SalesChannelContext $context): void
    {
        $this->decorated->process($request, $result, $context);
    }
}
