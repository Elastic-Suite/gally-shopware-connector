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
                    new ContainsFilter('path', $salesChannel->getNavigationCategoryId()),
                ])
            );
        }
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $categories = $this->entityRepository->search($criteria, $this->getContext($salesChannel, $language));
        $categories->get($salesChannel->getNavigationCategoryId());
        $rootCategory = $this->getRootCategory($salesChannel, $language);
        /** @var CategoryEntity $category */
        foreach ($categories as $category) {
            yield $this->formatCategory($rootCategory, $category);
        }
    }

    private function getRootCategory(SalesChannelEntity $salesChannel, LanguageEntity $language): ?CategoryEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $salesChannel->getNavigationCategoryId()));
        $rootCategory = $this->entityRepository->search($criteria, $this->getContext($salesChannel, $language))->first();

        return $rootCategory instanceof CategoryEntity ? $rootCategory : null;
    }

    private function formatCategory(CategoryEntity $rootCategory, CategoryEntity $category): array
    {
        $pathFromRoot = trim(
            str_replace(
                '|',
                '/', str_replace($rootCategory->getPath(), '', $category->getPath() ?? '')
            ) . $category->getId(),
            '/'
        );

        return [
            'id' => $category->getId(),
            'parentId' => $category->getId() === $rootCategory->getId() ? null : $category->getParentId(),
            'level' => $category->getLevel() - $rootCategory->getLevel() + 1,
            'path' => $pathFromRoot,
            'name' => $category->getTranslation('name'),
        ];
    }
}
