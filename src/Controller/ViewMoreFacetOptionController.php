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

namespace Gally\ShopwarePlugin\Controller;

use Gally\ShopwarePlugin\Search\Adapter;
use Gally\ShopwarePlugin\Search\Aggregation\AggregationBuilder;
use Gally\ShopwarePlugin\Search\CriteriaBuilder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * Controller used to fetch more option of a filter.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class ViewMoreFacetOptionController extends StorefrontController
{
    public function __construct(
        private RequestTransformer $transformer,
        private CriteriaBuilder $criteriaBuilder,
        private AggregationBuilder $aggregationBuilder,
        private Adapter $adapter,
    ) {
    }

    /**
     * Keep this "useless" method for cross compatibility with shopware 6.5.x
     *
     * @param Environment $twig
     * @return void
     */
    public function setTwig(Environment $twig): void
    {
        if (method_exists(parent::class, 'setTwig')) {
            parent::setTwig($twig);
        }
    }

    #[Route(path: '/gally/viewMore', name: 'frontend.gally.viewMore', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function viewMore(Request $request, SalesChannelContext $context): Response
    {
        $referer = $this->buildRefererRequest($request);
        $params = json_decode($request->getContent(), true);
        if (!\array_key_exists('aggregation', $params)) {
            throw new \InvalidArgumentException('"aggregation" parameter is required.');
        }
        $criteria = $this->criteriaBuilder->build($referer, $context);

        $field = preg_replace('/^' . CriteriaBuilder::GALLY_FILTER_PREFIX . '/', '', $params['aggregation']);
        $optionSearch = \is_string($params['optionSearch'] ?? null) && '' !== $params['optionSearch'] ? $params['optionSearch'] : null;
        $rawOptions = $this->adapter->viewMoreOption($context, $criteria, $field, $this->criteriaBuilder->getNavigationId(), $optionSearch);

        return $this->renderStorefront(
            '@GallyPlugin/storefront/component/listing/filter-panel-item.html.twig',
            [
                'aggregations' => $this->aggregationBuilder->build(
                    [
                        [
                            'field' => $field,
                            'label' => $field,
                            'type' => 'checkbox',
                            'count' => 1,
                            'options' => $rawOptions,
                            'hasMore' => false,
                        ],
                    ],
                    $context
                ),
                // The "view more"/option search endpoint always returns the full matching set (no further
                // pagination), so `hasMore` above is always false and the "view more" link correctly
                // disappears. But the search input itself (gated on `showOptionSearch` in
                // filter-multi-select.html.twig) must stay visible so the customer can keep refining or
                // clearing their search after the panel has been refreshed once.
                'showOptionSearch' => true,
            ]
        );
    }

    /**
     * Build product listing request from referer url in order to get matching criteria.
     */
    private function buildRefererRequest(Request $request): Request
    {
        $refererUrl = parse_url($request->headers->get('referer'));
        $refererUri = ($refererUrl['path'] ?? '') . '?' . ($refererUrl['query'] ?? '') . '#' . ($refererUrl['fragment'] ?? '');
        $server = $request->server->all();
        $server['REQUEST_URI'] = $refererUri;
        $server['QUERY_STRING'] = $refererUrl['query'] ?? '';
        $query = [];
        parse_str($refererUrl['query'] ?? '', $query);
        $request = $request->duplicate(null, $query, [], null, null, $server);

        $request = $this->transformer->transform($request);
        $pathInfo = explode('/', trim($request->getPathInfo(), '/'));
        if ('navigation' === $pathInfo[0]) {
            $request->attributes->set('navigationId', $pathInfo[1]);
        }

        return $request;
    }
}
