<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\ModelInterface;
use Gally\ShopwarePlugin\Api\Client;
use Gally\ShopwarePlugin\Service\Configuration;

abstract class AbstractSynchronizer
{
    protected Configuration $configuration;
    protected Client $client;
    protected string $entityClass;
    protected string $getCollectionMethod;
    protected string $createEntityMethod;
    protected string $patchEntityMethod;
    protected array $entityByCode = [];

    public function __construct(
        Configuration $configuration,
        Client $client,
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

    abstract  public function getIdentity(ModelInterface $entity): string;

    abstract public function synchronizeAll();

    /**
     * Todo how to type params
     */
    abstract public function synchronizeItem(array $params): ?ModelInterface;

    public function getEntityByIdentity(string $identity): ?ModelInterface
    {
        if (empty($this->entityByCode)) {
            // Load all entities to be able to check if the asked entity exists.
            $this->fetchEntities();
        }

        return $this->entityByCode[$identity] ?? null;
    }

    protected function createOrUpdateEntity(ModelInterface $entity): ModelInterface
    {
        $this->validateEntity($entity);

        if ($this->getIdentity($entity)) {
            // Check if entity already exists.
            $existingEntity = $this->getEntityByIdentity($this->getIdentity($entity));
            if (!$existingEntity) {
                // Create it if needed. Also save it locally for later use.
                $entity = $this->client->query($this->entityClass, $this->createEntityMethod, $entity);
            } else {
                $entity = $this->client->query(
                    $this->entityClass,
                    $this->patchEntityMethod,
                    $existingEntity->getId(),
                    $entity
                );
            }
            $this->addEntityByIdentity($entity);
        }

        return $this->entityByCode[$this->getIdentity($entity)];
    }

    protected function fetchEntities()
    {
        if (empty( $this->entityById)) {
            $entities = $this->client->query($this->entityClass, $this->getCollectionMethod);
            foreach ($entities as $entity) {
                $this->addEntityByIdentity($entity);
            }
        }
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

