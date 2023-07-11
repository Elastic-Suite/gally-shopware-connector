<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\Catalog;
use Gally\Rest\Model\LocalizedCatalog;
use Gally\Rest\Model\ModelInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Synchronize shopware sale channel languages with gally localizedCatalogs.
 */
class LocalizedCatalogSynchronizer extends AbstractSynchronizer
{
    private array $localizedCatalogByLocale = [];

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var LocalizedCatalog $entity */
        return $entity->getCode();
    }

    public function synchronizeAll(Context $context)
    {
        throw new \LogicException('Run catalog synchronizer to sync all localized catalog');
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $params['salesChannel'];

        /** @var LanguageEntity $language */
        $language = $params['language'];

        /** @var Catalog $catalog */
        $catalog = $params['catalog'];

        return $this->createOrUpdateEntity(
            new LocalizedCatalog([
                "name" => $language->getName(),
                "code" => $salesChannel->getId() . $language->getId(),
                "locale" => str_replace('-', '_', $language->getLocale()->getCode()),
                "currency" => $salesChannel->getCurrency()->getIsoCode(),
                "isDefault" => $language->getId() == $salesChannel->getLanguage()->getId(),
                "catalog" => "/catalogs/" . $catalog->getId(),
            ])
        );
    }

    protected function addEntityByIdentity(ModelInterface $entity)
    {
        /** @var LocalizedCatalog $entity */
        parent::addEntityByIdentity($entity);

        if (!array_key_exists($entity->getLocale(), $this->localizedCatalogByLocale)) {
            $this->localizedCatalogByLocale[$entity->getLocale()] = [];
        }

        $this->localizedCatalogByLocale[$entity->getLocale()][$entity->getId()] = $entity;
    }

    public function getLocalizedCatalogByLocale(string $localeCode): array
    {
        if (empty($this->localizedCatalogByLocale)) {
            // Load all entities to be able to check if the asked entity exists.
            $this->fetchEntities();
        }

        return $this->localizedCatalogByLocale[$localeCode] ?? [];
    }

    public function getByIdentity(string $identifier): ?ModelInterface
    {
        if (!$this->allEntityHasBeenFetch) {
            $this->fetchEntities();
        }

        return $this->entityByCode[$identifier] ?? null;
    }
}

