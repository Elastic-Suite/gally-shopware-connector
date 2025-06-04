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

namespace Gally\ShopwarePlugin\Indexer;

use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\Sdk\Entity\Metadata;
use Gally\Sdk\Service\IndexOperation;
use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Shopware\Core\Content\Media\Core\Application\AbstractMediaUrlGenerator;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Abstract pagination and bulk mechanism to index entity data to gally.
 */
abstract class AbstractIndexer
{
    /** @var LocalizedCatalog[][] */
    private array $localizedCatalogByChannel;

    public function __construct(
        protected ConfigManager $configManager,
        protected EntityRepository $salesChannelRepository,
        protected IndexOperation $indexOperation,
        protected CatalogProvider $catalogProvider,
        protected EntityRepository $entityRepository,
        protected AbstractMediaUrlGenerator $urlGenerator,
        protected EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function reindex(Context $context, array $documentIdsToReindex = []): void
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency', 'domains']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        $metadata = new Metadata($this->getEntityType());

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configManager->isActive($salesChannel->getId())) {
                $languages = [];
                foreach ($salesChannel->getLanguages() as $language) {
                    $languages[str_replace('-', '_', $language->getLocale()->getCode())] = $language;
                }

                foreach ($this->getLocalizedCatalogByChannel($context, $salesChannel) as $localizedCatalog) {
                    if (empty($documentIdsToReindex)) {
                        $index = $this->indexOperation->createIndex($metadata, $localizedCatalog);
                    } else {
                        $index = $this->indexOperation->getIndexByName($metadata, $localizedCatalog);
                    }

                    $batchSize = $this->configManager->getBatchSize($this->getEntityType(), $salesChannel->getId());
                    $bulk = [];
                    $language = $languages[$localizedCatalog->getLocale()];
                    foreach ($this->getDocumentsToIndex($salesChannel, $language, $documentIdsToReindex) as $document) {
                        $bulk[$document['id']] = json_encode($document);
                        if (\count($bulk) >= $batchSize) {
                            $this->indexOperation->executeBulk($index, $bulk);
                            $bulk = [];
                        }
                    }
                    if (\count($bulk)) {
                        $this->indexOperation->executeBulk($index, $bulk);
                    }

                    if (empty($documentIdsToReindex)) {
                        $this->indexOperation->refreshIndex($index);
                        $this->indexOperation->installIndex($index);
                    }
                }
            }
        }
    }

    abstract public function getEntityType(): string;

    abstract public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable;

    protected function getContext(SalesChannelEntity $salesChannel, LanguageEntity $language): Context
    {
        return new Context(
            new SystemSource(),
            [],
            $salesChannel->getCurrencyId(),
            [$language->getId(), Defaults::LANGUAGE_SYSTEM]
        );
    }

    /**
     * @return LocalizedCatalog[]
     */
    private function getLocalizedCatalogByChannel(Context $context, SalesChannelEntity $salesChannel): array
    {
        if (!isset($this->localizedCatalogByChannel)) {
            foreach ($this->catalogProvider->provide($context) as $localizedCatalog) {
                $catalogCode = $localizedCatalog->getCatalog()->getCode();
                if (!isset($this->localizedCatalogByChannel[$catalogCode])) {
                    $this->localizedCatalogByChannel[$catalogCode] = [];
                }
                $this->localizedCatalogByChannel[$catalogCode][] = $localizedCatalog;
            }
        }

        return $this->localizedCatalogByChannel[$salesChannel->getId()];
    }
}
