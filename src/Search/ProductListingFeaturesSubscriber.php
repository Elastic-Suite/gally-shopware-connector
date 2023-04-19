<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Search;

use Doctrine\DBAL\Connection;
use Gally\Rest\Model\CategorySortingOption;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Content\Product\Events\ProductListingCollectFilterEvent;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Exception\ProductSortingNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\Listing\Filter;
use Shopware\Core\Content\Product\SalesChannel\Listing\FilterCollection;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Aggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\EntityAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductListingFeaturesSubscriber implements EventSubscriberInterface
{
    public const DEFAULT_SEARCH_SORT = '_score';
    public const PROPERTY_GROUP_IDS_REQUEST_PARAM = 'property-whitelist';

    private Configuration $configuration;
    private Adapter $searchAdapter;
    private SortOptionProvider $sortOptionProvider;
    private EntityRepositoryInterface $optionRepository;
    private EntityRepositoryInterface $sortingRepository;
    private Connection $connection;
    private SystemConfigService $systemConfigService;
    private EventDispatcherInterface $dispatcher;
    private Result $gallyResults;
    private int $limit;
    private int $offset;

    public function __construct(
        Configuration $configuration,
        Adapter $searchAdapter,
        SortOptionProvider $sortOptionProvider,
        Connection $connection,
        EntityRepositoryInterface $optionRepository,
        EntityRepositoryInterface $productSortingRepository,
        SystemConfigService $systemConfigService,
        EventDispatcherInterface $dispatcher
    ) {
        $this->configuration = $configuration;
        $this->searchAdapter = $searchAdapter;
        $this->sortOptionProvider = $sortOptionProvider;

        $this->optionRepository = $optionRepository;
        $this->sortingRepository = $productSortingRepository;
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
        $this->dispatcher = $dispatcher;
    }

    public static function getSubscribedEvents(): array
    {
        return [
//            ProductListingCriteriaEvent::class => [
//                ['handleListingRequest', 200],
//            ],
            ProductSearchCriteriaEvent::class => [
                ['setDefaultOrder', 200],
                ['handleSearchRequest', 50],
            ],
//            ProductListingResultEvent::class => [
//                ['handleResult', 50],
//            ],
//            ProductSearchResultEvent::class => [
//                ['handleResult', 50],
//            ],
            SearchPageLoadedEvent::class =>[
                ['handleResult', 50],
            ],
        ];
    }

    public function setDefaultOrder(ProductSearchCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $criteria = $event->getCriteria();
        $context = $event->getSalesChannelContext();
        if (!$request->get('order')) {
            $request->request->set('order', self::DEFAULT_SEARCH_SORT);
        }
        $this->handleSorting($request, $criteria, $context);
    }

    public function handleListingRequest(ProductListingCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $criteria = $event->getCriteria();
        $context = $event->getSalesChannelContext();

        if (!$request->get('order')) {
            $request->request->set('order', $this->getSystemDefaultSorting($context));
        }

        $criteria->addAssociation('options');

        $this->handlePagination($request, $criteria, $event->getSalesChannelContext());

        $this->handleFilters($request, $criteria, $context);

        $this->handleSorting($request, $criteria, $context);
    }

    public function handleSearchRequest(ProductSearchCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $criteria = $event->getCriteria();
        $context = $event->getSalesChannelContext();

//        $this->handlePagination($request, $criteria, $event->getSalesChannelContext());
        $this->handleFilters($request, $criteria, $context); // TODO
        $this->handleSorting($request, $criteria, $context);

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {

            $term = $request->query->get('search');

            if ($term) { // todo remove this if for category ?
                // Add search term in criteria
                $criteria->setTerm($term);

                // Search data from gally
                $this->gallyResults = $this->searchAdapter->search($context, $criteria);

                // Save base criteria data
                $this->limit = $criteria->getLimit();
                $this->offset = $criteria->getOffset();

                // Create new criteria with gally result
                $this->resetCriteria($criteria);
                $productNumbers = $this->gallyResults->getProductNumbers();
                $criteria->addFilter(
                    new OrFilter([
                        new EqualsAnyFilter('productNumber', $productNumbers),
                        new EqualsAnyFilter('parent.productNumber', $productNumbers),
                    ])
                );
            }
        }
    }

    public function handleResult(SearchPageLoadedEvent $event): void
    {
//            $this->groupOptionAggregations($event);
//
//            $this->addCurrentFilters($event);
//
//            $result = $event->getResult();
//
//            /** @var ProductSortingCollection $sortings */
//            $sortings = $result->getCriteria()->getExtension('sortings');
//            $currentSortingKey = $this->getCurrentSorting($sortings, $event->getRequest())->getKey();
//
//            $result->setSorting($currentSortingKey);
//
//
//        $listing->setPage($this->getPage($event->getRequest()));
//
//        $listing->setLimit($this->getLimit($event->getRequest(), $event->getSalesChannelContext()));

        $listing = $event->getPage()->getListing();
//        $oldListing = clone $listing;

//        $sortings = $listing->getCriteria()->getExtension('sortings');
//        $listing->setAvailableSortings($sortings);

//        // manufacturer,price,rating-exists,shipping-free-filter,properties,options
        $newListing = ProductListingResult::createFrom(new EntitySearchResult(
            $listing->getEntity(),
            $this->gallyResults->getTotalResultCount(),
            $listing->getEntities(),
            $listing->getAggregations(), // Todo : generate aggregations object from gally results
            $listing->getCriteria(),
            $listing->getContext()
        ));

        $listing->setLimit($this->limit);
        $listing->setLimit($this->offset);

        $newListing->getCriteria()->setLimit($this->limit);
        $newListing->getCriteria()->setOffset($this->offset);
        $newListing->setExtensions($listing->getExtensions());
        $newListing->setSorting($listing->getSorting()); // Todo : use gally response to set current sorting
        $newListing->setAvailableSortings($listing->getAvailableSortings());

        $this->sortListing($listing);

//        $event->stopPropagation();
//        $event->getResult()

        $event->getPage()->setListing($newListing);
    }

    private function handleFilters(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        $criteria->addAssociation('manufacturer');

        $filters = $this->getFilters($request, $context);

        $aggregations = $this->getAggregations($request, $filters);

        foreach ($aggregations as $aggregation) {
            $criteria->addAggregation($aggregation);
        }

        foreach ($filters as $filter) {
            if ($filter->isFiltered()) {
                $criteria->addPostFilter($filter->getFilter());
            }
        }

        $criteria->addExtension('filters', $filters);
    }

    /**
     * @return list<Aggregation>
     */
    private function getAggregations(Request $request, FilterCollection $filters): array
    {
        $aggregations = [];

        if ($request->get('reduce-aggregations') === null) {
            foreach ($filters as $filter) {
                $aggregations = array_merge($aggregations, $filter->getAggregations());
            }

            return $aggregations;
        }

        foreach ($filters as $filter) {
            $excluded = $filters->filtered();

            if ($filter->exclude()) {
                $excluded = $excluded->blacklist($filter->getName());
            }

            foreach ($filter->getAggregations() as $aggregation) {
                if ($aggregation instanceof FilterAggregation) {
                    $aggregation->addFilters($excluded->getFilters());

                    $aggregations[] = $aggregation;

                    continue;
                }

                $aggregation = new FilterAggregation(
                    $aggregation->getName(),
                    $aggregation,
                    $excluded->getFilters()
                );

                $aggregations[] = $aggregation;
            }
        }

        return $aggregations;
    }

    private function handlePagination(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        $limit = $this->getLimit($request, $context);

        $page = $this->getPage($request);

        $criteria->setOffset(($page - 1) * $limit);
        $criteria->setLimit($limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
    }

    private function handleSorting(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        /** @var ProductSortingCollection $sortings */
        $sortings = $criteria->getExtension('sortings') ?? new ProductSortingCollection();
        $sortings->merge($this->getAvailableSortings($request, $context->getContext()));
        $criteria->addExtension('sortings', $sortings);

//        /** @var ProductSortingCollection $sortings */
//        $sortings = $criteria->getExtension('gally-sortings') ?? $this->getAvailableSortings();
//        $sortings->merge($this->getAvailableSortings());
//
        $currentSorting = $this->getCurrentSorting($sortings, $request); // TODO

        $criteria->addSorting(
            ...$currentSorting->createDalSorting()
        );

//        $criteria->addExtension('gally-sortings', $sortings);
////        $criteria->addExtension('sortings', $sortings);
    }

    private function getCurrentSorting(ProductSortingCollection $sortings, Request $request): ProductSortingEntity
    {
        $key = $request->get('order');

        $sorting = $sortings->getByKey($key);
        if ($sorting !== null) {
            return $sorting;
        }

        throw new ProductSortingNotFoundException($key);
    }

    private function getAvailableSortings(): ProductSortingCollection
    {
        $sortingOptions = $this->sortOptionProvider->getSortingOptions();
        $sortings = new ProductSortingCollection();

        /** @var CategorySortingOption $option */
        foreach ($sortingOptions as $option) {
            foreach (['asc', 'desc'] as $direction) {
                if ($option->getCode() === self::DEFAULT_SEARCH_SORT) {
                    if ($direction === 'asc') {
                        continue;
                    }
                    $label = $option->getLabel();
                    $code = $option->getCode()      ;
                } else {
                    $label = $option->getLabel() . " ${direction}ending";
                    $code = $option->getCode() . '-' . $direction;
                }
                $sortingEntity = new ProductSortingEntity();
                $sortingEntity->setId($code);
                $sortingEntity->setKey($code);
                $sortingEntity->setFields([]);
                $sortingEntity->setLabel($label);
                $sortingEntity->addTranslated('label', $label);
                $sortings->add($sortingEntity);
            }
        }

        return $sortings;
    }

    private function getSystemDefaultSorting(SalesChannelContext $context): string
    {
        return $this->systemConfigService->getString(
            'core.listing.defaultSorting',
            $context->getSalesChannel()->getId()
        );
    }

    /**
     * @return list<string>
     */
    private function collectOptionIds(ProductListingResultEvent $event): array
    {
        $aggregations = $event->getResult()->getAggregations();

        /** @var TermsResult|null $properties */
        $properties = $aggregations->get('properties');

        /** @var TermsResult|null $options */
        $options = $aggregations->get('options');

        $options = $options ? $options->getKeys() : [];
        $properties = $properties ? $properties->getKeys() : [];

        return array_unique(array_filter(array_merge($options, $properties)));
    }

    private function groupOptionAggregations(ProductListingResultEvent $event): void
    {
        $ids = $this->collectOptionIds($event);

        if (empty($ids)) {
            return;
        }

        $criteria = new Criteria($ids);
        $criteria->setLimit(500);
        $criteria->addAssociation('group');
        $criteria->addAssociation('media');
        $criteria->addFilter(new EqualsFilter('group.filterable', true));
        $criteria->setTitle('product-listing::property-filter');
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));

        $mergedOptions = new PropertyGroupOptionCollection();

        $repositoryIterator = new RepositoryIterator($this->optionRepository, $event->getContext(), $criteria);
        while (($result = $repositoryIterator->fetch()) !== null) {
            /** @var PropertyGroupOptionCollection $entities */
            $entities = $result->getEntities();

            $mergedOptions->merge($entities);
        }

        // group options by their property-group
        $grouped = $mergedOptions->groupByPropertyGroups();
        $grouped->sortByPositions();
        $grouped->sortByConfig();

        $aggregations = $event->getResult()->getAggregations();

        // remove id results to prevent wrong usages
        $aggregations->remove('properties');
        $aggregations->remove('configurators');
        $aggregations->remove('options');
        /** @var EntityCollection<Entity> $grouped */
        $aggregations->add(new EntityResult('properties', $grouped));
    }

    private function addCurrentFilters(ProductListingResultEvent $event): void
    {
        $result = $event->getResult();

        $filters = $result->getCriteria()->getExtension('filters');
        if (!$filters instanceof FilterCollection) {
            return;
        }

        foreach ($filters as $filter) {
            $result->addCurrentFilter($filter->getName(), $filter->getValues());
        }
    }

    /**
     * @return list<string>
     */
    private function getManufacturerIds(Request $request): array
    {
        $ids = $request->query->get('manufacturer', '');
        if ($request->isMethod(Request::METHOD_POST)) {
            $ids = $request->request->get('manufacturer', '');
        }

        if (\is_string($ids)) {
            $ids = explode('|', $ids);
        }

        /** @var list<string> $ids */
        $ids = array_filter((array) $ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function getPropertyIds(Request $request): array
    {
        $ids = $request->query->get('properties', '');
        if ($request->isMethod(Request::METHOD_POST)) {
            $ids = $request->request->get('properties', '');
        }

        if (\is_string($ids)) {
            $ids = explode('|', $ids);
        }

        /** @var list<string> $ids */
        $ids = array_filter((array) $ids);

        return $ids;
    }

    private function getLimit(Request $request, SalesChannelContext $context): int
    {
        $limit = $request->query->getInt('limit', 0);

        if ($request->isMethod(Request::METHOD_POST)) {
            $limit = $request->request->getInt('limit', $limit);
        }

        $limit = $limit > 0 ? $limit : $this->systemConfigService->getInt('core.listing.productsPerPage', $context->getSalesChannel()->getId());

        return $limit <= 0 ? 24 : $limit;
    }

    private function getPage(Request $request): int
    {
        $page = $request->query->getInt('p', 1);

        if ($request->isMethod(Request::METHOD_POST)) {
            $page = $request->request->getInt('p', $page);
        }

        return $page <= 0 ? 1 : $page;
    }

    private function getFilters(Request $request, SalesChannelContext $context): FilterCollection
    {
        $filters = new FilterCollection();

        $filters->add($this->getManufacturerFilter($request));
        $filters->add($this->getPriceFilter($request));
        $filters->add($this->getRatingFilter($request));
        $filters->add($this->getShippingFreeFilter($request));
        $filters->add($this->getPropertyFilter($request));

        if (!$request->request->get('manufacturer-filter', true)) {
            $filters->remove('manufacturer');
        }

        if (!$request->request->get('price-filter', true)) {
            $filters->remove('price');
        }

        if (!$request->request->get('rating-filter', true)) {
            $filters->remove('rating');
        }

        if (!$request->request->get('shipping-free-filter', true)) {
            $filters->remove('shipping-free');
        }

        if (!$request->request->get('property-filter', true)) {
            $filters->remove('properties');

            if (\count($propertyWhitelist = $request->request->all(self::PROPERTY_GROUP_IDS_REQUEST_PARAM))) {
                $filters->add($this->getPropertyFilter($request, $propertyWhitelist));
            }
        }

        $event = new ProductListingCollectFilterEvent($request, $filters, $context);
        $this->dispatcher->dispatch($event);

        return $filters;
    }

    private function getManufacturerFilter(Request $request): Filter
    {
        $ids = $this->getManufacturerIds($request);

        return new Filter(
            'manufacturer',
            !empty($ids),
            [new EntityAggregation('manufacturer', 'product.manufacturerId', 'product_manufacturer')],
            new EqualsAnyFilter('product.manufacturerId', $ids),
            $ids
        );
    }

    /**
     * @param array<string>|null $groupIds
     */
    private function getPropertyFilter(Request $request, ?array $groupIds = null): Filter
    {
        $ids = $this->getPropertyIds($request);

        $propertyAggregation = new TermsAggregation('properties', 'product.properties.id');

        $optionAggregation = new TermsAggregation('options', 'product.options.id');

        if ($groupIds) {
            $propertyAggregation = new FilterAggregation(
                'properties-filter',
                $propertyAggregation,
                [new EqualsAnyFilter('product.properties.groupId', $groupIds)]
            );

            $optionAggregation = new FilterAggregation(
                'options-filter',
                $optionAggregation,
                [new EqualsAnyFilter('product.options.groupId', $groupIds)]
            );
        }

        if (empty($ids)) {
            return new Filter(
                'properties',
                false,
                [$propertyAggregation, $optionAggregation],
                new MultiFilter(MultiFilter::CONNECTION_OR, []),
                [],
                false
            );
        }

        $grouped = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(property_group_id)) as property_group_id, LOWER(HEX(id)) as id
             FROM property_group_option
             WHERE id IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => Connection::PARAM_STR_ARRAY]
        );

        $grouped = FetchModeHelper::group($grouped);

        $filters = [];
        foreach ($grouped as $options) {
            $options = array_column($options, 'id');

            $filters[] = new MultiFilter(
                MultiFilter::CONNECTION_OR,
                [
                    new EqualsAnyFilter('product.optionIds', $options),
                    new EqualsAnyFilter('product.propertyIds', $options),
                ]
            );
        }

        return new Filter(
            'properties',
            true,
            [$propertyAggregation, $optionAggregation],
            new MultiFilter(MultiFilter::CONNECTION_AND, $filters),
            $ids,
            false
        );
    }

    private function getPriceFilter(Request $request): Filter
    {
        $min = $request->get('min-price');
        $max = $request->get('max-price');

        $range = [];
        if ($min !== null && $min >= 0) {
            $range[RangeFilter::GTE] = $min;
        }
        if ($max !== null && $max >= 0) {
            $range[RangeFilter::LTE] = $max;
        }

        return new Filter(
            'price',
            !empty($range),
            [new StatsAggregation('price', 'product.cheapestPrice', true, true, false, false)],
            new RangeFilter('product.cheapestPrice', $range),
            [
                'min' => (float) $request->get('min-price'),
                'max' => (float) $request->get('max-price'),
            ]
        );
    }

    private function getRatingFilter(Request $request): Filter
    {
        $filtered = $request->get('rating');

        return new Filter(
            'rating',
            $filtered !== null,
            [
                new FilterAggregation(
                    'rating-exists',
                    new MaxAggregation('rating', 'product.ratingAverage'),
                    [new RangeFilter('product.ratingAverage', [RangeFilter::GTE => 0])]
                ),
            ],
            new RangeFilter('product.ratingAverage', [
                RangeFilter::GTE => (int) $filtered,
            ]),
            $filtered
        );
    }

    private function getShippingFreeFilter(Request $request): Filter
    {
        $filtered = (bool) $request->get('shipping-free', false);

        return new Filter(
            'shipping-free',
            $filtered === true,
            [
                new FilterAggregation(
                    'shipping-free-filter',
                    new MaxAggregation('shipping-free', 'product.shippingFree'),
                    [new EqualsFilter('product.shippingFree', true)]
                ),
            ],
            new EqualsFilter('product.shippingFree', true),
            $filtered
        );
    }



    private function getProductNumberByIds(array $ids): array
    {
        // Todo : find a better way to get this mapping
        return $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(p.id)), pp.product_number
                FROM product p
                INNER JOIN product pp ON pp.id = p.parent_id AND pp.version_id = :version
                WHERE p.id IN (:ids) AND p.version_id = :version',
            ['ids' => Uuid::fromHexToBytesList($ids), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => Connection::PARAM_STR_ARRAY]
        );
    }

    private function resetCriteria(Criteria $criteria)
    {
        $criteria->setTerm(null);
        $criteria->setLimit(count($this->gallyResults->getProductNumbers()));
        $criteria->setOffset(0);
        $criteria->resetAggregations();
        $criteria->resetFilters();
        $criteria->resetQueries();

        if ($criteria->getSorting()[0]->getField() === '_score') {
            $criteria->resetSorting();
        }
    }

    /**
     * Sort result according to gally order.
     */
    private function sortListing(ProductListingResult $listing): void
    {
        $gallyOrder = array_flip($this->gallyResults->getProductNumbers());
        $parentProductNumberMapping = $this->getProductNumberByIds($listing->getIds());
        $listing->sort(
            function (ProductEntity $productA, ProductEntity $productB) use ($gallyOrder, $parentProductNumberMapping) {

                $positionA = array_key_exists($productA->getId(), $parentProductNumberMapping)
                    ? $gallyOrder[$parentProductNumberMapping[$productA->getId()]]
                    : $gallyOrder[$productA->getProductNumber()];
                $positionB = array_key_exists($productB->getId(), $parentProductNumberMapping)
                    ? $gallyOrder[$parentProductNumberMapping[$productB->getId()]]
                    : $gallyOrder[$productB->getProductNumber()];

                return $positionA > $positionB;
            }
        );
    }
}
