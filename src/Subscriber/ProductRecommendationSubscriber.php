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
use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeEntity;
use Gally\ShopwarePlugin\Service\RecommendationHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Add gally product recommendations on the product detail page.
 */
class ProductRecommendationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ConfigManager $configManager,
        private RecommendationHelper $recommendationHelper,
        private EntityRepository $productRepository,
        private EntityRepository $productCrossSellingRepository,
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
            $referenceProduct = $this->getReferenceProduct($event);
            $localizedCatalog = $this->recommendationHelper->getCurrentLocalizedCatalog($context);
            $productSku = $referenceProduct->getProductNumber();
            $maxSize = $this->configManager->getProductRecommendationMaxSize($context->getSalesChannelId());

            // A type already covered by a native, Gally-enabled cross-selling group on this
            // product (see CrossSellingSubscriber) is rendered there instead, merged with its
            // manually assigned products: showing it again here too would duplicate that block.
            $nativeCodes = $this->getNativeCrossSellingCodes($referenceProduct->getId(), $context->getContext());
            $typeCodes = array_values(array_diff($typeCodes, $nativeCodes));

            $blocks = $this->recommendationHelper->buildBlocks($typeCodes, [$productSku], $maxSize, $localizedCatalog, $context);

            $event->getPage()->addExtension(RecommendationHelper::EXTENSION_NAME, new ArrayStruct(['blocks' => $blocks]));
        } catch (\Throwable $exception) {
            // Never break the product page if Gally is not reachable.
            $this->logger->warning(
                \sprintf('Gally: unable to load product recommendations: %s', $exception->getMessage())
            );
        }
    }

    /**
     * Gally indexes parent products only, and cross-selling groups are always defined on the
     * parent too, so use it for variants.
     */
    private function getReferenceProduct(ProductPageLoadedEvent $event): ProductEntity
    {
        $product = $event->getPage()->getProduct();

        if ($product->getParentId()) {
            /** @var ProductEntity|null $parent */
            $parent = $this->productRepository
                ->search(new Criteria([$product->getParentId()]), $event->getContext())
                ->first();

            if ($parent) {
                return $parent;
            }
        }

        return $product;
    }

    /**
     * @return string[]
     */
    private function getNativeCrossSellingCodes(string $productId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $productId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('gallyRecommenderType');

        $codes = [];
        /** @var ProductCrossSellingEntity $crossSelling */
        foreach ($this->productCrossSellingRepository->search($criteria, $context)->getEntities() as $crossSelling) {
            /** @var GallyRecommenderTypeEntity|null $recommenderType */
            $recommenderType = $crossSelling->getExtension('gallyRecommenderType');
            if (null !== $recommenderType) {
                $codes[] = $recommenderType->getCode();
            }
        }

        return $codes;
    }
}
