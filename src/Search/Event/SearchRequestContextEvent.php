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

namespace Gally\ShopwarePlugin\Search\Event;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Allows a custom project to inject context into a Gally search request before it is sent. priceGroupId defaults
 * to the sales channel's customer group id, override it when a project needs a different Gally/Shopware mapping.
 */
class SearchRequestContextEvent extends Event
{
    public const NAME = 'gally.search.request_context';

    public function __construct(
        private readonly SalesChannelContext $context,
        private readonly Criteria $criteria,
        private ?string $priceGroupId = null,
    ) {
    }

    public function getContext(): SalesChannelContext
    {
        return $this->context;
    }

    public function getCriteria(): Criteria
    {
        return $this->criteria;
    }

    public function getPriceGroupId(): ?string
    {
        return $this->priceGroupId;
    }

    public function setPriceGroupId(?string $priceGroupId): void
    {
        $this->priceGroupId = $priceGroupId;
    }
}
