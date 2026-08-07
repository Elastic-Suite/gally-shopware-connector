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
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gally_is_active', $this->isActive(...)),
            new TwigFunction('gally_is_tracking_active', $this->isTrackingActive(...)),
            new TwigFunction('gally_tracking_base_url', $this->getTrackingBaseUrl(...)),
            new TwigFunction('gally_localized_catalog_code', $this->getLocalizedCatalogCode(...)),
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
}
