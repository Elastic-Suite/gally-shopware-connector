<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Indexer;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class CategoryIndexer extends AbstractIndexer
{
    public function getEntityType(): string
    {
        return 'category';
    }

    public function getDocumentsToIndex(SalesChannelEntity $salesChannel, LanguageEntity $language): iterable
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter('id', $salesChannel->getNavigationCategoryId()),
                new ContainsFilter('path', $salesChannel->getNavigationCategoryId())
            ])
        );
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        // Todo add pagination
        $categories = $this->entityRepository->search($criteria, $this->getContext($salesChannel, $language));
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
            'path' => trim(str_replace('|', '/', $category->getPath()) . $category->getId(), '/'),
            'name' => $category->getName()
        ];
    }
}
