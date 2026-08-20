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

namespace Gally\ShopwarePlugin\Service;

use Gally\Sdk\Client\Client;
use Gally\Sdk\Client\Configuration;
use Gally\Sdk\Entity\RecommenderType;
use Gally\Sdk\Repository\RecommenderTypeRepository;
use Gally\Sdk\Service\Cache\CacheManagerInterface;

/**
 * Reads the recommender types configured directly in Gally, so the admin can pick one when
 * enabling Gally on a native cross-selling group. Shopware does not keep its own copy of this
 * list: recommender types are managed in Gally's own admin.
 */
class RecommenderTypeCatalog
{
    private RecommenderTypeRepository $repository;

    public function __construct(Configuration $configuration, ?CacheManagerInterface $cacheManager = null)
    {
        $this->repository = new RecommenderTypeRepository(new Client($configuration, $cacheManager));
    }

    /**
     * @return RecommenderType[]
     */
    public function findAll(): array
    {
        return array_values($this->repository->findAll());
    }
}
