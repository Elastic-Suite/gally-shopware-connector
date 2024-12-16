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

namespace Gally\ShopwarePlugin\Indexer\Provider;

use Gally\Sdk\Entity\Catalog;
use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Gally Catalog data provider.
 */
class CatalogProvider implements ProviderInterface
{
    private array $catalogCache = [];

    public function __construct(
        private ConfigManager $configManager,
        private EntityRepository $salesChannelRepository,
    ) {
    }

    /**
     * @return iterable<LocalizedCatalog>
     */
    public function provide(Context $context): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configManager->isActive($salesChannel->getId())) {
                /** @var LanguageEntity $language */
                foreach ($salesChannel->getLanguages() as $language) {
                    yield $this->buildLocalizedCatalog($salesChannel, $language);
                }
            }
        }
    }

    public function buildLocalizedCatalog(SalesChannelEntity $salesChannel, LanguageEntity $language): LocalizedCatalog
    {
        if (!\array_key_exists($salesChannel->getId(), $this->catalogCache)) {
            $this->catalogCache[$salesChannel->getId()] = new Catalog(
                $salesChannel->getId(),
                $salesChannel->getName(),
            );
        }

        return new LocalizedCatalog(
            $this->catalogCache[$salesChannel->getId()],
            $salesChannel->getId() . $language->getId(),
            $language->getName(),
            str_replace('-', '_', $language->getLocale()->getCode()),
            $salesChannel->getCurrency()->getIsoCode()
        );
    }
}
