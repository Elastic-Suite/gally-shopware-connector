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
use Gally\Sdk\Service\RecommenderManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Psr\Log\LoggerInterface;
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
    /** Page/struct extension name the "blocks" recommendations are stored under (see storefront templates). */
    public const EXTENSION_NAME = 'gallyRecommendations';

    public function __construct(
        private CatalogProvider $catalogProvider,
        private EntityRepository $languageRepository,
        private EntityRepository $productRepository,
        private SalesChannelRepository $salesChannelProductRepository,
        private RecommenderManager $recommenderManager,
        private RecommenderTypeCatalog $recommenderTypeCatalog,
        private LoggerInterface $logger,
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

        $productBySku = [];
        /** @var ProductEntity $product */
        foreach ($searchResult->getEntities() as $product) {
            $productBySku[$product->getProductNumber()] = $product;
        }

        // Preserve the order returned by Gally.
        foreach ($skus as $sku) {
            if (isset($productBySku[$sku])) {
                $products->add($productBySku[$sku]);
            }
        }

        return $products;
    }

    /**
     * Build the "code/name/products" blocks shown on the product page and cart: one Gally call
     * per configured type code, keyed to $seedSkus. A type that fails to resolve (e.g. removed in
     * Gally after being configured here) is logged and skipped rather than taking every other
     * configured type down with it.
     *
     * @param string[] $typeCodes
     * @param string[] $seedSkus
     *
     * @return array<array{code: string, name: string, products: ProductCollection}>
     */
    public function buildBlocks(
        array $typeCodes,
        array $seedSkus,
        int $maxSize,
        LocalizedCatalog $localizedCatalog,
        SalesChannelContext $context,
    ): array {
        if ([] === $typeCodes) {
            return [];
        }

        $nameByCode = $this->getRecommenderTypeNamesByCode();

        $blocks = [];
        foreach ($typeCodes as $typeCode) {
            try {
                $recommendedSkus = array_column(
                    $this->recommenderManager->getProductRecommendations($typeCode, $localizedCatalog, $seedSkus, $maxSize),
                    'sku'
                );
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    \sprintf('Gally: unable to load recommendations for type [%s]: %s', $typeCode, $exception->getMessage())
                );
                continue;
            }

            $blocks[] = [
                'code' => $typeCode,
                'name' => $nameByCode[$typeCode] ?? ucfirst(strtolower(str_replace(['_', '-'], ' ', $typeCode))),
                'products' => $this->getProductsBySkus($recommendedSkus, $context),
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, string>
     */
    public function getRecommenderTypeNamesByCode(): array
    {
        $nameByCode = [];
        foreach ($this->recommenderTypeCatalog->findAll() as $recommenderType) {
            $nameByCode[$recommenderType->getCode()] = $recommenderType->getName();
        }

        return $nameByCode;
    }

    /**
     * Gally indexes parent products only, so use the parent sku for variants.
     */
    public function getProductSkuById(string $productId, \Shopware\Core\Framework\Context $context): ?string
    {
        return $this->getProductSkusById($productId, $context)['parent'] ?? null;
    }

    /**
     * Same lookup as getProductSkuById(), but also returns the variant's own number: tracking
     * needs both (Gally's entityCode is always the parent, payload.child_sku is the exact variant
     * purchased), and it only has a Shopware product id to start from (see AddToCart's native
     * lineItems[<id>][id] field, read generically regardless of which page/widget submitted it).
     *
     * Shopware refuses to resolve the "parent" association inline (addAssociation('parent')
     * throws ParentAssociationCanNotBeFetched): the parent has to be a separate search, same as
     * GallyExtension::getParentProductNumber() already does.
     *
     * @return array{parent: string, self: string}|null
     */
    public function getProductSkusById(string $productId, \Shopware\Core\Framework\Context $context): ?array
    {
        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search(new Criteria([$productId]), $context)->get($productId);
        if (null === $product) {
            return null;
        }

        $parentSku = $product->getProductNumber();
        if (null !== $product->getParentId()) {
            /** @var ProductEntity|null $parent */
            $parent = $this->productRepository->search(new Criteria([$product->getParentId()]), $context)->get($product->getParentId());
            $parentSku = $parent?->getProductNumber() ?? $parentSku;
        }

        return [
            'parent' => $parentSku,
            'self' => $product->getProductNumber(),
        ];
    }
}
