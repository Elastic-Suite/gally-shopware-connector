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

use Shopware\Core\Content\Product\SalesChannel\Search\ResolvedCriteriaProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Create a criteria object from a request object.
 */
class CriteriaBuilder
{
    public const GALLY_FILTER_PREFIX = 'g_';

    private ?string $navigationId = null;

    public function __construct(
        private SortOptionProvider $sortOptionProvider,
    ) {
    }

    public function getNavigationId(): ?string
    {
        return $this->navigationId;
    }

    public function build(Request $request, SalesChannelContext $context, ?Criteria $criteria = null): Criteria
    {
        if (!$criteria) {
            $criteria = new Criteria();
        }

        $this->handleFilters($request, $criteria);
        $this->handleSorting($request, $criteria);
        $this->navigationId = $request->get('navigationId', $context->getSalesChannel()->getNavigationCategoryId());
        $criteria->setTerm($request->get('search'));

        return $criteria;
    }

    private function handleFilters(Request $request, Criteria $criteria): void
    {
        $filters = $request->query->all();
        if ($request->isMethod(Request::METHOD_POST)) {
            $filters = $request->request->all();
        }

        $filterData = [];
        foreach ($filters as $field => $value) {
            if (!str_starts_with($field, self::GALLY_FILTER_PREFIX)) {
                continue;
            }

            $field = preg_replace('/^' . self::GALLY_FILTER_PREFIX . '/', '', $field);

            $data = [];
            if (str_contains($field, '_min')) {
                $field = str_replace('_min', '', $field);
                $data = $filterData[$field] ?? $data;
                $data['min'] = $value;
            } elseif (str_contains($field, '_max')) {
                $field = str_replace('_max', '', $field);
                $data = $filterData[$field] ?? $data;
                $data['max'] = $value;
            } elseif (str_contains($field, '_bool')) {
                $field = str_replace('_bool', '', $field);
                $data = ['eq' => (bool) $value];
            } elseif (str_contains($value, '|')) {
                $data = ['in' => explode('|', $value)];
            } else {
                $data = ['eq' => $value];
            }
            $filterData[$field] = $data;
        }

        $criteria->resetPostFilters();
        foreach ($filterData as $field => $data) {
            if (isset($data['min']) || isset($data['max'])) {
                $filterParams = [RangeFilter::GTE => (float) ($data['min'] ?? 0)];
                if (isset($data['max'])) {
                    $filterParams[RangeFilter::LTE] = (float) $data['max'];
                }
                $criteria->addPostFilter(new RangeFilter($field, $filterParams));
            } elseif (isset($data['in'])) {
                $criteria->addPostFilter(new EqualsAnyFilter($field, $data['in']));
            } elseif (isset($data['eq'])) {
                $criteria->addPostFilter(new EqualsFilter($field, $data['eq']));
            }
        }
    }

    private function handleSorting(Request $request, Criteria $criteria): void
    {
        if (!$request->get('order')
            || ResolvedCriteriaProductSearchRoute::DEFAULT_SEARCH_SORT === $request->get('order')) {
            $request->request->set('order', SortOptionProvider::DEFAULT_SEARCH_SORT);
        }

        /** @var ProductSortingCollection $sortings */
        $sortings = $criteria->getExtension('gally-sortings') ?? $this->sortOptionProvider->getSortingOptions();
        $currentSortKey = $request->get('order');
        $currentSorting = $sortings->getByKey($currentSortKey);

        $criteria->resetSorting(); // Remove multiple default shopware sortings.
        if (null !== $currentSorting) {
            $criteria->addSorting(...$currentSorting->createDalSorting());
        }

        $criteria->addExtension('gally-sortings', $sortings);
        // Clone collection to prevent adding shopware base sorting in this list.
        $criteria->addExtension('sortings', clone $sortings);
    }
}
