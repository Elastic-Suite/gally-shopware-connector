<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Controller;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Api\AuthenticationTokenProvider;
use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Synchronizer\AbstractSynchronizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Handle administration configuration button action.
 *
 * @Route(defaults={"_routeScope"={"api"}})
 */
class AdminController extends AbstractController
{
    /**
     * @param AuthenticationTokenProvider $authenticationTokenProvider
     * @param AbstractSynchronizer[] $synchronizers
     * @param AbstractIndexer[] $indexers
     */
    public function __construct(
        private AuthenticationTokenProvider $authenticationTokenProvider,
        private iterable $synchronizers,
        private iterable $indexers
    ) {
    }

    /**
     * @Route("/api/gally/test", name="api.gally.test", methods={"POST"})
     */
    public function testApi(Request $request): JsonResponse
    {
        $apiParams = json_decode($request->getContent(), true);
        $responseData = ['error' => false];
        try {
            $this->authenticationTokenProvider->getAuthenticationToken(
                $apiParams['baseUrl'],
                $apiParams['user'],
                $apiParams['password']
            );
            $responseData['message'] = "Connection to the api succeeded";
        } catch (ApiException $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getCode() == 401
                ? "Invalid credentials."
                : $exception->getMessage();
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    /**
     * @Route("/api/gally/synchronize", name="api.gally.synchronize", methods={"POST"})
     */
    public function synchronizeStructure(): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            foreach ($this->synchronizers as $synchronizer) {
                $synchronizer->synchronizeAll();
            }
            $responseData['message'] = "Syncing catalog structure with gally succeeded";
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }
        return new JsonResponse($responseData);
    }

    /**
     * @Route("/api/gally/index", name="api.gally.index", methods={"POST"})
     */
    public function index(): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            foreach ($this->indexers as $indexer) {
                $indexer->reindex();
            }
            $responseData['message'] = "Index catalog data to gally succeeded";
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }
}
