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

namespace Gally\ShopwarePlugin\Service;

use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Shared helpers for the subscribers that fetch Gally product recommendations
 * (product page, cart, native cross-selling).
 */
class RecommendationHelper
{
    public function __construct(
        private CatalogProvider $catalogProvider,
        private EntityRepository $languageRepository,
        private EntityRepository $productRepository,
        private SalesChannelRepository $salesChannelProductRepository,
    ) {
    }

    public function getCurrentLocalizedCatalog(SalesChannelContext $context): LocalizedCatalog
    {
        $languageCriteria = new Criteria();
        $languageCriteria->addAssociations(['locale']);
        $languageCriteria->addFilter(new EqualsFilter('id', $context->getLanguageId()));
        /** @var LanguageEntity $currentLanguage */
        $currentLanguage = $this->languageRepository
            ->search($languageCriteria, $context->getContext())
            ->first();

        return $this->catalogProvider->buildLocalizedCatalog($context->getSalesChannel(), $currentLanguage);
    }

    /**
     * @param string[] $skus
     */
    public function getProductsBySkus(array $skus, SalesChannelContext $context): ProductCollection
    {
        $products = new ProductCollection();
        if ([] === $skus) {
            return $products;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $skus));
        $criteria->addAssociation('cover.media');

        $searchResult = $this->salesChannelProductRepository->search($criteria, $context);

        // Preserve the order returned by Gally.
        foreach ($skus as $sku) {
            /** @var ProductEntity $product */
            foreach ($searchResult->getEntities() as $product) {
                if ($product->getProductNumber() === $sku) {
                    $products->add($product);
                    break;
                }
            }
        }

        return $products;
    }

    /**
     * Gally indexes parent products only, so use the parent sku for variants.
     */
    public function getProductSkuById(string $productId, \Shopware\Core\Framework\Context $context): ?string
    {
        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search(new Criteria([$productId]), $context)->get($productId);

        if (null === $product) {
            return null;
        }

        if ($product->getParentId()) {
            /** @var ProductEntity|null $parent */
            $parent = $this->productRepository->search(new Criteria([$product->getParentId()]), $context)->first();

            if ($parent) {
                return $parent->getProductNumber();
            }
        }

        return $product->getProductNumber();
    }
}
