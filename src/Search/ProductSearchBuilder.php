<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class ProductSearchBuilder implements ProductSearchBuilderInterface
{
    private ProductSearchBuilderInterface $decorated;
    private Configuration $configuration;

    public function __construct(
        ProductSearchBuilderInterface $decorated,
        Configuration $configuration
    ) {
        $this->decorated = $decorated;
        $this->configuration = $configuration;
    }

    public function build(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$this->configuration->isActive($context->getSalesChannelId())) {
            $this->decorated->build($request, $criteria, $context);
        }

        // Criteria building is managed in
        // \Gally\ShopwarePlugin\Search\ProductListingFeaturesSubscriber::handleListingRequest
    }
}
