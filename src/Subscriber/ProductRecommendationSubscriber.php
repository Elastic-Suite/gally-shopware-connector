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
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Add gally product recommendations on the product detail page.
 */
class ProductRecommendationSubscriber implements EventSubscriberInterface
{
    public const RECOMMENDATION_TYPES = ['related', 'upsell'];
    public const EXTENSION_NAME = 'gallyRecommendations';
    public const PRODUCT_COUNT = 4;

    public function __construct(
        private ConfigManager $configManager,
        private RecommenderManager $recommenderManager,
        private CatalogProvider $catalogProvider,
        private EntityRepository $languageRepository,
        private SalesChannelRepository $salesChannelProductRepository,
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

        try {
            $localizedCatalog = $this->getCurrentLocalizedCatalog($context);
            $productSku = $this->getProductSku($event);

            $recommendations = [];
            foreach (self::RECOMMENDATION_TYPES as $type) {
                $recommendedSkus = array_column(
                    $this->recommenderManager->getProductRecommendations(
                        $type,
                        $localizedCatalog,
                        [$productSku],
                        self::PRODUCT_COUNT
                    ),
                    'sku'
                );
                $recommendations[$type] = $this->getProductsBySkus($recommendedSkus, $context);
            }

            $event->getPage()->addExtension(self::EXTENSION_NAME, new ArrayStruct($recommendations));
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
            $parent = $this->productRepository
                ->search(new Criteria([$product->getParentId()]), $event->getContext())
                ->first();

            if ($parent) {
                return $parent->getProductNumber();
            }
        }

        return $product->getProductNumber();
    }

    /**
     * @param string[] $skus
     */
    private function getProductsBySkus(array $skus, SalesChannelContext $context): ProductCollection
    {
        $products = new ProductCollection();
        if (empty($skus)) {
            return $products;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $skus));
        $criteria->addAssociation('cover.media');

        $searchResult = $this->salesChannelProductRepository->search($criteria, $context);

        // Preserve the order returned by Gally.
        foreach ($skus as $sku) {
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
