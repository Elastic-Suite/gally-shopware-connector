<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ManufacturerIndexer extends AbstractIndexer
{
    public function getEntityType(): string
    {
        return 'manufacturer';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['media']);
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
            'name' => $manufacturer->getName(),
            'description' => $manufacturer->getDescription(),
            'image' => str_replace('http://localhost', '', $manufacturer->getMedia() ? $manufacturer->getMedia()->getUrl() : ''),
            'link' => $manufacturer->getLink(),
        ];
    }
}
