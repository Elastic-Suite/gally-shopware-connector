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

namespace Gally\ShopwarePlugin\Indexer;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Format and index manufacturer entity data to gally.
 */
class ManufacturerIndexer extends AbstractIndexer
{
    public function getEntityType(): string
    {
        return 'manufacturer';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['media']);
        if (!empty($documentIdsToReindex)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $documentIdsToReindex));
        }
        $manufacturers = $this->entityRepository->search($criteria, $this->getContext($salesChannel, $language));
        /** @var ProductManufacturerEntity $manufacturer */
        foreach ($manufacturers as $manufacturer) {
            yield $this->formatManufacturer($manufacturer);
        }
    }

    private function formatManufacturer(ProductManufacturerEntity $manufacturer): array
    {
        return [
            'id' => $manufacturer->getId(),
            'name' => $manufacturer->getTranslation('name'),
            'description' => $manufacturer->getTranslation('description'),
            'link' => $manufacturer->getLink(),
            'image' => $manufacturer->getMedia()
                ? $this->urlGenerator->getRelativeMediaUrl($manufacturer->getMedia())
                : '',
        ];
    }
}
