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

namespace Gally\ShopwarePlugin\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigManager
{
    public function __construct(
        private SystemConfigService $systemConfigService,
    ) {
    }

    public function isActive(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get('GallyPlugin.config.active', $salesChannelId);
    }

    public function getBaseUrl(): string
    {
        return trim((string) $this->systemConfigService->get('GallyPlugin.config.baseurl'), '/');
    }

    public function checkSSL(): bool
    {
        return (bool) $this->systemConfigService->get('GallyPlugin.config.checkSsl');
    }

    public function getUser(): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.user');
    }

    public function getPassword(): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.password');
    }

    public function getBatchSize(string $entityType, ?string $salesChannelId = null): int
    {
        $configKey = "GallyPlugin.config.{$entityType}BatchSize";

        return (int) $this->systemConfigService->get($configKey, $salesChannelId);
    }

    public function getProductRecommendationMaxSize(?string $salesChannelId = null): int
    {
        return (int) $this->systemConfigService->get('GallyPlugin.config.productRecommendationMaxSize', $salesChannelId);
    }

    public function getCartRecommendationMaxSize(?string $salesChannelId = null): int
    {
        return (int) $this->systemConfigService->get('GallyPlugin.config.cartRecommendationMaxSize', $salesChannelId);
    }

    public function getCartRecommendationTypeCode(?string $salesChannelId = null): ?string
    {
        $typeCode = $this->systemConfigService->get('GallyPlugin.config.cartRecommendationTypeCode', $salesChannelId);

        return \is_string($typeCode) && '' !== $typeCode ? $typeCode : null;
    }
}
