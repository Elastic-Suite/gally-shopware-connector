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

use Gally\ShopwarePlugin\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TrackingProxyService
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Process tracking event from user payload and send to Gally API.
     * Security: Rebuilds the GraphQL mutation from scratch using only whitelisted data.
     *
     * @param array $payload User payload containing tracking event data
     *
     * @throws \Exception
     *
     * @return array GraphQL response from Gally
     */
    public function forwardGraphQLRequest(array $payload): array
    {
        $graphqlUrl = null;

        try {
            $trackingEvents = $this->extractTrackingEvents($payload);

            if ([] === $trackingEvents) {
                throw new \InvalidArgumentException('No valid tracking events found in payload');
            }

            $graphqlUrl = rtrim($this->configManager->getBaseUrl(), '/') . '/graphql';

            $safePayload = $this->buildTrackingMutation($trackingEvents);

            $response = $this->httpClient->request('POST', $graphqlUrl, [
                'json' => $safePayload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'verify_peer' => $this->configManager->checkSSL(),
                'verify_host' => $this->configManager->checkSSL(),
            ]);

            $responseData = $response->toArray(false);

            if (isset($responseData['errors'])) {
                $this->logger->error('Gally GraphQL response contains errors', [
                    'url' => $graphqlUrl,
                    'status_code' => $response->getStatusCode(),
                    'errors' => $responseData['errors'],
                    'events' => $trackingEvents,
                ]);
            }

            return $responseData;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send tracking events to Gally', [
                'error' => $e->getMessage(),
                'url' => $graphqlUrl ?? 'N/A',
            ]);

            throw $e;
        }
    }

    /**
     * Extract and validate tracking events from user payload.
     * Whitelist approach: only extract known safe fields.
     *
     * @param array $payload User payload
     *
     * @throws \InvalidArgumentException
     *
     * @return array Array of validated tracking events
     */
    private function extractTrackingEvents(array $payload): array
    {
        if (!isset($payload['variables']) || !\is_array($payload['variables'])) {
            throw new \InvalidArgumentException('Missing or invalid variables in payload');
        }

        $trackingEvents = [];

        foreach ($payload['variables'] as $key => $value) {
            if (1 === preg_match('/^input\\d+$/', (string) $key) && \is_array($value)) {
                $trackingEvents[] = $this->validateTrackingEventData($value);
            }
        }

        // Security: Limit the number of events to prevent DoS
        $maxEvents = 100;
        if (\count($trackingEvents) > $maxEvents) {
            throw new \InvalidArgumentException(\sprintf('Too many tracking events. Maximum %d events allowed per request.', $maxEvents));
        }

        return $trackingEvents;
    }

    /**
     * Validate and sanitize a single tracking event.
     * Accepts all scalar fields but rejects complex types to prevent injection.
     *
     * @throws \InvalidArgumentException
     *
     * @return array Validated event data
     */
    private function validateTrackingEventData(array $eventData): array
    {
        $requiredFields = ['eventType', 'metadataCode', 'localizedCatalogCode'];
        foreach ($requiredFields as $field) {
            if (!isset($eventData[$field]) || !\is_string($eventData[$field])) {
                throw new \InvalidArgumentException("Missing or invalid required field: {$field}");
            }
        }

        $cleanEvent = [];

        foreach ($eventData as $field => $value) {
            if (\is_scalar($value) || null === $value) {
                $cleanEvent[$field] = $value;
            } elseif (\is_array($value) || \is_object($value)) {
                $this->logger->warning('Rejected non-scalar field in tracking event', [
                    'field' => $field,
                    'type' => \gettype($value),
                ]);
            }
        }

        return $cleanEvent;
    }

    /**
     * Build a clean GraphQL mutation for tracking events.
     *
     * @return array GraphQL payload with query and variables
     */
    private function buildTrackingMutation(array $trackingEvents): array
    {
        $mutations = [];
        $variables = [];

        foreach ($trackingEvents as $index => $event) {
            $varName = "input{$index}";
            $mutations[] = "event{$index}: createTrackingEvent(input: \${$varName}) { trackingEvent { id } }";
            $variables[$varName] = $event;
        }

        $mutationQuery = 'mutation createTrackingEvents(' . implode(', ', array_map(
            static fn ($i) => "\$input{$i}: createTrackingEventInput!",
            array_keys($trackingEvents)
        )) . ') { ' . implode(' ', $mutations) . ' }';

        return [
            'query' => $mutationQuery,
            'variables' => $variables,
        ];
    }
}
