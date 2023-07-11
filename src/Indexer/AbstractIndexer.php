<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Service\IndexOperation;
use Shopware\Core\Content\Media\Pathname\UrlGenerator;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Abstract pagination and bulk mechanism to index entity data to gally.
 */
abstract class AbstractIndexer
{
    public function __construct(
        protected Configuration $configuration,
        protected EntityRepository $salesChannelRepository,
        protected IndexOperation $indexOperation,
        protected EntityRepository $entityRepository,
        protected UrlGenerator $urlGenerator
    ) {
    }

    public function reindex(Context $context, array $documentIdsToReindex = []): void
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency', 'domains']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configuration->isActive($salesChannel->getId())) {
                $languages = $salesChannel->getLanguages();
                /** @var LanguageEntity $language */
                foreach ($languages as $language) {

                    if (empty($documentIdsToReindex)) {
                        $indexName = $this->indexOperation->createIndex($this->getEntityType(), $salesChannel, $language);
                    } else {
                        $indexName = $this->indexOperation->getIndexByName($this->getEntityType(), $salesChannel, $language);
                    }

                    $batchSize = $this->configuration->getBatchSize($this->getEntityType(), $salesChannel->getId());
                    $bulk = [];
                    foreach ($this->getDocumentsToIndex($salesChannel, $language, $documentIdsToReindex) as $document) {
                        $bulk[$document['id']] = json_encode($document);
                        if (count($bulk) >= $batchSize) {
                            $this->indexOperation->executeBulk($indexName, $bulk);
                            $bulk = [];
                        }
                    }
                    if (count($bulk)) {
                        $this->indexOperation->executeBulk($indexName, $bulk);
                    }

                    if (empty($documentIdsToReindex)) {
                        $this->indexOperation->refreshIndex($indexName);
                        $this->indexOperation->installIndex($indexName);
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
}
