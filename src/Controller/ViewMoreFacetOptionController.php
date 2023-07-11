<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Controller;

use Gally\ShopwarePlugin\Search\Adapter;
use Gally\ShopwarePlugin\Search\CriteriaBuilder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller used to fetch more option of a filter.
 *
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class ViewMoreFacetOptionController extends AbstractController
{
    public function __construct(
        private RequestTransformer $transformer,
        private CriteriaBuilder $criteriaBuilder,
        private Adapter $adapter
    ) {
    }

    /**
     * @Route("/gally/viewMore", name="frontend.gally.viewMore", methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function viewMore(Request $request, SalesChannelContext $context): JsonResponse
    {
        $referer = $this->buildRefererRequest($request);
        $params = json_decode($request->getContent(), true);
        if (!array_key_exists('aggregation', $params)) {
            throw new \InvalidArgumentException('"aggregation" parameter is required.');
        }
        $criteria = $this->criteriaBuilder->build($referer, $context);

        return new JsonResponse($this->adapter->viewMoreOption($context, $criteria, $params['aggregation']));
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
        $request = $request->duplicate(null, null, [], null, null, $server);

        $request = $this->transformer->transform($request);
        $pathInfo = explode('/', trim($request->getPathInfo(), '/'));
        if ($pathInfo[0] === 'navigation') {
            $request->attributes->set('navigationId', $pathInfo[1]);
        }
        return $request;
    }
}
