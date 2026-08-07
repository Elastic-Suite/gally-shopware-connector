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
        $metadata = new Metadata($this->getEntityType());

        foreach ($this->getActiveLocalizedCatalogs($context) as [$salesChannel, $language, $localizedCatalog]) {
            if (empty($documentIdsToReindex)) {
                $index = $this->indexOperation->createIndex($metadata, $localizedCatalog);
            } else {
                $index = $this->indexOperation->getIndexByName($metadata, $localizedCatalog);
            }

            $batchSize = $this->configManager->getBatchSize($this->getEntityType(), $salesChannel->getId());
            $bulk = [];
            foreach ($this->getDocumentsToIndex($salesChannel, $language, $documentIdsToReindex) as $document) {
                if (0 === \count($document)) {
                    continue;
                }
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

    public function remove(array $documentIdsToRemove): void
    {
        if ([] === $documentIdsToRemove) {
            return;
        }

        $metadata = new Metadata($this->getEntityType());
        $context = Context::createDefaultContext();

        foreach ($this->getActiveLocalizedCatalogs($context) as [, , $localizedCatalog]) {
            $index = $this->indexOperation->getIndexByName($metadata, $localizedCatalog);
            $this->indexOperation->deleteBulk($index, $documentIdsToRemove);
        }
    }

    /**
     * @return iterable<array{0: SalesChannelEntity, 1: LanguageEntity, 2: LocalizedCatalog}>
     */
    private function getActiveLocalizedCatalogs(Context $context): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency', 'domains']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if (!$this->configManager->isActive($salesChannel->getId())) {
                continue;
            }

            $languages = [];
            foreach ($salesChannel->getLanguages() as $language) {
                $languages[str_replace('-', '_', $language->getLocale()->getCode())] = $language;
            }

            foreach ($this->getLocalizedCatalogByChannel($context, $salesChannel) as $localizedCatalog) {
                yield [$salesChannel, $languages[$localizedCatalog->getLocale()], $localizedCatalog];
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
