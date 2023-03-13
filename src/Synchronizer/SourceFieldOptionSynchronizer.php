<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\LocalizedCatalog;
use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldLabel;
use Gally\Rest\Model\SourceFieldOption;
use Gally\Rest\Model\SourceFieldOptionSourceFieldOptionWrite;
use Gally\Rest\Model\SourceFieldSourceFieldApi;
use Gally\ShopwarePlugin\Api\Client;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;

class SourceFieldOptionSynchronizer extends AbstractSynchronizer
{
    protected SourceFieldOptionLabelSynchronizer $sourceFieldOptionLabelSynchronizer;

    public function __construct(
        Configuration $configuration,
        Client $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod,
        SourceFieldOptionLabelSynchronizer $sourceFieldOptionLabelSynchronizer
    ) {
        parent::__construct($configuration, $client, $entityClass, $getCollectionMethod, $createEntityMethod, $patchEntityMethod);
        $this->sourceFieldOptionLabelSynchronizer = $sourceFieldOptionLabelSynchronizer;
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldOption $entity */
        return $entity->getSourceField() . $entity->getCode();
    }

    public function synchronizeAll()
    {
        throw new \LogicException('Run source field synchronizer to sync all options');
    }

    public function synchronizeItem(array $params): ?ModelInterface
    {
        /** @var SourceFieldSourceFieldApi $sourceField */
        $sourceField = $params['field'];

        /** @var array|PropertyGroupOptionEntity $option */
        $option = $params['option'];

        /** @var int $position */
        $position = $params['position'];

        $sourceFieldOption = $this->createOrUpdateEntity(
            new SourceFieldOptionSourceFieldOptionWrite(
                [
                    'sourceField'  => '/source_fields/' . $sourceField->getId() ,
                    'code'         => is_array($option) ? $option['value'] : $option->getId(),
                    'defaultLabel' => is_array($option) ? $option['value'] : $option->getName(),
                    'position'     => is_array($option) ? $position : $option->getPosition(),
                ]
            )
        );

//        foreach ($option['label'] as $localeCode => $label) {
//            $this->sourceFieldOptionLabelSynchronizer->synchronizeItem(
//                [
//                    'fieldOption' => $sourceFieldOption,
//                    'localeCode' => str_replace('-', '_', $localeCode),
//                    'label' => $label
//                ]
//            );
//        }

        return $sourceFieldOption;
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
