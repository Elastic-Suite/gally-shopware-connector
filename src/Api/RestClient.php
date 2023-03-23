<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Api;

use GuzzleHttp\Client;

class RestClient extends AbstractClient
{
    public function query($endpoint, $operation, ...$input)
    {
        $config = \Gally\Rest\Configuration::getDefaultConfiguration()
            ->setApiKey('Authorization', $this->getAuthorizationToken())
            ->setApiKeyPrefix('Authorization', 'Bearer')
            ->setHost($this->configuration->getBaseUrl());

        // TODO remove host header
        $apiInstance = new $endpoint(
            new Client(['verify' => false, 'headers' => ['Host' => 'gally.localhost']]),
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
        }

        return $result;
    }
}
