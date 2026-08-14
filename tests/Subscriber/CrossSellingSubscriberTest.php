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

namespace Gally\ShopwarePlugin\Tests\Subscriber;

use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\Sdk\Service\RecommenderManager;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeEntity;
use Gally\ShopwarePlugin\Service\RecommendationHelper;
use Gally\ShopwarePlugin\Subscriber\CrossSellingSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\Events\ProductCrossSellingsLoadedEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElement;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElementCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CrossSellingSubscriberTest extends TestCase
{
    private function buildCrossSelling(string $productId, int $limit, ?GallyRecommenderTypeEntity $recommenderType): ProductCrossSellingEntity
    {
        $crossSelling = new ProductCrossSellingEntity();
        $crossSelling->setUniqueIdentifier('cs-1');
        $crossSelling->setProductId($productId);
        $crossSelling->setLimit($limit);
        if (null !== $recommenderType) {
            $crossSelling->addExtension('gallyRecommenderType', $recommenderType);
        }

        return $crossSelling;
    }

    private function buildProduct(string $sku): ProductEntity
    {
        $product = new ProductEntity();
        $product->setUniqueIdentifier($sku);
        $product->setProductNumber($sku);

        return $product;
    }

    private function buildElement(ProductCrossSellingEntity $crossSelling, ProductCollection $products): CrossSellingElement
    {
        $element = new CrossSellingElement();
        $element->setCrossSelling($crossSelling);
        $element->setProducts($products);
        $element->setTotal($products->count());

        return $element;
    }

    public function testFillsRemainderWithGallyRecommendationsAfterNativeProducts(): void
    {
        $recommenderType = new GallyRecommenderTypeEntity();
        $recommenderType->setUniqueIdentifier('type-1');
        $recommenderType->setCode('related');

        $crossSelling = $this->buildCrossSelling('product-1', 3, $recommenderType);
        $nativeProducts = new ProductCollection([$this->buildProduct('NATIVE-1')]);
        $element = $this->buildElement($crossSelling, $nativeProducts);

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('isActive')->willReturn(true);

        $recommenderManager = $this->createMock(RecommenderManager::class);
        $recommenderManager->expects($this->once())
            ->method('getProductRecommendations')
            ->with('related', $this->anything(), ['SKU-1'], $this->anything())
            ->willReturn([['sku' => 'NATIVE-1'], ['sku' => 'GALLY-1'], ['sku' => 'GALLY-2']]);

        $helper = $this->createMock(RecommendationHelper::class);
        $helper->method('getProductSkuById')->willReturn('SKU-1');
        $helper->method('getCurrentLocalizedCatalog')->willReturn($this->createMock(LocalizedCatalog::class));
        $helper->method('getProductsBySkus')
            ->with(['GALLY-1', 'GALLY-2'])
            ->willReturn(new ProductCollection([$this->buildProduct('GALLY-1'), $this->buildProduct('GALLY-2')]));

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(ProductCrossSellingsLoadedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($context);
        $event->method('getCrossSellings')->willReturn(new CrossSellingElementCollection([$element]));

        (new CrossSellingSubscriber($configManager, $recommenderManager, $helper, new NullLogger()))
            ->onCrossSellingsLoaded($event);

        self::assertSame(3, $element->getTotal());
        self::assertSame(
            ['NATIVE-1', 'GALLY-1', 'GALLY-2'],
            array_values(array_map(static fn (ProductEntity $p) => $p->getProductNumber(), iterator_to_array($element->getProducts())))
        );
    }

    public function testSkipsGallyCallWhenNativeProductsAlreadyFillTheLimit(): void
    {
        $recommenderType = new GallyRecommenderTypeEntity();
        $recommenderType->setUniqueIdentifier('type-1');
        $recommenderType->setCode('related');

        $crossSelling = $this->buildCrossSelling('product-1', 1, $recommenderType);
        $nativeProducts = new ProductCollection([$this->buildProduct('NATIVE-1')]);
        $element = $this->buildElement($crossSelling, $nativeProducts);

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('isActive')->willReturn(true);

        $recommenderManager = $this->createMock(RecommenderManager::class);
        $recommenderManager->expects($this->never())->method('getProductRecommendations');

        $helper = $this->createMock(RecommendationHelper::class);

        $context = $this->createMock(SalesChannelContext::class);

        $event = $this->createMock(ProductCrossSellingsLoadedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($context);
        $event->method('getCrossSellings')->willReturn(new CrossSellingElementCollection([$element]));

        (new CrossSellingSubscriber($configManager, $recommenderManager, $helper, new NullLogger()))
            ->onCrossSellingsLoaded($event);

        self::assertSame(1, $element->getTotal());
    }

    public function testIgnoresCrossSellingGroupsWithoutGallyRecommenderType(): void
    {
        $crossSelling = $this->buildCrossSelling('product-1', 5, null);
        $nativeProducts = new ProductCollection([$this->buildProduct('NATIVE-1')]);
        $element = $this->buildElement($crossSelling, $nativeProducts);

        $configManager = $this->createMock(ConfigManager::class);
        $configManager->method('isActive')->willReturn(true);

        $recommenderManager = $this->createMock(RecommenderManager::class);
        $recommenderManager->expects($this->never())->method('getProductRecommendations');

        $helper = $this->createMock(RecommendationHelper::class);

        $context = $this->createMock(SalesChannelContext::class);

        $event = $this->createMock(ProductCrossSellingsLoadedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($context);
        $event->method('getCrossSellings')->willReturn(new CrossSellingElementCollection([$element]));

        (new CrossSellingSubscriber($configManager, $recommenderManager, $helper, new NullLogger()))
            ->onCrossSellingsLoaded($event);

        self::assertSame(1, $element->getTotal());
    }
}
