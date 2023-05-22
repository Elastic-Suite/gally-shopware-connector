<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Format and index category entity data to gally.
 */
class CategoryIndexer extends AbstractIndexer
{
    public function getEntityType(): string
    {
        return 'category';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language, array $documentIdsToReindex): iterable
    {
        $criteria = new Criteria();
        if (!empty($documentIdsToReindex)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $documentIdsToReindex));
        } else {
            $criteria->addFilter(
                new OrFilter([
                    new EqualsFilter('id', $salesChannel->getNavigationCategoryId()),
                    new ContainsFilter('path', $salesChannel->getNavigationCategoryId())
                ])
            );
        }
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $categories = $this->entityRepository->search($criteria, $this->getContext($salesChannel, $language));
        /** @var CategoryEntity $category */
        foreach ($categories as $category) {
            yield $this->formatCategory($category);
        }
    }

    private function formatCategory(CategoryEntity $category): array
    {
        return [
            'id' => $category->getId(),
            'parentId' => $category->getParentId(),
            'level' => $category->getLevel(),
            'path' => trim(str_replace('|', '/', $category->getPath() ?? '') . $category->getId(), '/'),
            'name' => $category->getName()
        ];
    }
}
