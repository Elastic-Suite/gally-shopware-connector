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

class ReindexMessage implements AsyncMessageInterface
{
    public const ENTIY_PRODUCT = 'product';
    public const ENTIY_CATEGORY = 'category';
    public const ENTIY_MANUFACTURER = 'manufacturer';

    public function __construct(
        private readonly string $entityCode,
        private readonly array $documentsIds,
    ) {}

    public function getEntityCode(): string
    {
        return $this->entityCode;
    }

    public function getDocumentsIds(): array
    {
        return $this->documentsIds;
    }
}
