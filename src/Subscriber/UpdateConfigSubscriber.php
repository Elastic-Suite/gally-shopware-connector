<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Subscriber;

use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Save gally api configuration on global scope.
 */
class UpdateConfigSubscriber implements EventSubscriberInterface
{
    private SystemConfigService $configService;
    private array $globalConfigs = [
        'GallyPlugin.config.baseurl',
        'GallyPlugin.config.user',
        'GallyPlugin.config.password'
    ];

    public function __construct(
        SystemConfigService $configService
    ) {
        $this->configService = $configService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'beforeSystemConfigChange',
        ];
    }

    public function beforeSystemConfigChange(SystemConfigChangedEvent $event)
    {
        if ($event->getSalesChannelId()
            && $event->getValue()
            && in_array($event->getKey(), $this->globalConfigs)) {
            $this->configService->set($event->getKey(), $event->getValue());
            $this->configService->set($event->getKey(), null, $event->getSalesChannelId());
        }
    }
}
