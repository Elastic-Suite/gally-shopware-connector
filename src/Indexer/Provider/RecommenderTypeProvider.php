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

namespace Gally\ShopwarePlugin\Indexer\Provider;

use Gally\ShopwarePlugin\RecommenderType\Entity\GallyRecommenderTypeEntity;
use Gally\Sdk\Entity\RecommenderType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Provide the recommender types managed in the Shopware admin (Catalogues > Gally > Recommendation types).
 */
class RecommenderTypeProvider implements ProviderInterface
{
    public function __construct(
        private EntityRepository $gallyRecommenderTypeRepository,
    ) {
    }

    /**
     * @return iterable<RecommenderType>
     */
    public function provide(Context $context): iterable
    {
        /** @var GallyRecommenderTypeEntity $recommenderType */
        foreach ($this->gallyRecommenderTypeRepository->search(new Criteria(), $context)->getEntities() as $recommenderType) {
            yield $this->buildRecommenderType($recommenderType);
        }
    }

    public function buildRecommenderType(GallyRecommenderTypeEntity $recommenderType): RecommenderType
    {
        return new RecommenderType($recommenderType->getLabel(), $recommenderType->getCode());
    }
}
