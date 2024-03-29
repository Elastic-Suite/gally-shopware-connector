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

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\ModelInterface;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\Context;

/**
 * Synchronize shopware store structure with gally.
 */
abstract class AbstractSynchronizer
{
    protected const FETCH_PAGE_SIZE = 50;
    protected const BATCH_SIZE = 100;
    protected array $entityByCode = [];
    private array $currentBatch = [];
    private int $currentBatchSize = 0;
    protected bool $allEntityHasBeenFetch = false;

    public function __construct(
        protected Configuration $configuration,
        protected RestClient $client,
        protected string $entityClass,
        protected string $getCollectionMethod,
        protected string $createEntityMethod,
        protected string $putEntityMethod,
        protected string $deleteEntityMethod,
        protected ?string $bulkEntityMethod = null
    ) {
    }

    abstract public function synchronizeAll(Context $context);

    abstract public function synchronizeItem(array $params): ?ModelInterface;

    /**
     * Remove all entity from gally that not exist anymore on shopware side.
     */
    public function cleanAll(Context $context, bool $dryRun = true, bool $quiet = false): void
    {

    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function fetchEntities(): void
    {
        $currentPage = 1;
        do {
            $entities = $this->client->query(...$this->buildFetchAllParams($currentPage));

            foreach ($entities as $entity) {
                $this->addEntityByIdentity($entity);
            }
            ++$currentPage;
        } while (\count($entities) >= self::FETCH_PAGE_SIZE);
        $this->allEntityHasBeenFetch = true;
    }

    public function fetchEntity(ModelInterface $entity): ?ModelInterface
    {
        $entities = $this->client->query(...$this->buildFetchOneParams($entity));
        if (1 !== \count($entities)) {
            return null;
        }

        return reset($entities);
    }

    abstract protected function getIdentity(ModelInterface $entity): string;

    protected function buildFetchAllParams(int $page): array
    {
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            null,
            null,
            $page,
            self::FETCH_PAGE_SIZE,
        ];
    }

    protected function buildFetchOneParams(ModelInterface $entity): array
    {
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            $this->getIdentity($entity),
        ];
    }

    protected function createOrUpdateEntity(ModelInterface $entity): ModelInterface
    {
        $this->validateEntity($entity);

        if ($this->getIdentity($entity)) {
            // Check if entity already exists.
            $existingEntity = $this->getEntityFromApi($entity);
            if (!$existingEntity) {
                // Create it if needed. Also save it locally for later use.
                $entity = $this->client->query($this->entityClass, $this->createEntityMethod, $entity);
            } else {
                $entity = $this->client->query(
                    $this->entityClass,
                    $this->putEntityMethod,
                    $existingEntity->getId(), // @phpstan-ignore-line
                    $entity
                );
            }
            $this->addEntityByIdentity($entity);
        }

        return $this->entityByCode[$this->getIdentity($entity)];
    }

    protected function getEntityFromApi(ModelInterface|string|int $entity): ?ModelInterface
    {
        if ($this->allEntityHasBeenFetch) {
            return $this->entityByCode[\is_scalar($entity) ? $entity : $this->getIdentity($entity)] ?? null;
        }

        return $this->fetchEntity($entity);
    }

    protected function addEntityByIdentity(ModelInterface $entity)
    {
        $this->entityByCode[$this->getIdentity($entity)] = $entity;
    }

    protected function validateEntity(ModelInterface $entity)
    {
        if (!$entity->valid()) {
            throw new \LogicException('Missing properties for ' . $entity::class . ' : ' . implode(',', $entity->listInvalidProperties()));
        }
    }

    protected function addEntityToBulk(ModelInterface $entity): void
    {
        if ($this->bulkEntityMethod === null) {
            throw new \Exception(sprintf('The entity %s doesn\'t have a bulk method.', $this->getEntityClass()));
        }

        $this->currentBatch[] = $entity;
        $this->currentBatchSize++;
        if ($this->currentBatchSize >= self::BATCH_SIZE) {
            $this->runBulk();
        }
    }

    protected function runBulk(): void
    {
        if ($this->currentBatchSize) {
            $entities = $this->client->query($this->entityClass, $this->bulkEntityMethod, 'fakeId', $this->currentBatch);
            foreach ($entities as $entity) {
                $this->addEntityByIdentity($entity);
            }
            $this->currentBatch = [];
            $this->currentBatchSize = 0;
        }
    }

    protected function getAllEntityCodes(): array
    {
        $this->fetchEntities();

        return array_keys($this->entityByCode);
    }

    protected function deleteEntity(int|string $entityId)
    {
        $this->client->query($this->entityClass, $this->deleteEntityMethod, $entityId);
    }
}
