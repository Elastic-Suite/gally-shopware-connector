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
use Shopware\Core\Content\Product\SalesChannel\Listing\Processor\SortingListingProcessor as BaseSortingListingProcessor;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class SortingListingProcessor extends BaseSortingListingProcessor
{
    private ConfigManager $configManager;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $sortingRepository,
        ConfigManager $configManager,
    ) {
        parent::__construct($systemConfigService, $sortingRepository);
        $this->configManager = $configManager;
    }

    public function prepare(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$this->configManager->isActive($context->getSalesChannelId())) {
            parent::prepare($request, $criteria, $context);
        }

        // For gally sort criteria is managed in
        // @see \Gally\ShopwarePlugin\Search\CriteriaBuilder::handleSorting
    }
}
