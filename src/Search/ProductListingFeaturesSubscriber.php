<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\Events\CmsPageLoadedEvent;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductListingStruct;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\SalesChannel\Exception\ProductSortingNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

class ProductListingFeaturesSubscriber implements EventSubscriberInterface
{
    private Configuration $configuration;
    private Adapter $searchAdapter;
    private SortOptionProvider $sortOptionProvider;
    private Result $gallyResults;

    private array $nonFilterParameters = ['order', 'p', 'search', 'slots', 'no-aggregations'];

    public function __construct(
        Configuration $configuration,
        Adapter $searchAdapter,
        SortOptionProvider $sortOptionProvider
    ) {
        $this->configuration = $configuration;
        $this->searchAdapter = $searchAdapter;
        $this->sortOptionProvider = $sortOptionProvider;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Criteria building for category page
            ProductListingCriteriaEvent::class => [
                ['setDefaultOrder', 200],
                ['handleListingRequest', 50],
            ],
            // Criteria building for search page
            ProductSearchCriteriaEvent::class => [
                ['setDefaultOrder', 200],
                ['handleListingRequest', 50],
            ],
            // Listing override for category page
            NavigationPageLoadedEvent::class =>[
                ['handleNavigationResult', 50],
            ],
            // Listing override for category page update (ajax)
            CmsPageLoadedEvent::class =>[
                ['handleNavigationResult', 50],
            ],
            // Listing override for search page and search page update (ajax)
            SearchPageLoadedEvent::class =>[
                ['handleSearchResult', 50],
            ],
        ];
    }

    public function setDefaultOrder(ProductListingCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $criteria = $event->getCriteria();
        if (!$request->get('order')) {
            $request->request->set('order', SortOptionProvider::DEFAULT_SEARCH_SORT);
        }
        $this->handleSorting($request, $criteria);
    }

    public function handleListingRequest(ProductListingCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $criteria = $event->getCriteria();
        $context = $event->getSalesChannelContext();

        $this->handleFilters($request, $criteria);
        $this->handleSorting($request, $criteria);

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {

            if ($event instanceof ProductSearchCriteriaEvent) {
                $criteria->setTerm($request->get('search'));
                $criteria->setIds([$context->getSalesChannel()->getNavigationCategoryId()]);
            } else {
                $criteria->setIds([$request->get('navigationId')]);
            }

            // Search data from gally
            $this->gallyResults = $this->searchAdapter->search($context, $criteria);

            // Create new criteria with gally result
            $this->resetCriteria($criteria);
            $productNumbers = array_keys($this->gallyResults->getProductNumbers());
            $criteria->addFilter(
                new OrFilter([
                    new EqualsAnyFilter('productNumber', $productNumbers),
                    new EqualsAnyFilter('parent.productNumber', $productNumbers),
                ])
            );
        }
    }

    /**
     * Listing override for category page
     *
     * @param NavigationPageLoadedEvent|CmsPageLoadedEvent $event
     */
    public function handleNavigationResult(ShopwareEvent $event): void
    {
        /** @var CmsPageEntity $page */
        $page = $event instanceof NavigationPageLoadedEvent
            ? $event->getPage()->getCmsPage()
            : $event->getResult()->first();

        if ($page->getType() !== 'product_list') {
            return;
        }

        /** @var ProductListingStruct $listingContainer */
        $listingContainer = $page->getSections()->getBlocks()->getSlots()->getSlot('content')->getData();
        /** @var ProductListingResult $productListing */
        $productListing = $listingContainer->getListing();
        $listingContainer->setListing($this->gallyResults->getResultListing($productListing));
    }

    public function handleSearchResult(SearchPageLoadedEvent $event): void
    {
        $productListing = $event->getPage()->getListing();
        $event->getPage()->setListing($this->gallyResults->getResultListing($productListing));
    }

    private function handleFilters(Request $request, Criteria $criteria): void
    {
        $filters = $request->query->all();
        if ($request->isMethod(Request::METHOD_POST)) {
            $filters = $request->request->all();
        }

        $filterData = [];
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->nonFilterParameters)) {
                continue;
            }

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

        foreach ($filterData as $field => $data) {
            if (isset($data['min']) || isset($data['max'])) {
                $filterParams = [RangeFilter::GTE => (float) $data['min'] ?? 0];
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
        /** @var ProductSortingCollection $sortings */
        $sortings = $criteria->getExtension('gally-sortings') ?? $this->sortOptionProvider->getSortingOptions();
        $currentSortKey = $request->get('order');
        $currentSorting = $sortings->getByKey($currentSortKey);
        if ($currentSorting === null) {
            new ProductSortingNotFoundException($currentSortKey);
        }

        $criteria->resetSorting(); // Remove multiple default shopware sortings.
        $criteria->addSorting(...$currentSorting->createDalSorting());
        $criteria->addExtension('gally-sortings', $sortings);
        // Clone collection to prevent adding shopware base sorting in this list.
        $criteria->addExtension('sortings', clone $sortings);
    }

    /**
     * Reset collection criteria in order to search in mysql only with product number filter.
     */
    private function resetCriteria(Criteria $criteria)
    {
        $criteria->setTerm(null);
        $criteria->setIds([]);
        $criteria->setLimit(count($this->gallyResults->getProductNumbers()));
        $criteria->setOffset(0);
        $criteria->resetAggregations();
        $criteria->resetFilters();
        $criteria->resetPostFilters();
        $criteria->resetQueries();
        $criteria->resetSorting();
    }
}
