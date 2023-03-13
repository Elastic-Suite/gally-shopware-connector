<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

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

    public function getBaseUrl(?int $salesChannelId = null): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.baseurl', $salesChannelId);
    }

    public function getUser(?int $salesChannelId = null): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.user', $salesChannelId);
    }

    public function getPassword(?int $salesChannelId = null): string
    {
        return (string) $this->systemConfigService->get('GallyPlugin.config.password', $salesChannelId);
    }
}
