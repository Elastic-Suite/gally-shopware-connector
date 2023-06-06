<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Controller;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Api\AuthenticationTokenProvider;
use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Synchronizer\AbstractSynchronizer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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
    private EntityRepository $salesChannelRepository;
    private Configuration $configuration;
    private AuthenticationTokenProvider $authenticationTokenProvider;

    /** @var AbstractSynchronizer[] */
    private iterable $synchronizers;

    /** @var AbstractIndexer[]  */
    private iterable $indexers;

    public function __construct(
        EntityRepository $salesChannelRepository,
        Configuration $configuration,
        AuthenticationTokenProvider $authenticationTokenProvider,
        iterable $synchronizers,
        iterable $indexers
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->configuration = $configuration;
        $this->authenticationTokenProvider = $authenticationTokenProvider;
        $this->synchronizers = $synchronizers;
        $this->indexers = $indexers;
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
    public function synchronizeStructure(Request $request): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            $responseData['message'] = "No active sales channel found.";
            /** @var SalesChannelEntity $salesChannel */
            foreach ($this->getCurrentSalesChannels($request) as $salesChannel) {
                if ($this->configuration->isActive($salesChannel->getId())) {
                    foreach ($this->synchronizers as $synchronizer) {
                        $synchronizer->synchronizeAll($salesChannel);
                    }
                    $responseData['message'] = "Syncing catalog structure with gally succeeded";
                }
            }
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }
        return new JsonResponse($responseData);
    }

    /**
     * @Route("/api/gally/index", name="api.gally.index", methods={"POST"})
     */
    public function index(Request $request): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            $responseData['message'] = "No active sales channel found.";
            /** @var SalesChannelEntity $salesChannel */
            foreach ($this->getCurrentSalesChannels($request) as $salesChannel) {
                if ($this->configuration->isActive($salesChannel->getId())) {
                    foreach ($this->indexers as $indexer) {
                        $indexer->reindex($salesChannel);
                    }
                    $responseData['message'] = "Index catalog data to gally succeeded";
                }
            }
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    /**
     * Get current sales channel from request, if the value is set to "All sales channels" return all sales channels.
     *
     * @param Request $request
     * @return EntitySearchResult
     */
    private function getCurrentSalesChannels(Request $request)
    {
        $apiParams = json_decode($request->getContent(), true);
        if (!array_key_exists('salesChannelId', $apiParams)) {
            throw new \InvalidArgumentException('Missing sales channel id');
        }

        $salesChannelId = $apiParams['salesChannelId'];
        $criteria = $salesChannelId ? new Criteria([$salesChannelId]) : new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency', 'domains']);

        return $this->salesChannelRepository->search($criteria, Context::createDefaultContext());
    }
}
