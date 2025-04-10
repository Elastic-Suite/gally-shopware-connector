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

namespace Gally\ShopwarePlugin\Indexer\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncMessage implements AsyncMessageInterface
{
    public const ENTITY_SALES_CHANNEL = 'sales_channel';
    public const ENTITY_PROPERTY_GROUP = 'property_group';
    public const ENTITY_CUSTOM_FIELD = 'custom_field';
    public const ENTITY_CUSTOM_FIELD_SET = 'custom_field_set';

    public function __construct(
        private readonly string $entityCode,
        private readonly string|array $entityIds,
    ) {}

    public function getEntityCode(): string
    {
        return $this->entityCode;
    }

    public function getEntityIds(): array|string
    {
        return $this->entityIds;
    }
}
