<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\ModelInterface;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Synchronize shopware store structure with gally.
 */
abstract class AbstractSynchronizer
{
    protected const FETCH_PAGE_SIZE = 50;

    protected Configuration $configuration;
    protected RestClient $client;
    protected string $entityClass;
    protected string $getCollectionMethod;
    protected string $createEntityMethod;
    protected string $patchEntityMethod;
    protected array $entityByCode = [];
    protected bool $allEntityHasBeenFetch = false;

    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod
    ) {
        $this->configuration = $configuration;
        $this->client = $client;
        $this->entityClass = $entityClass;
        $this->getCollectionMethod = $getCollectionMethod;
        $this->createEntityMethod = $createEntityMethod;
        $this->patchEntityMethod = $patchEntityMethod;
    }

    abstract public function synchronizeAll(SalesChannelEntity $salesChannel);

    abstract public function synchronizeItem(SalesChannelEntity $salesChannel, array $params = []): ?ModelInterface;

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function fetchEntities(SalesChannelEntity $salesChannel): void
    {
        if (!$this->allEntityHasBeenFetch) {
            $currentPage = 1;
            do {
                $entities = $this->client->query($salesChannel, ...$this->buildFetchAllParams($currentPage));

                foreach ($entities as $entity) {
                    $this->addEntityByIdentity($entity);
                }
                $currentPage++;
            } while (count($entities) >= self::FETCH_PAGE_SIZE);
            $this->allEntityHasBeenFetch = true;
        }
    }

    public function fetchEntity(SalesChannelEntity $salesChannel, ModelInterface $entity): ?ModelInterface
    {
        $entities = $this->client->query($salesChannel, ...$this->buildFetchOneParams($entity));
        if (count($entities) !== 1) {
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
            self::FETCH_PAGE_SIZE
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

    protected function createOrUpdateEntity(SalesChannelEntity $salesChannel, ModelInterface $entity): ModelInterface
    {
        $this->validateEntity($entity);

        if ($this->getIdentity($entity)) {
            // Check if entity already exists.
            $existingEntity = $this->getEntityFromApi($salesChannel, $entity);
            if (!$existingEntity) {
                // Create it if needed. Also save it locally for later use.
                $entity = $this->client->query($salesChannel, $this->entityClass, $this->createEntityMethod, $entity);
            } else {
                $entity = $this->client->query(
                    $salesChannel,
                    $this->entityClass,
                    $this->patchEntityMethod,
                    $existingEntity->getId(), // @phpstan-ignore-line
                    $entity
                );
            }
            $this->addEntityByIdentity($entity);
        }

        return $this->entityByCode[$this->getIdentity($entity)];
    }

    protected function getEntityFromApi(SalesChannelEntity $salesChannel, ModelInterface $entity): ?ModelInterface
    {
        if ($this->allEntityHasBeenFetch) {
            return $this->entityByCode[$this->getIdentity($entity)] ?? null;
        }

        return $this->fetchEntity($salesChannel, $entity);
    }

    protected function addEntityByIdentity(ModelInterface $entity)
    {
        $this->entityByCode[$this->getIdentity($entity)] = $entity;
    }

    protected function validateEntity(ModelInterface $entity)
    {
        if (!$entity->valid()) {
            throw new \LogicException(
                "Missing properties for "
                . get_class($entity) . " : "
                . implode(",", $entity->listInvalidProperties())
            );
        }
    }
}

