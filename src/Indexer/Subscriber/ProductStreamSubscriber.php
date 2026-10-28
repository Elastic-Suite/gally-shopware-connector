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

namespace Gally\ShopwarePlugin\Indexer\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Gally\ShopwarePlugin\Indexer\Message\ReindexMessage;
use Gally\ShopwarePlugin\Service\ProductStreamResolver;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\ProductStream\Aggregate\ProductStreamFilter\ProductStreamFilterDefinition;
use Shopware\Core\Content\ProductStream\Event\ProductStreamIndexerEvent;
use Shopware\Core\Content\ProductStream\ProductStreamDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reindex the products entering or leaving a category filled by a dynamic product group (product stream).
 *
 * Products leaving a group can only be known before the write, while the group still has its old filters:
 *  - before a group or one of its filters is written, its current products are reindexed, then its new products once
 *    its filters are recompiled (ProductStreamIndexerEvent, also dispatched by the "since"/"until" scheduled task);
 *  - before a category changes its group (or assignment type, activation, parent) or is deleted, the products of
 *    its old group are reindexed, then the products of its new group.
 */
class ProductStreamSubscriber implements EventSubscriberInterface
{
    private const CATEGORY_STREAM_FIELDS = ['product_assignment_type', 'product_stream_id', 'active', 'parent_id'];
    private const BATCH_SIZE = 500;

    public function __construct(
        private MessageBusInterface $messageBus,
        private ProductStreamResolver $productStreamResolver,
        private Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWriteEvent::class => 'beforeWrite',
            ProductStreamIndexerEvent::class => 'reindexStreamProducts',
        ];
    }

    public function beforeWrite(EntityWriteEvent $event): void
    {
        [$oldCategoryStreamIds, $newCategoryStreamIds, $assignmentTypeChangedCategoryIds] = $this->getCategoryStreamChanges($event);
        $oldStreamIds = array_unique([
            ...$this->productStreamResolver->filterStreamsUsedByCategories($this->getWrittenStreamIds($event)),
            ...$oldCategoryStreamIds,
        ]);

        if ([] === $oldStreamIds && [] === $newCategoryStreamIds && [] === $assignmentTypeChangedCategoryIds) {
            return;
        }

        $context = $event->getContext();
        $productIds = [
            ...$this->productStreamResolver->getParentProductIds($oldStreamIds, $context),
            // Manual assignments are ignored while a category is filled by a dynamic product group.
            ...$this->getManuallyAssignedParentProductIds($assignmentTypeChangedCategoryIds),
        ];

        $event->addSuccess(function () use ($productIds, $newCategoryStreamIds, $context): void {
            $this->dispatchReindex([
                ...$productIds,
                ...$this->productStreamResolver->getParentProductIds($newCategoryStreamIds, $context),
            ]);
        });
    }

    public function reindexStreamProducts(ProductStreamIndexerEvent $event): void
    {
        $streamIds = $this->productStreamResolver->filterStreamsUsedByCategories($event->getIds());
        $this->dispatchReindex($this->productStreamResolver->getParentProductIds($streamIds, $event->getContext()));
    }

    /**
     * Groups written directly (e.g. by the "since"/"until" scheduled task) or through one of their filters.
     *
     * @return string[]
     */
    private function getWrittenStreamIds(EntityWriteEvent $event): array
    {
        $streamIds = $event->getIds(ProductStreamDefinition::ENTITY_NAME);

        $filterIds = [];
        foreach ($event->getCommandsForEntity(ProductStreamFilterDefinition::ENTITY_NAME) as $command) {
            $filterIds[] = $command->getDecodedPrimaryKey()['id'];
            // New filters are not in database yet, their group is only in the payload.
            $streamId = $command->getPayload()['product_stream_id'] ?? null;
            if (\is_string($streamId)) {
                $streamIds[] = Uuid::fromBytesToHex($streamId);
            }
        }

        if ([] !== $filterIds) {
            $streamIds = [...$streamIds, ...$this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(product_stream_id)) FROM product_stream_filter WHERE id IN (:ids)',
                ['ids' => Uuid::fromHexToBytesList($filterIds)],
                ['ids' => ArrayParameterType::BINARY]
            )];
        }

        return array_values(array_unique($streamIds));
    }

    /**
     * Groups of the categories whose product assignment changes: before the write, after it, and the categories
     * switching between manual and dynamic product group assignment.
     *
     * @return array{0: string[], 1: string[], 2: string[]}
     */
    private function getCategoryStreamChanges(EntityWriteEvent $event): array
    {
        $commands = [];
        foreach ($event->getCommandsForEntity(CategoryDefinition::ENTITY_NAME) as $command) {
            $primaryKey = $command->getDecodedPrimaryKey();
            if (($primaryKey['version_id'] ?? Defaults::LIVE_VERSION) !== Defaults::LIVE_VERSION) {
                continue;
            }
            if ($command instanceof DeleteCommand
                || [] !== array_intersect(self::CATEGORY_STREAM_FIELDS, array_keys($command->getPayload()))
            ) {
                $commands[$primaryKey['id']] = $command;
            }
        }

        if ([] === $commands) {
            return [[], [], []];
        }

        $oldCategories = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, product_assignment_type, LOWER(HEX(product_stream_id)) AS product_stream_id
             FROM category WHERE id IN (:ids) AND version_id = :version',
            ['ids' => Uuid::fromHexToBytesList(array_keys($commands)), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        ) as $category) {
            $oldCategories[$category['id']] = $category;
        }

        $oldStreamIds = [];
        $newStreamIds = [];
        $assignmentTypeChangedCategoryIds = [];
        foreach ($commands as $categoryId => $command) {
            $old = $oldCategories[$categoryId] ?? ['product_assignment_type' => null, 'product_stream_id' => null];
            $oldStreamId = $this->getStreamId($old['product_assignment_type'], $old['product_stream_id']);
            if (null !== $oldStreamId) {
                $oldStreamIds[] = $oldStreamId;
            }

            if ($command instanceof DeleteCommand) {
                continue;
            }

            $payload = $command->getPayload();
            if (\array_key_exists('product_assignment_type', $payload)
                && $payload['product_assignment_type'] !== $old['product_assignment_type']
            ) {
                $assignmentTypeChangedCategoryIds[] = $categoryId;
            }
            $newStreamId = $this->getStreamId(
                $payload['product_assignment_type'] ?? $old['product_assignment_type'],
                \array_key_exists('product_stream_id', $payload)
                    ? (\is_string($payload['product_stream_id']) ? Uuid::fromBytesToHex($payload['product_stream_id']) : null)
                    : $old['product_stream_id'],
            );
            if (null !== $newStreamId) {
                $newStreamIds[] = $newStreamId;
            }
        }

        return [array_values(array_unique($oldStreamIds)), array_values(array_unique($newStreamIds)), $assignmentTypeChangedCategoryIds];
    }

    /**
     * @param string[] $categoryIds
     *
     * @return string[]
     */
    private function getManuallyAssignedParentProductIds(array $categoryIds): array
    {
        if ([] === $categoryIds) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(COALESCE(product.parent_id, product.id))) FROM product_category
             INNER JOIN product ON product.id = product_category.product_id AND product.version_id = product_category.product_version_id
             WHERE product_category.category_id IN (:ids) AND product_category.category_version_id = :version',
            ['ids' => Uuid::fromHexToBytesList($categoryIds), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    private function getStreamId(?string $assignmentType, ?string $streamId): ?string
    {
        return CategoryDefinition::PRODUCT_ASSIGNMENT_TYPE_PRODUCT_STREAM === $assignmentType && $streamId ? $streamId : null;
    }

    /**
     * @param string[] $productIds
     */
    private function dispatchReindex(array $productIds): void
    {
        foreach (array_chunk(array_values(array_unique($productIds)), self::BATCH_SIZE) as $batch) {
            $this->messageBus->dispatch(new ReindexMessage(ReindexMessage::ENTIY_PRODUCT, $batch));
        }
    }
}
