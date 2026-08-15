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

namespace Gally\ShopwarePlugin\Tests\Search;

use Gally\Sdk\Entity\Catalog;
use Gally\Sdk\Entity\LocalizedCatalog;
use Gally\Sdk\GraphQl\Request;
use Gally\Sdk\GraphQl\Response;
use Gally\Sdk\Service\SearchManager;
use Gally\ShopwarePlugin\Indexer\Provider\CatalogProvider;
use Gally\ShopwarePlugin\Search\Adapter;
use Gally\ShopwarePlugin\Search\Aggregation\AggregationBuilder;
use Gally\ShopwarePlugin\Search\Event\SearchRequestContextEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AdapterTest extends TestCase
{
    private const RAW_RESPONSE = [
        'products' => [
            'collection' => [],
            'aggregations' => [],
            'paginationInfo' => ['totalCount' => 0, 'lastPage' => 1, 'itemsPerPage' => 10],
            'sortInfo' => ['current' => [['field' => '_score', 'direction' => 'asc']]],
        ],
    ];

    public function testPriceGroupIdSetByListenerIsForwardedToTheSearchRequest(): void
    {
        $searchManager = $this->createMock(SearchManager::class);
        $capturedRequest = null;
        $searchManager->method('search')
            ->willReturnCallback(function (Request $request) use (&$capturedRequest) {
                $capturedRequest = $request;

                return new Response($request, self::RAW_RESPONSE);
            });

        $catalogProvider = $this->createMock(CatalogProvider::class);
        $catalogProvider->method('buildLocalizedCatalog')
            ->willReturn(new LocalizedCatalog(new Catalog('sc-1', 'Sales channel'), 'sc-1-lang-1', 'English', 'en_GB', 'EUR'));

        $languageRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(new LanguageEntity());
        $languageRepository->method('search')->willReturn($searchResult);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')
            ->willReturnCallback(function (SearchRequestContextEvent $event) {
                $event->setPriceGroupId('price-group-42');

                return $event;
            });

        $adapter = new Adapter(
            $searchManager,
            $catalogProvider,
            $languageRepository,
            $this->createMock(AggregationBuilder::class),
            $eventDispatcher,
        );

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getLanguageId')->willReturn('lang-1');
        $context->method('hasState')->willReturn(false);
        $context->method('getSalesChannel')->willReturn($this->createMock(SalesChannelEntity::class));
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->setLimit(10);

        $adapter->search($context, $criteria, null);

        $this->assertSame('price-group-42', $capturedRequest->getPriceGroupId());
    }
}
