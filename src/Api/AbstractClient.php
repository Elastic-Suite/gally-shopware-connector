<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Api;

use Gally\ShopwarePlugin\Service\Configuration;
use Psr\Log\LoggerInterface;

class AbstractClient
{
    protected Authentication $authentication;
    protected Configuration $configuration;
    protected LoggerInterface $logger;
    protected ?string $token = null;

    public function __construct(
        Authentication $authentication,
        Configuration $configuration,
        LoggerInterface $logger
    ){
        $this->authentication = $authentication;
        $this->configuration  = $configuration;
        $this->logger         = $logger;
        $this->debug          = false;
    }

    public function getAuthorizationToken(): string
    {
        if (null === $this->token) {
            $this->token = $this->authentication->getAuthenticationToken();
        }

        return $this->token;
    }
}
