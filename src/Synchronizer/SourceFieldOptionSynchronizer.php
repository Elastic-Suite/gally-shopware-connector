<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Synchronizer;

use Gally\Rest\Model\ModelInterface;
use Gally\Rest\Model\SourceFieldOptionSourceFieldOptionWrite;
use Gally\Rest\Model\SourceFieldSourceFieldApi;
use Gally\ShopwarePlugin\Api\RestClient;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\Context;

/**
 * Synchronize shopware custom field and property options with gally source field options.
 */
class SourceFieldOptionSynchronizer extends AbstractSynchronizer
{
    public function __construct(
        Configuration $configuration,
        RestClient $client,
        string $entityClass,
        string $getCollectionMethod,
        string $createEntityMethod,
        string $patchEntityMethod,
        protected SourceFieldOptionLabelSynchronizer $sourceFieldOptionLabelSynchronizer
    ) {
        parent::__construct(
            $configuration,
            $client,
            $entityClass,
            $getCollectionMethod,
            $createEntityMethod,
            $patchEntityMethod
        );
    }

    public function getIdentity(ModelInterface $entity): string
    {
        /** @var SourceFieldOptionSourceFieldOptionWrite $entity */
        return $entity->getSourceField() . $entity->getCode();
    }

    public function synchronizeAll(Context $context)
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

        $labels = is_array($option) ? $option['label'] : $option->getTranslations();
        foreach ($labels as $localeCode => $label) {
            $this->sourceFieldOptionLabelSynchronizer->synchronizeItem(
                [
                    'fieldOption' => $sourceFieldOption,
                    'label' => is_string($label) ? $label : $label->getName(),
                    'localeCode' =>  str_replace('-', '_', is_string($label)
                        ? $localeCode
                        : $label->getLanguage()->getLocale()->getCode()),
                ]
            );
        }

        return $sourceFieldOption;
    }

    public function fetchEntities(): void
    {
        parent::fetchEntities();
        $this->sourceFieldOptionLabelSynchronizer->fetchEntities();
    }

    public function fetchEntity(ModelInterface $entity): ?ModelInterface
    {
        /** @var SourceFieldOptionSourceFieldOptionWrite $entity */
        $results = $this->client->query(...$this->buildFetchOneParams($entity));
        $filteredResults = [];
        /** @var SourceFieldOptionSourceFieldOptionWrite $result */
        foreach ($results as $result) {
            // It is not possible to search by source field option code in api.
            // So we need to get the good option after.
            if ($result->getCode() === $entity->getCode()) {
                $filteredResults[] = $result;
            }
        }
        if (count($filteredResults) !== 1) {
            return null;
        }
        return reset($filteredResults);
    }

    protected function buildFetchAllParams(int $page): array
    {
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            null,
            null,
            null,
            $page,
            self::FETCH_PAGE_SIZE,
        ];
    }

    protected function buildFetchOneParams(ModelInterface $entity): array
    {
        /** @var SourceFieldOptionSourceFieldOptionWrite $entity */
        return [
            $this->entityClass,
            $this->getCollectionMethod,
            $entity->getSourceField(),
        ];
    }
}
