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

namespace Gally\ShopwarePlugin\Twig;

use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class GallyExtension extends AbstractExtension
{
    public function __construct(
        private ConfigManager $configManager,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private EntityRepository $languageRepository,
        private CatalogProvider $catalogProvider,
        private EntityRepository $productRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gally_is_active', $this->isActive(...)),
            new TwigFunction('gally_is_tracking_active', $this->isTrackingActive(...)),
            new TwigFunction('gally_tracking_base_url', $this->getTrackingBaseUrl(...)),
            new TwigFunction('gally_localized_catalog_code', $this->getLocalizedCatalogCode(...)),
            new TwigFunction('gally_listing_product_list', $this->getListingProductList(...)),
            new TwigFunction('gally_parent_product_number', $this->getParentProductNumber(...)),
            new TwigFunction('gally_order_tracking_payload', $this->getOrderTrackingPayload(...)),
        ];
    }

    public function isActive(?string $salesChannelId): bool
    {
        return $this->configManager->isActive($salesChannelId);
    }

    public function isTrackingActive(?string $salesChannelId): bool
    {
        return $this->configManager->isTrackingActive($salesChannelId);
    }

    /**
     * The SDK appends '/graphql' itself, so the proxy route path is stripped of it here.
     */
    public function getTrackingBaseUrl(): string
    {
        $graphqlUrl = $this->urlGenerator->generate(
            'frontend.gally.tracking.graphql',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return rtrim(str_replace('/graphql', '', $graphqlUrl), '/');
    }

    public function getLocalizedCatalogCode(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext) {
            return null;
        }

        $languageCriteria = new Criteria();
        $languageCriteria->addAssociations(['locale']);
        $languageCriteria->addFilter(new EqualsFilter('id', $context->getLanguageId()));
        /** @var LanguageEntity|null $language */
        $language = $this->languageRepository->search($languageCriteria, $context->getContext())->first();
        if (null === $language) {
            return null;
        }

        return $this->catalogProvider->buildLocalizedCatalog($context->getSalesChannel(), $language)->getCode();
    }

    /**
     * Gally only indexes parent products (see ProductIndexer), variants are folded into the
     * parent's own document instead of getting their own entry. Any entityCode sent to tracking
     * must therefore be the parent's product number, never a variant's own one.
     */
    public function getParentProductNumber(ProductEntity $product): string
    {
        if (null === $product->getParentId()) {
            return $product->getProductNumber();
        }

        $request = $this->requestStack->getCurrentRequest();
        $context = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext) {
            return $product->getProductNumber();
        }

        /** @var ProductEntity|null $parent */
        $parent = $this->productRepository->search(new Criteria([$product->getParentId()]), $context->getContext())->first();

        return $parent?->getProductNumber() ?? $product->getProductNumber();
    }

    /**
     * Builds the "product_list" tracking payload (pagination, active sort, applied filters)
     * shared by category view and search events.
     *
     * @return array{item_count: int, current_page: int, page_count: int, sort_order: string, sort_direction: string, filters: array<int, array{name: string, value: string}>}
     */
    public function getListingProductList(ProductListingResult $result): array
    {
        $limit = max(1, $result->getLimit());
        $sortOrder = (string) $result->getSorting();
        $sortDirection = 'asc';

        foreach ($result->getAvailableSortings() as $sorting) {
            if ($sorting->getKey() !== $result->getSorting()) {
                continue;
            }

            $field = $sorting->getFields()[0] ?? null;
            if (null !== $field) {
                $sortOrder = $field['field'];
                $sortDirection = strtolower((string) ($field['order'] ?? 'asc'));
            }
            break;
        }

        $filters = [];
        foreach ($result->getCurrentFilters() as $name => $value) {
            if (\in_array($name, ['navigationId', 'search'], true) || empty($value)) {
                continue;
            }

            // Shopware always reports a "price" filter with {min: 0, max: 0} as a placeholder
            // when the customer hasn't actually touched the price slider: not a real applied
            // filter, and reporting it as one wrongly excludes the search from Gally's term
            // suggestions (which only suggest terms from unfiltered searches).
            if ('price' === $name && \is_array($value) && 0 == ($value['min'] ?? null) && 0 == ($value['max'] ?? null)) {
                continue;
            }

            $filters[] = [
                'name' => $name,
                'value' => \is_scalar($value) ? (string) $value : (string) json_encode($value),
            ];
        }

        return [
            'item_count' => $result->getTotal(),
            'current_page' => $result->getPage(),
            'page_count' => (int) ceil($result->getTotal() / $limit),
            'sort_order' => $sortOrder,
            'sort_direction' => $sortDirection,
            'filters' => $filters,
        ];
    }

    /**
     * Builds the "order" tracking payload (order totals + one line per purchased product) for
     * the checkout confirmation page.
     *
     * @return array{order: array{order_id: string, total: float}, items: array<int, array{child_sku: string, entityCode: string, order: array{price: float, qty: int, row_total: float}}>}
     */
    public function getOrderTrackingPayload(OrderEntity $order, SalesChannelContext $context): array
    {
        $items = [];

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if (LineItem::PRODUCT_LINE_ITEM_TYPE !== $lineItem->getType() || null === $lineItem->getProductId()) {
                continue;
            }

            $childSku = $lineItem->getPayload()['productNumber'] ?? null;
            if (null === $childSku) {
                continue;
            }

            $items[] = [
                'child_sku' => $childSku,
                'entityCode' => $this->getParentProductNumberById($lineItem->getProductId(), $childSku, $context->getContext()),
                'order' => [
                    'price' => $lineItem->getUnitPrice(),
                    'qty' => $lineItem->getQuantity(),
                    'row_total' => $lineItem->getTotalPrice(),
                ],
            ];
        }

        return [
            'order' => [
                'order_id' => (string) $order->getOrderNumber(),
                'total' => $order->getAmountTotal(),
            ],
            'items' => $items,
        ];
    }

    /**
     * Same parent-resolution rule as getParentProductNumber(), starting from a product id rather
     * than an already-loaded entity: order line items only carry the id, not the entity.
     */
    private function getParentProductNumberById(string $productId, string $fallbackNumber, Context $context): string
    {
        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search(new Criteria([$productId]), $context)->first();
        if (null === $product) {
            return $fallbackNumber;
        }

        if (null === $product->getParentId()) {
            return $product->getProductNumber();
        }

        /** @var ProductEntity|null $parent */
        $parent = $this->productRepository->search(new Criteria([$product->getParentId()]), $context)->first();

        return $parent?->getProductNumber() ?? $product->getProductNumber();
    }
}
