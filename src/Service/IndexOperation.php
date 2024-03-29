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

use Gally\Rest\Api\IndexApi;
use Gally\Rest\Api\IndexDocumentApi;
use Gally\Rest\Model\IndexCreate;
use Gally\Rest\Model\IndexDetails;
use Gally\Rest\Model\LocalizedCatalog;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Synchronizer\LocalizedCatalogSynchronizer;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Indexer manager service.
 */
class IndexOperation
{
    public function __construct(
        private RestClient $client,
        private LocalizedCatalogSynchronizer $localizedCatalogSynchronizer
    ) {
    }

    public function createIndex(string $entityType, SalesChannelEntity $salesChannel, LanguageEntity $language): string
    {
        /** @var LocalizedCatalog $localizedCatalog */
        $localizedCatalog = $this->localizedCatalogSynchronizer->getByIdentity(
            $salesChannel->getId() . $language->getId()
        );
        $indexData = [
            'entityType' => $entityType,
            'localizedCatalog' => $localizedCatalog->getCode(),
        ];

        /** @var IndexCreate $index */
        $index = $this->client->query(IndexApi::class, 'postIndexCollection', $indexData);

        return $index->getName();
    }

    public function getIndexByName(string $entityType, SalesChannelEntity $salesChannel, LanguageEntity $language): string
    {
        /** @var LocalizedCatalog $localizedCatalog */
        $localizedCatalog = $this->localizedCatalogSynchronizer->getByIdentity(
            $salesChannel->getId() . $language->getId()
        );

        $indices = $this->client->query(IndexApi::class, 'getIndexCollection');

        /** @var IndexDetails $index */
        foreach ($indices as $index) {
            if (
                $index->getEntityType() === $entityType
                && $index->getLocalizedCatalog() === '/localized_catalogs/' . $localizedCatalog->getId()
                && 'live' === $index->getStatus()
            ) {
                return $index->getName();
            }
        }

        throw new \LogicException("Index for entity {$entityType} and localizedCatalog {$localizedCatalog->getCode()} does not exist yet. Make sure everything is reindexed.");
    }

    public function refreshIndex(string $indexName)
    {
        $this->client->query(IndexApi::class, 'refreshIndexItem', $indexName, []);
    }

    public function installIndex(string $indexName)
    {
        $this->client->query(IndexApi::class, 'installIndexItem', $indexName, []);
    }

    public function executeBulk(string $indexName, array $documents)
    {
        return $this->client->query(
            IndexDocumentApi::class,
            'postIndexDocumentCollection',
            ['indexName' => $indexName, 'documents' => $documents]
        );
    }
}
