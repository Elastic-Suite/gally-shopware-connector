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

use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Service\RecommendationHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Add gally product recommendations on the full cart page and the offcanvas mini-cart.
 */
class CartRecommendationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ConfigManager $configManager,
        private RecommendationHelper $recommendationHelper,
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

        $typeCodes = $this->configManager->getCartRecommendationTypeCodes($context->getSalesChannelId());
        if ([] === $typeCodes) {
            return;
        }

        try {
            $cartSkus = $this->getCartProductSkus($event->getPage()->getCart(), $context);
            if ([] === $cartSkus) {
                return;
            }

            $localizedCatalog = $this->recommendationHelper->getCurrentLocalizedCatalog($context);
            $maxSize = $this->configManager->getCartRecommendationMaxSize($context->getSalesChannelId());

            $blocks = $this->recommendationHelper->buildBlocks($typeCodes, $cartSkus, $maxSize, $localizedCatalog, $context);

            $event->getPage()->addExtension(RecommendationHelper::EXTENSION_NAME, new ArrayStruct(['blocks' => $blocks]));
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

        $skus = [];
        foreach ($productIds as $productId) {
            $sku = $this->recommendationHelper->getProductSkuById($productId, $context->getContext());
            if (null !== $sku && !\in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }
}
