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

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Configuration provider service.
 */
class Configuration
{
    public function __construct(private SystemConfigService $systemConfigService)
    {
    }

    public function isActive(string $salesChannelId = null): bool
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

    public function getBatchSize(string $entityType, string $salesChannelId = null): int
    {
        $configKey = "GallyPlugin.config.{$entityType}BatchSize";

        return (int) $this->systemConfigService->get($configKey, $salesChannelId);
    }
}
