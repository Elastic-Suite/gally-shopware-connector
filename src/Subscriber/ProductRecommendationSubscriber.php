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

use Gally\Sdk\Service\RecommenderManager;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Service\RecommendationHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Add gally product recommendations on the product detail page.
 */
class ProductRecommendationSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'gallyRecommendations';

    public function __construct(
        private ConfigManager $configManager,
        private RecommenderManager $recommenderManager,
        private RecommendationHelper $recommendationHelper,
        private EntityRepository $productRepository,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'addRecommendations',
        ];
    }

    public function addRecommendations(ProductPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->configManager->isActive($context->getSalesChannelId())) {
            return;
        }

        $typeCodes = $this->configManager->getProductRecommendationTypeCodes($context->getSalesChannelId());
        if ([] === $typeCodes) {
            return;
        }

        try {
            $localizedCatalog = $this->recommendationHelper->getCurrentLocalizedCatalog($context);
            $productSku = $this->getProductSku($event);
            $maxSize = $this->configManager->getProductRecommendationMaxSize($context->getSalesChannelId());

            $blocks = [];
            foreach ($typeCodes as $typeCode) {
                $recommendedSkus = array_column(
                    $this->recommenderManager->getProductRecommendations(
                        $typeCode,
                        $localizedCatalog,
                        [$productSku],
                        $maxSize
                    ),
                    'sku'
                );
                $blocks[] = ['code' => $typeCode, 'products' => $this->recommendationHelper->getProductsBySkus($recommendedSkus, $context)];
            }

            $event->getPage()->addExtension(self::EXTENSION_NAME, new ArrayStruct(['blocks' => $blocks]));
        } catch (\Throwable $exception) {
            // Never break the product page if Gally is not reachable.
            $this->logger->warning(
                \sprintf('Gally: unable to load product recommendations: %s', $exception->getMessage())
            );
        }
    }

    /**
     * Gally indexes parent products only, so use the parent sku for variants.
     */
    private function getProductSku(ProductPageLoadedEvent $event): string
    {
        $product = $event->getPage()->getProduct();

        if ($product->getParentId()) {
            /** @var ProductEntity|null $parent */
            $parent = $this->productRepository
                ->search(new Criteria([$product->getParentId()]), $event->getContext())
                ->first();

            if ($parent) {
                return $parent->getProductNumber();
            }
        }

        return $product->getProductNumber();
    }
}
