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

use Gally\ShopwarePlugin\Service\RecommendationHelper;
use Gally\ShopwarePlugin\Service\TrackingProxyService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * GraphQL proxy endpoint for Gally tracking: forwards whitelisted GraphQL mutations from the
 * storefront JS SDK to the Gally API, so credentials never need to be exposed client-side.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class TrackingController
{
    public function __construct(
        private readonly TrackingProxyService $trackingProxyService,
        private readonly RecommendationHelper $recommendationHelper,
    ) {
    }

    #[Route(path: '/gally/graphql', name: 'frontend.gally.tracking.graphql', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function graphqlProxy(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true);

            if (null === $payload || !\is_array($payload)) {
                return new JsonResponse([
                    'errors' => [
                        ['message' => 'Invalid JSON payload'],
                    ],
                ], Response::HTTP_BAD_REQUEST);
            }

            $response = $this->trackingProxyService->forwardGraphQLRequest($payload);

            return new JsonResponse($response);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'errors' => [
                    ['message' => $e->getMessage()],
                ],
            ], Response::HTTP_FORBIDDEN);
        } catch (\Exception $e) {
            return new JsonResponse([
                'errors' => [
                    ['message' => $e->getMessage()],
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resolves a Shopware product id (as read from any add-to-cart form's native
     * lineItems[<id>][id] field) to the parent/own product numbers Gally tracking needs, so the
     * storefront JS doesn't have to know which page/widget triggered the add-to-cart.
     */
    #[Route(path: '/gally/product/skus', name: 'frontend.gally.tracking.product.skus', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function productSkus(Request $request, SalesChannelContext $context): JsonResponse
    {
        $productId = json_decode($request->getContent(), true)['productId'] ?? null;
        if (!\is_string($productId) || '' === $productId) {
            return new JsonResponse(['error' => true], Response::HTTP_BAD_REQUEST);
        }

        $skus = $this->recommendationHelper->getProductSkusById($productId, $context->getContext());
        if (null === $skus) {
            return new JsonResponse(['error' => true], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['error' => false] + $skus);
    }
}
