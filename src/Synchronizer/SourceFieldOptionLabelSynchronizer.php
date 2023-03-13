<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\LocalizedCatalog;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldOption;
use Gally\Rest\Model\SourceFieldOptionLabel;
use Gally\Rest\Model\SourceFieldOptionLabelSourceFieldOptionLabelWrite;

class SourceFieldOptionLabelSynchronizer extends SourceFieldLabelSynchronizer
{
    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldOptionLabel $entity */
        return $entity->getSourceFieldOption() . $entity->getlocalizedCatalog();
    }

    public function synchronizeAll()
    {
        throw new \LogicException('Run source field synchronizer to sync all localized option labels');
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var SourceFieldOption $option */
        $option = $params['fieldOption'];

        /** @var string $localeCode */
        $localeCode = $params['localeCode'];

        /** @var string $label */
        $label = $params['label'];

        /** @var LocalizedCatalog $localizedCatalog */
        foreach ($this->localizedCatalogSynchronizer->getLocalizedCatalogByLocale($localeCode) as $localizedCatalog) {
            $this->createOrUpdateEntity(
                new SourceFieldOptionLabelSourceFieldOptionLabelWrite(
                    [
                        'sourceFieldOption' => '/source_field_options/' . $option->getId() ,
                        'localizedCatalog'  => '/localized_catalogs/' . $localizedCatalog->getId(),
                        'label'             => $label,
                    ]
                )
            );
        }

        return null;
    }

    protected function fetchEntities()
    {
        if (empty($this->entityById)) {
            $currentPage = 1;
            do {
                $entities = $this->client->query(
                    $this->entityClass,
                    $this->getCollectionMethod,
                    // Can't used named function argument in php7.4
                    null,
                    null,
                    null,
                    null,
                    null,
                    $currentPage,
                    30
                );

                foreach ($entities as $entity) {
                    $this->addEntityByIdentity($entity);
                }
                $currentPage++;
            } while (count($entities) > 0);
        }
    }
}
