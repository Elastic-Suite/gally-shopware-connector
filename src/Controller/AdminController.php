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
use Gally\Sdk\Service\StructureSynchonizer;
use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Indexer\Provider\ProviderInterface;
use Shopware\Core\Framework\Context;
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
        \IteratorAggregate $providers,
        private iterable $indexers,
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
            $responseData['message'] = 'Connection to the api succeeded';
        } catch (\RuntimeException $exception) {
            $responseData['error'] = true;
            $responseData['message'] = 401 == $exception->getCode()
                ? 'Invalid credentials.'
                : $exception->getMessage();
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
            $responseData['message'] = 'Syncing catalog structure with gally succeeded';
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
            $responseData['message'] = 'Index catalog data to gally succeeded';
        } catch (\Exception $exception) {
            $responseData['error'] = true;
            $responseData['message'] = $exception->getMessage();
        }

        return new JsonResponse($responseData);
    }
}
