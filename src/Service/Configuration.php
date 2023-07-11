<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Configuration provider service.
 */
class Configuration
{
    public function __construct(private SystemConfigService $systemConfigService)
    {
    }

    public function isActive(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfigService->get('GallyPlugin.config.active', $salesChannelId);
    }

    public function getBaseUrl(): string
    {
        return trim((string) $this->systemConfigService->get('GallyPlugin.config.baseurl'), '/');
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
}
