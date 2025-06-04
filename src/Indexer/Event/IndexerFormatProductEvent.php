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

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Allows to add/update product data before indexation.
 */
class IndexerFormatProductEvent extends AbstractIndexerFormatEntityEvent
{
    public const NAME = 'gally.product_indexer.format_product';

    public function __construct(
        array $data,
        private ProductEntity $product,
        private EntitySearchResult $children,
        private Context $context,
    ) {
        parent::__construct($data);
    }

    public function getProduct(): ProductEntity
    {
        return $this->product;
    }

    public function getChildren(): EntitySearchResult
    {
        return $this->children;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
