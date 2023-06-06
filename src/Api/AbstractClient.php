<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Api;

use Gally\ShopwarePlugin\Service\Configuration;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Abstract api client that manage authentication process.
 */
class AbstractClient
{
    protected AuthenticationTokenProvider $tokenProvider;
    protected Configuration $configuration;
    protected LoggerInterface $logger;
    protected ?string $token = null;
    protected bool $debug;

    public function __construct(
        AuthenticationTokenProvider $tokenProvider,
        Configuration $configuration,
        LoggerInterface $logger
    ){
        $this->tokenProvider = $tokenProvider;
        $this->configuration = $configuration;
        $this->logger        = $logger;
        $this->debug         = false;
    }

    public function getAuthorizationToken(SalesChannelEntity $salesChannel): string
    {
        if (null === $this->token) {
            $this->token = $this->tokenProvider->getAuthenticationToken(
                $this->configuration->getBaseUrl($salesChannel->getId()),
                $this->configuration->getUser($salesChannel->getId()),
                $this->configuration->getPassword($salesChannel->getId())
            );
        }

        return $this->token;
    }
}
