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

use Gally\Sdk\Client\Client;
use Gally\Sdk\Client\Configuration;
use Gally\Sdk\Entity\RecommenderType;
use Gally\Sdk\Repository\RecommenderTypeRepository;
use Gally\Sdk\Service\BundleManager;
use Gally\Sdk\Service\StructureSynchonizer;
use Gally\ShopwarePlugin\Config\CacheManager;
use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Indexer\Provider\ProviderInterface;
use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeEntity;
use Gally\ShopwarePlugin\Service\RecommenderTypeCatalog;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Handle administration configuration button action.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminController extends AbstractController
{
    /** @var ProviderInterface[] */
    private array $providers;
    private array $syncMethod = [
        'catalog' => 'syncAllLocalizedCatalogs',
        'sourceField' => 'syncAllSourceFields',
        'sourceFieldOption' => 'syncAllSourceFieldOptions',
    ];

    /**
     * @param AbstractIndexer[] $indexers
     */
    public function __construct(
        private StructureSynchonizer $synchonizer,
        private RecommenderTypeCatalog $recommenderTypeCatalog,
        private EntityRepository $gallyRecommenderTypeRepository,
        \IteratorAggregate $providers,
        private iterable $indexers,
        private CacheManager $cacheManager,
    ) {
        $this->providers = iterator_to_array($providers);
    }

    #[Route(path: '/api/gally/test', name: 'api.gally.test', methods: ['POST'])]
    public function testApi(Request $request): JsonResponse
    {
        $apiParams = json_decode($request->getContent(), true);
        $responseData = ['error' => false];

        $configuration = new Configuration(
            $apiParams['baseUrl'],
            $apiParams['check_ssl'],
            $apiParams['user'],
            $apiParams['password']
        );
        $client = new Client($configuration);

        try {
            $client->get('indices');
            $responseData['messageKey'] = 'connectionSucceeded';
        } catch (\RuntimeException $exception) {
            $responseData['error'] = true;
            if (401 == $exception->getCode()) {
                $responseData['messageKey'] = 'invalidCredentials';
            } else {
                $responseData['message'] = $exception->getMessage();
            }
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    #[Route(path: '/api/gally/synchronize', name: 'api.gally.synchronize', methods: ['POST'])]
    public function synchronizeStructure(Context $context): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            foreach ($this->syncMethod as $entity => $method) {
                $this->synchonizer->{$method}($this->providers[$entity]->provide($context));
            }
            $responseData['messageKey'] = 'syncSucceeded';
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    #[Route(path: '/api/gally/index', name: 'api.gally.index', methods: ['POST'])]
    public function index(Context $context): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            foreach ($this->indexers as $indexer) {
                $indexer->reindex($context);
            }
            $responseData['messageKey'] = 'indexSucceeded';
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    /**
     * Clear everything the SDK caches locally (auth token, active bundles list, recommender
     * types list from Gally): lets the admin force a refresh instead of waiting out each cache's
     * own TTL, e.g. to see a recommender type just created in Gally.
     */
    #[Route(path: '/api/gally/clear-cache', name: 'api.gally.clear_cache', methods: ['POST'])]
    public function clearCache(): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            $this->cacheManager->clearCache(Client::API_TOKEN_CACHE_KEY);
            $this->cacheManager->clearCache(BundleManager::BUNDLES_CACHE_KEY);
            $this->cacheManager->clearCache(RecommenderTypeRepository::RECOMMENDER_TYPES_CACHE_KEY);
            $responseData['messageKey'] = 'cacheCleared';
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    /**
     * List the recommender types configured directly in Gally, so the admin can attach one to a
     * native cross-selling group (no local copy of this list is kept in Shopware).
     */
    #[Route(path: '/api/gally/recommender-types', name: 'api.gally.recommender_types', methods: ['POST'])]
    public function recommenderTypes(): JsonResponse
    {
        $responseData = ['error' => false];
        try {
            $responseData['recommenderTypes'] = array_map(
                static fn (RecommenderType $recommenderType): array => [
                    'code' => $recommenderType->getCode(),
                    'name' => $recommenderType->getName(),
                ],
                $this->recommenderTypeCatalog->findAll()
            );
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }

    /**
     * Find (or create) the local row mapping to the given Gally recommender type code, so it can
     * be used as the FK value on a native cross-selling group. See ProductCrossSellingExtension
     * for why a local row is needed at all.
     */
    #[Route(path: '/api/gally/recommender-types/resolve', name: 'api.gally.recommender_types.resolve', methods: ['POST'])]
    public function resolveRecommenderType(Request $request, Context $context): JsonResponse
    {
        $code = json_decode($request->getContent(), true)['code'] ?? null;
        if (!\is_string($code) || '' === $code) {
            return new JsonResponse(['error' => true, 'messageKey' => 'codeRequired']);
        }

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $code));
            /** @var GallyRecommenderTypeEntity|null $recommenderType */
            $recommenderType = $this->gallyRecommenderTypeRepository->search($criteria, $context)->getEntities()->first();

            if (null === $recommenderType) {
                $id = Uuid::randomHex();
                try {
                    $this->gallyRecommenderTypeRepository->create([['id' => $id, 'code' => $code]], $context);
                } catch (\Exception $exception) {
                    // Another admin resolved the same new code concurrently: reuse their row.
                    /** @var GallyRecommenderTypeEntity|null $recommenderType */
                    $recommenderType = $this->gallyRecommenderTypeRepository->search($criteria, $context)->getEntities()->first();
                    if (null === $recommenderType) {
                        throw $exception;
                    }
                    $id = $recommenderType->getId();
                }
            } else {
                $id = $recommenderType->getId();
            }
        } catch (\Exception $exception) {
            return new JsonResponse(['error' => true, 'message' => $exception->getMessage()]);
        }

        return new JsonResponse(['error' => false, 'id' => $id]);
    }
}
