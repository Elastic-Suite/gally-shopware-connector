<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Subscriber;

use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Service\Searcher;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SearchSubscriber implements EventSubscriberInterface
{
    private Configuration $configuration;
    private Searcher $searcher;

    public function __construct(
        Configuration $configuration,
        Searcher $searcher
    ) {
        $this->configuration = $configuration;
        $this->searcher = $searcher;
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductSearchCriteriaEvent::class  => 'onSearchCriteriaEvent'];
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
                $result = $this->searcher->search($context, $criteria);

                if (!empty($result['products'])) {
                    $criteria->setTerm(null);
                    $criteria->setLimit(count($result['products']));
                    $criteria->setOffset(0);

                    $criteria->resetFilters();
                    $criteria->resetQueries();

                    if ($criteria->getSorting()[0]->getField() === '_score') {
                        $criteria->resetSorting();
                    }

                    $criteria->addFilter(
                        new OrFilter([
                            new EqualsAnyFilter('productNumber', array_values($result['products'])),
                            new EqualsAnyFilter('parent.productNumber', array_values($result['products'])),
                        ])
                    );
                }
            }
        }
    }
}
