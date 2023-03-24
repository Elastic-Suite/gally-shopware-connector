<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Subscriber;

use Doctrine\DBAL\Connection;
use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Service\Searcher;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SearchSubscriber implements EventSubscriberInterface
{
    private Configuration $configuration;
    private Searcher $searcher;
    private Connection $connection;
    private array $gallyResults;
    private int $limit;
    private int $offset;

    public function __construct(
        Configuration $configuration,
        Searcher $searcher,
        Connection $connection
    ) {
        $this->configuration = $configuration;
        $this->searcher = $searcher;
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchCriteriaEvent::class => 'onSearchCriteriaEvent',
            SearchPageLoadedEvent::class      => 'onSearchPageLoadedEvent',
        ];
    }

    public function onSearchCriteriaEvent(ProductSearchCriteriaEvent $event): void
    {
        $criteria = $event->getCriteria();
        $request  = $event->getRequest();
        $context  = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {

            $term = $request->query->get('search');

            if ($term) {
                $criteria->setTerm($term);
                $this->gallyResults = $this->searcher->search($context, $criteria);

                $this->limit = $criteria->getLimit();
                $this->offset = $criteria->getOffset();
                $criteria->setTerm(null);
                $criteria->setLimit(count($this->gallyResults['products']));
                $criteria->setOffset(0);
                $criteria->resetFilters();
                $criteria->resetQueries();

                if ($criteria->getSorting()[0]->getField() === '_score') {
                    $criteria->resetSorting();
                }

                $criteria->addFilter(
                    new OrFilter([
                        new EqualsAnyFilter('productNumber', array_values($this->gallyResults['products'])),
                        new EqualsAnyFilter('parent.productNumber', array_values($this->gallyResults['products'])),
                    ])
                );
            }
        }
    }

    public function onSearchPageLoadedEvent(SearchPageLoadedEvent $event): void
    {
        $listing = $event->getPage()->getListing();

        $newListing = ProductListingResult::createFrom(new EntitySearchResult(
            $listing->getEntity(),
            $this->gallyResults['paginationData']['totalCount'],
            $listing->getEntities(),
            $listing->getAggregations(), // Todo : generate aggregations object from gally results
            $listing->getCriteria(),
            $listing->getContext()
        ));

        $newListing->getCriteria()->setLimit($this->limit);
        $newListing->getCriteria()->setOffset($this->offset);
        $newListing->setExtensions($listing->getExtensions());
        $newListing->setSorting($listing->getSorting()); // Todo : use gally response to set current sorting
        $newListing->setAvailableSortings($listing->getAvailableSortings());

        // Sort result according to gally order.
        $gallyOrder = array_flip($this->gallyResults['products']);
        $parentProductNumberMapping = $this->getProductNumberByIds($listing->getIds());
        $newListing->sort(
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

        $event->getPage()->setListing($newListing);
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
}
