<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Api;

use Gally\Rest\ApiException;
use Gally\ShopwarePlugin\Service\Configuration;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Utils;

/**
 * Service which provide authentication token.
 */
class AuthenticationTokenProvider
{
    private Client $client;

    public function __construct() {
        $this->client = new Client();
    }

    public function getAuthenticationToken(string $baseUrl, string $user, string $password)
    {
        $resourcePath = '/authentication_token';
        $request = new Request(
            'POST',
            trim($baseUrl, '/') . $resourcePath,
            [
                'accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            Utils::jsonEncode(['email' => $user, 'password' => $password,])
        );

        try {
            $responseJson = $this->client->send($request);
        } catch (RequestException $e) {
            throw new ApiException(
                "[{$e->getCode()}] {$e->getMessage()}",
                $e->getCode(),
                $e->getResponse() ? $e->getResponse()->getHeaders() : null, // @phpstan-ignore-line
                $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            );
        }

        try {
            $response = Utils::jsonDecode($responseJson->getBody()->getContents(), true);
            return (string) $response['token'];
        } catch (\Exception $e) {
            throw new \LogicException("Unable to fetch authorization token from Api response.");
        }
    }
}
