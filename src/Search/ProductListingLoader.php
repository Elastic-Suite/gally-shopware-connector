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

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductCloseoutFilterFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Remove non gally filters.
 */
class ProductListingLoader extends \Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader
{
    public function __construct(
        private readonly \Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader $decorated,
        SalesChannelRepository $repository,
        SystemConfigService $systemConfigService,
        Connection $connection,
        EventDispatcherInterface $eventDispatcher,
        AbstractProductCloseoutFilterFactory $productCloseoutFilterFactory
    ) {
        parent::__construct($repository, $systemConfigService, $connection, $eventDispatcher, $productCloseoutFilterFactory);
    }

    public function load(Criteria $origin, SalesChannelContext $context): EntitySearchResult
    {
        $filters = $origin->getFilters();
        if (\array_key_exists('gally_filter', $filters)) {
            $origin->resetFilters();
            $origin->setFilter('gally_filter', $filters['gally_filter']);
        }

        return $this->decorated->load($origin, $context);
    }
}
