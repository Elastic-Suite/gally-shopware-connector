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

/**
 * Controller used to fetch more option of a filter.
 *
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class ViewMoreFacetOptionController extends StorefrontController
{
    public function __construct(
        private RequestTransformer $transformer,
        private CriteriaBuilder $criteriaBuilder,
        private AggregationBuilder $aggregationBuilder,
        private Adapter $adapter
    ) {
    }

    /**
     * @Route("/gally/viewMore", name="frontend.gally.viewMore", methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function viewMore(Request $request, SalesChannelContext $context): Response
    {
        $referer = $this->buildRefererRequest($request);
        $params = json_decode($request->getContent(), true);
        if (!\array_key_exists('aggregation', $params)) {
            throw new \InvalidArgumentException('"aggregation" parameter is required.');
        }
        $criteria = $this->criteriaBuilder->build($referer, $context);

        $field = preg_replace('/^' . CriteriaBuilder::GALLY_FILTER_PREFIX . '/', '', $params['aggregation']);
        $rawOptions = $this->adapter->viewMoreOption($context, $criteria, $field);

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
