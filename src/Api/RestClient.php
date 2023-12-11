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

use Gally\Rest\Configuration;
use GuzzleHttp\Client;

/**
 * Rest client used to call gally api on synchronization and indexing process.
 */
class RestClient extends AbstractClient
{
    public function query($endpoint, $operation, ...$input)
    {
        $config = Configuration::getDefaultConfiguration()
            ->setApiKey('Authorization', $this->getAuthorizationToken())
            ->setApiKeyPrefix('Authorization', 'Bearer')
            ->setHost($this->configuration->getBaseUrl());

        $apiInstance = new $endpoint(new Client('prod' !== $this->kernelEnv ? ['verify' => false] : []), $config);

        try {
            if (true === $this->debug) {
                $this->logger->info("Calling {$endpoint}->{$operation} : ");
                $this->logger->info(print_r($input, true));
            }
            $result = $apiInstance->{$operation}(...$input);
            if (true === $this->debug) {
                $this->logger->info("Result of {$endpoint}->{$operation} : ");
                $this->logger->info(print_r($result, true));
            }
        } catch (\Exception $e) {
            $this->logger->info($e::class . " when calling {$endpoint}->{$operation}: " . $e->getMessage());
            $this->logger->info($e->getTraceAsString());
            $this->logger->info('Input was');
            $this->logger->info(print_r($input, true));

            throw $e;
        }

        return $result;
    }
}
