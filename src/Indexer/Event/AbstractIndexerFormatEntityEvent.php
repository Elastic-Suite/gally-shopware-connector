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

use Shopware\Core\Content\Category\CategoryEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Allows to add/update entity data before indexation.
 */
abstract class AbstractIndexerFormatEntityEvent extends Event
{
    public function __construct(
        protected array $data,
    ) {
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function addData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
