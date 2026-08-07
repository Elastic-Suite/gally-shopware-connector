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

namespace Gally\ShopwarePlugin\Subscriber;

use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\Sdk\Service\RecommenderManager;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Add gally product recommendations on the full cart page and the offcanvas mini-cart.
 */
class CartRecommendationSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'gallyRecommendations';

    public function __construct(
        private ConfigManager $configManager,
        private RecommenderManager $recommenderManager,
        private CatalogProvider $catalogProvider,
        private EntityRepository $languageRepository,
        private EntityRepository $productRepository,
        private SalesChannelRepository $salesChannelProductRepository,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'addRecommendations',
            OffcanvasCartPageLoadedEvent::class => 'addRecommendations',
        ];
    }

    public function addRecommendations(CheckoutCartPageLoadedEvent|OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->configManager->isActive($context->getSalesChannelId())) {
            return;
        }

        $typeCode = $this->configManager->getCartRecommendationTypeCode($context->getSalesChannelId());
        if (null === $typeCode) {
            return;
        }

        try {
            $cartSkus = $this->getCartProductSkus($event->getPage()->getCart(), $context);
            if ([] === $cartSkus) {
                return;
            }

            $localizedCatalog = $this->getCurrentLocalizedCatalog($context);
            $maxSize = $this->configManager->getCartRecommendationMaxSize($context->getSalesChannelId());

            $recommendedSkus = array_column(
                $this->recommenderManager->getProductRecommendations(
                    $typeCode,
                    $localizedCatalog,
                    $cartSkus,
                    $maxSize
                ),
                'sku'
            );

            $products = $this->getProductsBySkus($recommendedSkus, $context);
            $event->getPage()->addExtension(self::EXTENSION_NAME, new ArrayStruct(['products' => $products]));
        } catch (\Throwable $exception) {
            // Never break the cart if Gally is not reachable.
            $this->logger->warning(
                \sprintf('Gally: unable to load cart recommendations: %s', $exception->getMessage())
            );
        }
    }

    /**
     * Gally indexes parent products only. Most recently added line item first, so its
     * recommendations take priority once capped by $maxSize.
     *
     * @return string[]
     */
    private function getCartProductSkus(Cart $cart, SalesChannelContext $context): array
    {
        $productIds = [];
        foreach ($cart->getLineItems() as $lineItem) {
            if (LineItem::PRODUCT_LINE_ITEM_TYPE === $lineItem->getType() && $lineItem->getReferencedId()) {
                $productIds[] = $lineItem->getReferencedId();
            }
        }
        $productIds = array_reverse(array_unique($productIds));

        if ([] === $productIds) {
            return [];
        }

        $criteria = new Criteria($productIds);
        $products = $this->productRepository->search($criteria, $context->getContext())->getEntities();

        $parentIds = [];
        /** @var ProductEntity $product */
        foreach ($products as $product) {
            if ($product->getParentId()) {
                $parentIds[$product->getParentId()] = true;
            }
        }

        $parentNumbersById = [];
        if ([] !== $parentIds) {
            $parents = $this->productRepository
                ->search(new Criteria(array_keys($parentIds)), $context->getContext())
                ->getEntities();
            /** @var ProductEntity $parent */
            foreach ($parents as $parent) {
                $parentNumbersById[$parent->getId()] = $parent->getProductNumber();
            }
        }

        $skus = [];
        foreach ($productIds as $productId) {
            /** @var ProductEntity|null $product */
            $product = $products->get($productId);
            if (null === $product) {
                continue;
            }
            $sku = $product->getParentId()
                ? $parentNumbersById[$product->getParentId()] ?? null
                : $product->getProductNumber();
            if (null !== $sku && !\in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }

    /**
     * @param string[] $skus
     */
    private function getProductsBySkus(array $skus, SalesChannelContext $context): ProductCollection
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

    private function getCurrentLocalizedCatalog(SalesChannelContext $context): LocalizedCatalog
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
}
