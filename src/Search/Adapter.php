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

namespace Gally\ShopwarePlugin\Search;

use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\Sdk\Entity\Metadata;
use Gally\Sdk\GraphQl\Request;
use Gally\Sdk\Service\SearchManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Gally\ShopwarePlugin\Search\Aggregation\AggregationBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Gally search adapter.
 */
class Adapter
{
    public function __construct(
        private SearchManager $searchManager,
        private CatalogProvider $catalogProvider,
        private EntityRepository $languageRepository,
        private AggregationBuilder $aggregationBuilder,
    ) {
    }

    public function search(SalesChannelContext $context, Criteria $criteria, ?string $navigationId): Result
    {
        $sorts = $criteria->getSorting();
        $sort = reset($sorts);
        $currentPage = 0 == $criteria->getOffset() ? 1 : $criteria->getOffset() / $criteria->getLimit() + 1;

        $request = new Request(
            $this->getCurrentLocalizedCatalog($context),
            new Metadata('product'),
            $context->hasState('suggest'),
            ['sku', 'source'],
            $currentPage,
            $criteria->getLimit(),
            $navigationId,
            $criteria->getTerm(),
            $this->getFiltersFromCriteria($criteria),
            $sort && SortOptionProvider::DEFAULT_SEARCH_SORT !== $sort->getField() ? $sort->getField() : null,
            $sort && SortOptionProvider::DEFAULT_SEARCH_SORT !== $sort->getField() ? strtolower($sort->getDirection()) : null,
        );
        $response = $this->searchManager->search($request);

        $criteria->resetSorting();

        return new Result(
            $request,
            $response,
            $this->aggregationBuilder->build($response->getAggregations(), $context),
        );
    }

    public function viewMoreOption(SalesChannelContext $context, Criteria $criteria, string $aggregationField, ?string $navigationId)
    {
        $request = new Request(
            $this->getCurrentLocalizedCatalog($context),
            new Metadata('product'),
            false,
            ['sku', 'source'],
            1,
            0,
            $navigationId,
            $criteria->getTerm(),
            $this->getFiltersFromCriteria($criteria),
        );

        return $this->searchManager->viewMoreProductFilterOption($request, $aggregationField);
    }

    private function getFiltersFromCriteria(Criteria $criteria): array
    {
        $filters = [];
        foreach ($criteria->getPostFilters() as $filter) {
            switch ($filter::class) {
                case EqualsFilter::class:
                    $filters[] = [$filter->getField() => ['eq' => $filter->getValue()]];
                    break;
                case EqualsAnyFilter::class:
                    // On gally side, category filter can't handle multiple values
                    // This is why we use a boolean filter in this case.
                    if ('category__id' === $filter->getField()) {
                        $boolFilterClauses = [];
                        foreach ($filter->getValue() as $value) {
                            $boolFilterClauses[] = [$filter->getField() => ['eq' => $value]];
                        }
                        $filters[] = ['boolFilter' => ['_should' => $boolFilterClauses]];
                    } else {
                        $filters[] = [$filter->getField() => ['in' => $filter->getValue()]];
                    }
                    break;
                case RangeFilter::class:
                    $filters[] = [$filter->getField() => $filter->getParameters()];
                    break;
            }
        }

        return $filters;
    }

    private function getCurrentLocalizedCatalog(SalesChannelContext $context): LocalizedCatalog
    {
        $languageCriteria = new Criteria();
        $languageCriteria->addAssociations(['locale']);
        $languageCriteria->addFilter(new EqualsFilter('id', $context->getLanguageId()));
        /** @var LanguageEntity $currentLanguage */
        $currentLanguage = $this->languageRepository
            ->search($languageCriteria, $context->getContext())
            ->first();

        return $this->catalogProvider->buildLocalizedCatalog($context->getSalesChannel(), $currentLanguage);
    }
}
