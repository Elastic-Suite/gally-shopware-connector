<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class GallyPlugin extends Plugin
{
    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
    }
}
