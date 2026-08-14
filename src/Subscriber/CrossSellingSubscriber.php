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
use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeEntity;
use Gally\ShopwarePlugin\Service\RecommendationHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\Events\ProductCrossSellingCriteriaLoadEvent;
use Shopware\Core\Content\Product\Events\ProductCrossSellingsLoadedEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElement;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Complete native cross-selling groups with Gally recommendations: manually
 * assigned products are kept first, Gally fills the remainder up to the group's limit.
 */
class CrossSellingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ConfigManager $configManager,
        private RecommenderManager $recommenderManager,
        private RecommendationHelper $recommendationHelper,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductCrossSellingCriteriaLoadEvent::class => 'onCriteriaLoad',
            ProductCrossSellingsLoadedEvent::class => 'onCrossSellingsLoaded',
        ];
    }

    public function onCriteriaLoad(ProductCrossSellingCriteriaLoadEvent $event): void
    {
        $event->getCriteria()->addAssociation('gallyRecommenderType');
    }

    public function onCrossSellingsLoaded(ProductCrossSellingsLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->configManager->isActive($context->getSalesChannelId())) {
            return;
        }

        foreach ($event->getCrossSellings() as $element) {
            $crossSelling = $element->getCrossSelling();
            /** @var GallyRecommenderTypeEntity|null $recommenderType */
            $recommenderType = $crossSelling->getExtension('gallyRecommenderType');

            if (null === $recommenderType) {
                continue;
            }

            try {
                $this->completeWithRecommendations($element, $crossSelling, $recommenderType, $context);
            } catch (\Throwable $exception) {
                // Never break the cross-selling tab if Gally is not reachable.
                $this->logger->warning(
                    \sprintf('Gally: unable to load cross-selling recommendations: %s', $exception->getMessage())
                );
            }
        }
    }

    private function completeWithRecommendations(
        CrossSellingElement $element,
        ProductCrossSellingEntity $crossSelling,
        GallyRecommenderTypeEntity $recommenderType,
        SalesChannelContext $context,
    ): void {
        $limit = $crossSelling->getLimit();
        $products = $element->getProducts();

        if ($products->count() >= $limit) {
            return;
        }

        $productSku = $this->recommendationHelper->getProductSkuById($crossSelling->getProductId(), $context->getContext());
        if (null === $productSku) {
            return;
        }

        $excludedSkus = array_filter(array_map(
            static fn (ProductEntity $product): ?string => $product->getProductNumber(),
            iterator_to_array($products)
        ));

        $missing = $limit - $products->count();
        $recommendedSkus = array_column(
            $this->recommenderManager->getProductRecommendations(
                $recommenderType->getCode(),
                $this->recommendationHelper->getCurrentLocalizedCatalog($context),
                [$productSku],
                $missing + \count($excludedSkus)
            ),
            'sku'
        );
        $recommendedSkus = \array_slice(array_diff($recommendedSkus, $excludedSkus), 0, $missing);

        $recommendedProducts = $this->recommendationHelper->getProductsBySkus($recommendedSkus, $context);
        foreach ($recommendedProducts as $product) {
            $products->add($product);
        }

        $element->setProducts($products);
        $element->setTotal($products->count());
    }
}
