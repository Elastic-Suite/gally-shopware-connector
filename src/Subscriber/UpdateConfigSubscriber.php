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

namespace Gally\ShopwarePlugin\Subscriber;

use Gally\Sdk\Client\Client;
use Gally\ShopwarePlugin\Config\CacheManager;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Save gally api configuration on global scope.
 */
class UpdateConfigSubscriber implements EventSubscriberInterface
{
    private array $globalConfigs = [
        'GallyPlugin.config.baseurl',
        'GallyPlugin.config.user',
        'GallyPlugin.config.password',
    ];

    public function __construct(
        private SystemConfigService $configService,
        private CacheManager $cacheManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'beforeSystemConfigChange',
        ];
    }

    public function beforeSystemConfigChange(SystemConfigChangedEvent $event)
    {
        if (!\in_array($event->getKey(), $this->globalConfigs, true)) {
            return;
        }

        // The cached API token was generated with the credentials being replaced: drop it so
        // the next call re-authenticates instead of failing (or succeeding) with stale ones.
        $this->cacheManager->clearCache(Client::API_TOKEN_CACHE_KEY);

        if ($event->getSalesChannelId() && $event->getValue()) {
            $this->configService->set($event->getKey(), $event->getValue());
            $this->configService->set($event->getKey(), null, $event->getSalesChannelId());
        }
    }
}
