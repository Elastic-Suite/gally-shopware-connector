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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\ProductStream\Service\AbstractProductStreamBuilder;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Evaluate dynamic product groups (product streams) the same way the native category listing does
 * (see ProductListingRoute), instead of relying on the product_stream_mapping table which is updated asynchronously
 * and merges all languages.
 */
class ProductStreamResolver
{
    public function __construct(
        private ProductStreamBuilderInterface|AbstractProductStreamBuilder $productStreamBuilder,
        private EntityRepository $productRepository,
        private EntityRepository $languageRepository,
        private Connection $connection,
    ) {
    }

    /**
     * @return Filter[]|null null when the dynamic product group doesn't exist, is invalid or has no filter
     */
    public function getFilters(string $streamId, Context $context): ?array
    {
        try {
            $criteria = new Criteria();
            if ($this->productStreamBuilder instanceof AbstractProductStreamBuilder) {
                $this->productStreamBuilder->enrichCriteria($criteria, $streamId, $context);
            } else {
                $criteria->addFilter(...$this->productStreamBuilder->buildFilters($streamId, $context));
            }

            return $criteria->getFilters();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ids of the products matched by the given filters, with inheritance like the storefront.
     *
     * @param Filter[] $filters
     *
     * @return string[]
     */
    public function searchProductIds(array $filters, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(...$filters);

        try {
            return $context->enableInheritance(
                fn (Context $context): array => $this->productRepository->searchIds($criteria, $context)->getIds()
            );
        } catch (\Throwable) {
            // Filter on a field that no longer exists (deleted custom field...): the group matches no product.
            return [];
        }
    }

    /**
     * Parent product ids (the documents indexed in Gally) of the products matched by the given dynamic product
     * groups, in any language used by a sales channel.
     *
     * @param string[] $streamIds
     *
     * @return string[]
     */
    public function getParentProductIds(array $streamIds, Context $context): array
    {
        if ([] === $streamIds) {
            return [];
        }

        $productIds = [];
        foreach ($this->getLanguageContexts($context) as $languageContext) {
            foreach ($streamIds as $streamId) {
                $filters = $this->getFilters($streamId, $languageContext);
                if (null === $filters) {
                    continue;
                }
                foreach ($this->searchProductIds($filters, $languageContext) as $productId) {
                    $productIds[$productId] = true;
                }
            }
        }

        if ([] === $productIds) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(COALESCE(parent_id, id))) FROM product WHERE id IN (:ids) AND version_id = :version',
            ['ids' => Uuid::fromHexToBytesList(array_keys($productIds)), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * Keep the dynamic product groups used by at least one category, the only ones indexed in Gally.
     *
     * @param string[] $streamIds
     *
     * @return string[]
     */
    public function filterStreamsUsedByCategories(array $streamIds): array
    {
        if ([] === $streamIds) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(product_stream_id)) FROM category
             WHERE product_stream_id IN (:ids) AND product_assignment_type = :type AND version_id = :version',
            [
                'ids' => Uuid::fromHexToBytesList(array_values(array_unique($streamIds))),
                'type' => CategoryDefinition::PRODUCT_ASSIGNMENT_TYPE_PRODUCT_STREAM,
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * @return Context[]
     */
    private function getLanguageContexts(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NotEqualsFilter('salesChannels.id', null));

        $contexts = [];
        foreach ($this->languageRepository->searchIds($criteria, $context)->getIds() as $languageId) {
            $contexts[] = new Context(
                $context->getSource(),
                [],
                Defaults::CURRENCY,
                array_values(array_unique([$languageId, Defaults::LANGUAGE_SYSTEM]))
            );
        }

        return $contexts;
    }
}
