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
 * Allows to add/update category data before indexation.
 */
class IndexerFormatCategoryEvent extends AbstractIndexerFormatEntityEvent
{
    public const NAME = 'gally.category_indexer.format_category';

    public function __construct(
        array $data,
        private CategoryEntity $category,
    ) {
        parent::__construct($data);
    }

    public function getCategory(): CategoryEntity
    {
        return $this->category;
    }
}
