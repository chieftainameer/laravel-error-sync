<?php
// src/Services/RelayClient.php

namespace NativePHP\ErrorSync\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;

class RelayClient
{
    protected Client $client;

    public function __construct(
        protected string $relayUrl,
        protected int $timeout = 3,
    ) {
        $this->client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => 2,
            'http_errors' => false,
        ]);
    }

    public function send(array $payload): bool
    {
        try {
            $response = $this->client->post($this->relayUrl . '/error', [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Error-Sync' => 'nativephp',
                    'X-App-Name' => config('app.name', 'Unknown'),
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (ConnectException $e) {
            // Server unreachable - that's okay in dev
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function ping(): bool
    {
        try {
            $response = $this->client->get($this->relayUrl . '/ping');
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getUrl(): string
    {
        return $this->relayUrl;
    }
}