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

namespace Gally\ShopwarePlugin\Factory;

use Gally\Sdk\Client\Configuration;
use Gally\ShopwarePlugin\Config\ConfigManager;

class ConfigurationFactory
{
    public static function create(ConfigManager $configManager): Configuration
    {
        return new Configuration(
            $configManager->getBaseUrl(),
            $configManager->getUser(),
            $configManager->getPassword(),
        );
    }
}
