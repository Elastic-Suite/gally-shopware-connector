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

namespace Gally\ShopwarePlugin\Indexer\MessageHandler;

use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Indexer\CategoryIndexer;
use Gally\ShopwarePlugin\Indexer\ManufacturerIndexer;
use Gally\ShopwarePlugin\Indexer\Message\ReindexMessage;
use Gally\ShopwarePlugin\Indexer\ProductIndexer;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Message handler to manage async reindex request.
 */
#[AsMessageHandler]
class ReindexHandler
{
    public function __construct(
        private readonly ProductIndexer $productIndexer,
        private readonly CategoryIndexer $categoryIndexer,
        private readonly ManufacturerIndexer $manufacturerIndexer,
    ) {
    }

    public function __invoke(ReindexMessage $message): void
    {
        $context = Context::createDefaultContext();
        $this->getIndexerByEntityCode($message->getEntityCode())->reindex($context, $message->getDocumentsIds());
    }

    private function getIndexerByEntityCode(string $entityCode): AbstractIndexer
    {
        return match ($entityCode) {
            ReindexMessage::ENTIY_CATEGORY => $this->categoryIndexer,
            ReindexMessage::ENTIY_MANUFACTURER => $this->manufacturerIndexer,
            default => $this->productIndexer,
        };
    }
}
