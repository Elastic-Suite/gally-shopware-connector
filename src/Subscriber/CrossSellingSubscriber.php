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
use Shopware\Core\Content\Product\ProductCollection;
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

        $nameByCode = null;

        foreach ($event->getCrossSellings() as $element) {
            $crossSelling = $element->getCrossSelling();
            /** @var GallyRecommenderTypeEntity|null $recommenderType */
            $recommenderType = $crossSelling->getExtension('gallyRecommenderType');

            if (null === $recommenderType) {
                continue;
            }

            // Gally is the source of truth for this group's display name while it's
            // Gally-enabled: override it here (never persisted back to Shopware) instead of
            // overwriting the stored one in the admin, so a pre-existing group's original name
            // reappears untouched if the merchant turns Gally back off later.
            if (null === $nameByCode) {
                try {
                    $nameByCode = $this->recommendationHelper->getRecommenderTypeNamesByCode();
                } catch (\Throwable $exception) {
                    $nameByCode = [];
                    $this->logger->warning(
                        \sprintf('Gally: unable to load recommender type names: %s', $exception->getMessage())
                    );
                }
            }
            if (isset($nameByCode[$recommenderType->getCode()])) {
                $crossSelling->setName($nameByCode[$recommenderType->getCode()]);
            }

            try {
                $this->completeWithRecommendations($element, $crossSelling, $recommenderType->getCode(), $context);
            } catch (\Throwable $exception) {
                // This group is Gally-enabled: showing it anyway with only its manually assigned
                // products (or nothing) would look like it's working fine to a customer, while
                // the merchant sees no indication anything needs fixing. Hide the whole group
                // instead, and let the admin's warning border (needs-attention state) be the one
                // and only place this surfaces.
                $element->setProducts(new ProductCollection());
                $element->setTotal(0);

                $this->logger->warning(
                    \sprintf('Gally: unable to load cross-selling recommendations: %s', $exception->getMessage())
                );
            }
        }
    }

    private function completeWithRecommendations(
        CrossSellingElement $element,
        ProductCrossSellingEntity $crossSelling,
        string $recommenderTypeCode,
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
            static fn (ProductEntity $product): string => $product->getProductNumber(),
            iterator_to_array($products)
        ));

        $missing = $limit - $products->count();
        $recommendedSkus = array_column(
            $this->recommenderManager->getProductRecommendations(
                $recommenderTypeCode,
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
