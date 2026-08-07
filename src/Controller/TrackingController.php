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

use Gally\ShopwarePlugin\Service\TrackingProxyService;
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
}
