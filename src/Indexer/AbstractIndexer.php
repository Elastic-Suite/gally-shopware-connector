<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Gally\Rest\Api\IndexApi;
use Gally\Rest\Api\IndexDocumentApi;
use Gally\Rest\Model\IndexCreate;
use Gally\ShopwarePlugin\Api\Client;
use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Synchronizer\LocalizedCatalogSynchronizer;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

abstract class AbstractIndexer
{
    private Configuration $configuration;
    private Client $client;
    private EntityRepository $salesChannelRepository;
    private LocalizedCatalogSynchronizer $localizedCatalogSynchronizer;

    protected EntityRepository $entityRepository;

    public function __construct(
        Configuration $configuration,
        Client $client,
        EntityRepository $salesChannelRepository,
        LocalizedCatalogSynchronizer $localizedCatalogSynchronizer,
        EntityRepository $entityRepository
    ) {
        $this->configuration = $configuration;
        $this->client = $client;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->localizedCatalogSynchronizer = $localizedCatalogSynchronizer;
        $this->entityRepository = $entityRepository;
    }

    public function index()
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configuration->isActive($salesChannel->getId())) {
                $languages = $salesChannel->getLanguages();
                /** @var LanguageEntity $language */
                foreach ($languages as $language) {

                    // Todo manage partial reindex
//                    try {
//                        $index = $this->indexOperation->getIndexByName($this->typeName, $store);
//                    } catch (\Exception $exception) {
                        $indexName = $this->createIndex($salesChannel, $language);
//                    }

                    // Todo get from conf
                    $batchSize = 100;

                    $bulk = [];
                    foreach ($this->getDocumentsToIndex($salesChannel, $language) as $document) {
                        $bulk[$document['id']] = json_encode($document);
                        if (count($bulk) >= $batchSize) {
                            $this->executeBulk($indexName, $bulk);
                        }
                    }
                    if (count($bulk)) {
                        $this->executeBulk($indexName, $bulk);
                    }

                    $this->refreshIndex($indexName);
                    $this->installIndex($indexName);
                }
            }
        }
    }

    abstract public function getEntityType(): string;

    abstract public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language): iterable;

    protected function createIndex(SalesChannelEntity $salesChannel, LanguageEntity $language): string
    {
        $indexData = [
            'entityType' => $this->getEntityType(),
            'localizedCatalog' => $this->localizedCatalogSynchronizer->getEntityByIdentity(
                $salesChannel->getId() . $language->getId()
            )->getCode(),
        ];

        /** @var IndexCreate $index */
        $index = $this->client->query(IndexApi::class, 'postIndexCollection', $indexData);

        return $index->getName();
    }

    protected function refreshIndex(string $indexName)
    {
        $this->client->query(IndexApi::class, 'refreshIndexItem', $indexName, []);
    }

    protected function installIndex(string $indexName)
    {
        $this->client->query(IndexApi::class, 'installIndexItem', $indexName, []);
    }

    protected function executeBulk(string $indexName, array $documents)
    {
        return $this->client->query(
            IndexDocumentApi::class,
            'postIndexDocumentCollection',
            ['indexName' => $indexName, 'documents' => $documents]
        );
    }

    protected function getContext(SalesChannelEntity $salesChannel, LanguageEntity $language): Context
    {
        return new Context(new SystemSource(), [], $salesChannel->getCurrencyId(), [$language->getId()]);
    }
}
