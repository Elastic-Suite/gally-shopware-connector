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

namespace Gally\ShopwarePlugin\Indexer\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Allows to add additional source fields not managed directly by Shopware.
 * (For example a custom symfony entity created to store a custom attribute).
 */
class ProvideAdditionalSourceFieldEvent extends Event
{
    public const NAME = 'gally.source_field_provider.additional_source_field';

    private array $additionalSourceFields = [];

    public function __construct(
    ) {
    }

    public function getAdditionalSourceFields(): array
    {
        return $this->additionalSourceFields;
    }

    public function setAdditionalSourceFields(array $additionalSourceFields): void
    {
        $this->additionalSourceFields = $additionalSourceFields;
    }

    /**
     * Allows to add additional fields, for $data use the same format as Gally\ShopwarePlugin\Indexer\Provider\SourceFieldProvider::$staticFields.
     */
    public function addAdditionalFields(string $entity, array $data): void
    {
        $this->additionalSourceFields[$entity][] = $data;
    }
}
