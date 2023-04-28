<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Configuration provider service.
 */
class Configuration
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function isActive(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get('GallyPlugin.config.active', $salesChannelId);
    }

    public function getBaseUrl(?string $salesChannelId = null): string
    {
        return trim((string) $this->systemConfigService->get('GallyPlugin.config.baseurl', $salesChannelId), '/');
    }

    public function getUser(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.user', $salesChannelId);
    }

    public function getPassword(?string $salesChannelId = null): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.password', $salesChannelId);
    }

    public function getBatchSize(string $entityType, ?string $salesChannelId = null): int
    {
        $configKey = "GallyPlugin.config.{$entityType}BatchSize";
        return (int) $this->systemConfigService->get($configKey, $salesChannelId);
    }
}
