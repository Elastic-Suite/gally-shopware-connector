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

namespace Gally\ShopwarePlugin\Api;

use Gally\ShopwarePlugin\Service\Configuration;
use Psr\Log\LoggerInterface;

/**
 * Abstract api client that manage authentication process.
 */
class AbstractClient
{
    protected string $kernelEnv;
    protected ?string $token = null;
    protected bool $debug;

    public function __construct(
        protected AuthenticationTokenProvider $tokenProvider,
        protected Configuration $configuration,
        protected LoggerInterface $logger,
        string $kernelEnv
    ) {
        $this->kernelEnv = $kernelEnv;
        $this->debug = false;
    }

    public function getAuthorizationToken(): string
    {
        if (null === $this->token) {
            $this->token = $this->tokenProvider->getAuthenticationToken(
                $this->configuration->getBaseUrl(),
                $this->configuration->getUser(),
                $this->configuration->getPassword()
            );
        }

        return $this->token;
    }
}
