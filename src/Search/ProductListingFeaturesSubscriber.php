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

use Gally\ShopwarePlugin\Config\ConfigManager;
use Gally\ShopwarePlugin\Model\Message;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\Events\CmsPageLoadedEvent;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductListingStruct;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;
use Shopware\Storefront\Page\Suggest\SuggestPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProductListingFeaturesSubscriber implements EventSubscriberInterface
{
    private ?Result $gallyResults = null;

    public function __construct(
        private ConfigManager $configuration,
        private Adapter $searchAdapter,
        private CriteriaBuilder $criteriaBuilder,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
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
            // Criteria building for search page
            ProductSuggestCriteriaEvent::class => [
                ['setAutocompleteContext', 200],
                ['setDefaultOrder', 200],
                ['handleListingRequest', 50],
            ],
            // Listing override for category page
            NavigationPageLoadedEvent::class => [
                ['handleNavigationResult', 50],
            ],
            // Listing override for category page update (ajax)
            CmsPageLoadedEvent::class => [
                ['handleNavigationResult', 50],
            ],
            // Listing override for search page and search page update (ajax)
            SearchPageLoadedEvent::class => [
                ['handleSearchResult', 50],
            ],
            // Listing override for product suggest
            SuggestPageLoadedEvent::class => [
                ['handleSuggestResult', 50],
            ]
        ];
    }

    public function setDefaultOrder(ProductListingCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())
            && $this->configuration->getBaseUrl()
        ) {
            $this->criteriaBuilder->build($request, $context, $event->getCriteria());
        }
    }

    public function setAutocompleteContext(ProductSuggestCriteriaEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())
            && $this->configuration->getBaseUrl()
        ) {
            $context->addState('suggest');
        }
    }

    public function handleListingRequest(ProductListingCriteriaEvent $event): void
    {
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {
            if ($this->configuration->getBaseUrl()) {
                $criteria = $this->criteriaBuilder->build($request, $context, $event->getCriteria());

                // Search data from gally
                try {
                    $this->gallyResults = $this->searchAdapter->search(
                        $context,
                        $criteria,
                        $this->criteriaBuilder->getNavigationId()
                    );
                    // Create new criteria with gally result
                    $this->resetCriteria($criteria);
                    $productNumbers = array_keys($this->gallyResults->getProductNumbers());
                } catch (\RuntimeException $exception) {
                    $this->logger->error($exception->getMessage());
                    $productNumbers = [];
                }

                $criteria->setFilter(
                    'gally_filter',
                    new OrFilter([
                        new EqualsAnyFilter('productNumber', $productNumbers),
                        new EqualsAnyFilter('parent.productNumber', $productNumbers),
                    ])
                );
            } else {
                // Show an empty product listing if gally is misconfigured.
                $event->getCriteria()->addFilter(new EqualsAnyFilter('productNumber', []));
            }
        }
    }

    /**
     * Listing override for category page.
     *
     * @param NavigationPageLoadedEvent|CmsPageLoadedEvent $event
     */
    public function handleNavigationResult(ShopwareEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {
            /** @var CmsPageEntity $page */
            $page = $event instanceof NavigationPageLoadedEvent
                ? $event->getPage()->getCmsPage()
                : $event->getResult()->first();

            if ('product_list' !== $page->getType()) {
                return;
            }

            /** @var CmsBlockEntity $block */
            foreach ($page->getSections()->getBlocks() as $block) {
                if ('product-listing' == $block->getType()) {
                    /** @var ProductListingStruct $listingContainer */
                    $listingContainer = $block->getSlots()->getSlot('content')->getData();
                    /** @var ProductListingResult $productListing */
                    $productListing = $listingContainer->getListing();

                    if (!$this->gallyResults) {
                        $productListing->addExtension(
                            'gally-message',
                            new Message(
                                'warning',
                                $this->translator->trans($this->configuration->getBaseUrl()
                                    ? 'gally.listing.emptyResultMessage'
                                    : 'gally.listing.wrongConfiguration')
                            ));

                        return;
                    }

                    $listingContainer->setListing($this->gallyResults->getResultListing($productListing));
                }
            }
        }
    }

    public function handleSearchResult(SearchPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {
            $productListing = $event->getPage()->getListing();

            if (!$this->gallyResults) {
                $productListing->addExtension(
                    'gally-message',
                    new Message(
                        'warning',
                        $this->translator->trans($this->configuration->getBaseUrl()
                            ? 'gally.listing.emptyResultMessage'
                            : 'gally.listing.wrongConfiguration')
                    ));

                return;
            }

            $event->getPage()->setListing($this->gallyResults->getResultListing($productListing));
        }
    }

    public function handleSuggestResult(SuggestPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if ($this->configuration->isActive($context->getSalesChannel()->getId())) {
            /** @var ProductListingResult $productListing */
            $productListing = $event->getPage()->getSearchResult();

            if (!$this->gallyResults) {
                $productListing->addExtension(
                    'gally-message',
                    new Message(
                        'warning',
                        $this->translator->trans($this->configuration->getBaseUrl()
                            ? 'gally.listing.emptyResultMessage'
                            : 'gally.listing.wrongConfiguration')
                    ));

                return;
            }

            $event->getPage()->setSearchResult($this->gallyResults->getResultListing($productListing));
        }
    }

    /**
     * Reset collection criteria in order to search in mysql only with product number filter.
     */
    private function resetCriteria(Criteria $criteria)
    {
        $criteria->setTerm(null);
        $criteria->setLimit(\count($this->gallyResults->getProductNumbers()));
        $criteria->setOffset(0);
        $criteria->resetAggregations();
        $criteria->resetFilters();
        $criteria->resetPostFilters();
        $criteria->resetQueries();
        $criteria->resetSorting();
    }
}
