<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Api;

use Gally\ShopwarePlugin\Service\Configuration;
use Psr\Log\LoggerInterface;

class Client
{
    private Authentication $authentication;
    private Configuration $configuration;
    private LoggerInterface $logger;
    private ?string $token = null;

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

    public function query($endpoint, $operation, ...$input)
    {
        $config = \Gally\Rest\Configuration::getDefaultConfiguration()
            ->setApiKey('Authorization', $this->getAuthorizationToken())
            ->setApiKeyPrefix('Authorization', 'Bearer')
            ->setHost(trim($this->configuration->getBaseUrl(), '/'));

        $apiInstance = new $endpoint(
            new \GuzzleHttp\Client(['verify' => false, 'headers' => ['Host' => 'gally.localhost']]),
            $config
        );

        try {
            if ($this->debug === true) {
                $this->logger->info("Calling {$endpoint}->{$operation} : ");
                $this->logger->info(print_r($input, true));
            }
            $result = $apiInstance->$operation(...$input);
            if ($this->debug === true) {
                $this->logger->info("Result of {$endpoint}->{$operation} : ");
                $this->logger->info(print_r($result, true));
            }
        } catch (\Exception $e) {
            $this->logger->info(get_class($e) . " when calling {$endpoint}->{$operation}: " . $e->getMessage());
            $this->logger->info($e->getTraceAsString());
            $this->logger->info("Input was");
            $this->logger->info(print_r($input, true));

            throw $e;
            $result = null;
        }

        return $result;
    }
}
